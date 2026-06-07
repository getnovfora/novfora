<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Backup;

use App\Install\Installer;
use App\Support\Audit;
use App\Upgrade\SchemaState;
use App\Upgrade\UpgradeRunner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The no-SSH restore orchestrator (RH-11). Wraps {@see RestoreService} in the same backup-first,
 * maintenance-safe choreography the RH-10 {@see UpgradeRunner} gives the auto-upgrade, so the
 * Admin → System → Backups panel finally has a recovery path that does not require a shell. CLI and panel
 * share THIS one path.
 *
 * THE RUN (strict order, kickoff §3), under a FILE lock so it can never double-run:
 *   1. validate the archive (manifest + dump SHA-256 + restorable into THIS database engine) — a corrupt,
 *      foreign, or cross-engine archive is REFUSED before anything is touched (and, since nothing was
 *      touched, the maintenance gate lifts again);
 *   2. take a pre-restore SAFETY snapshot of the CURRENT state (best-effort) so the destructive restore is
 *      itself reversible;
 *   3. restore the database + storage (from a private temp copy of the target);
 *   4. flush caches + refresh {@see SchemaState} — a restored DB may carry an OLDER schema, so the RH-10
 *      tick must then see pending migrations and upgrade cleanly (the documented hand-off);
 *   5. record success + audit-log (who, which backup, duration, result).
 *
 * WHY CRON-DRIVEN, NOT SYNCHRONOUS-IN-THE-REQUEST OR A DB QUEUE JOB. A restore overwrites the live DB —
 * and on the baseline tier the cache, session, AND queue all live in that DB. So a synchronous web restore
 * would wipe the very session/cache backing the request mid-flight (and is bounded by the request-time
 * limit on large archives), and a DB-queue job would erase its own `jobs` row mid-restore. Instead the
 * panel records the request in {@see RestoreState} (a file, store-independent) and the single cron line
 * drains it via {@see runPending()} — in CLI context, with no web timeout. {@see runNow()} is the
 * synchronous path for an operator who already has a shell. Both reach {@see execute()}.
 *
 * SINGLE-ATTEMPT, FAIL-SAFE. A restore is destructive, so it is NOT auto-retried. A validation failure
 * (nothing touched) refuses and lifts the gate. A failure during the restore step — or a process killed
 * mid-restore, detected on the next cron tick because the file lock is free yet {@see RestoreState} still
 * says `running` — HOLDS the site in maintenance (`stuck`) rather than serving a possibly half-restored DB.
 * Recovery: re-restore from the panel once reachable, or (no SSH) delete the restore-state file via the host
 * file manager, then restore a known-good backup / the named pre-restore safety snapshot.
 *
 * (Not `final` so it can be swapped for a test double where a caller is unit-tested in isolation.)
 */
class RestoreRunner
{
    public function __construct(
        private readonly RestoreService $restore,
        private readonly RestoreState $state,
        private readonly BackupService $backups,
        private readonly SchemaState $schema,
        private readonly Installer $installer,
    ) {}

    /**
     * Panel path: record an operator-requested restore. Cheaply REFUSES a missing / foreign / cross-engine
     * archive before anything is touched (manifest + format + engine check — NOT the full dump hash, which is
     * deferred to the cron run so the web request stays within its time budget; the cron re-validates,
     * including the hash, before touching the DB). The gate engages on the next request (RestoreState is
     * file-based). Throws {@see BackupException} on an unusable archive.
     */
    public function request(string $archiveName, ?int $actorId, ?string $actorName): void
    {
        $path = $this->resolveArchive($archiveName);
        if ($path === null) {
            throw new BackupException('That backup could not be found.');
        }

        $info = $this->restore->inspect($path);             // cheap: manifest present + format (throws if not)
        $this->restore->assertRestorable($info['db_driver']); // cheap: refuse a cross-engine archive up front

        $this->state->request(basename($path), $actorId, $actorName);
    }

    /** Cron path: perform a pending panel-requested restore, if any. A cheap no-op the rest of the time. */
    public function runPending(): RestoreResult
    {
        if (! $this->installer->isInstalled()) {
            return RestoreResult::skipped('not-installed');
        }
        if ($this->state->isStuck()) {
            return RestoreResult::skipped('stuck'); // held for the operator — no auto-retry
        }

        $req = $this->state->pendingRequest();
        if ($req === null) {
            return RestoreResult::skipped('nothing-pending');
        }

        return $this->locked(function () use ($req) {
            // We hold the file lock, so no run is live. If RestoreState still says `running`, a previous run
            // was killed mid-restore — the DB may be half-applied. Do NOT auto-re-run a destructive op:
            // HOLD for the operator (stuck) rather than resume blindly.
            if ($this->state->isRunning()) {
                return $this->holdInterrupted($req);
            }

            return $this->executeRequested($req);
        });
    }

    /**
     * CLI path (operator has a shell): restore synchronously through the SAME choreography as the panel.
     * Deliberate, so it proceeds even if a prior run was interrupted (a stale `running`/`stuck`) — re-applying
     * the chosen archive over a possibly half-restored DB is exactly the recovery — and a clean run clears the
     * hold. The file lock still prevents it from colliding with a live cron run.
     */
    public function runNow(string $archivePath): RestoreResult
    {
        if (! is_file($archivePath)) {
            return RestoreResult::failed('validate', 'Backup archive not found: '.$archivePath, basename($archivePath));
        }

        return $this->locked(fn () => $this->execute($archivePath, auth()->id(), auth()->user()?->name, 'cli'));
    }

    /** @param array{archive:string, actor_id:?int, actor_name:?string, requested_at:int} $req */
    private function executeRequested(array $req): RestoreResult
    {
        $path = $this->resolveArchive($req['archive']);
        if ($path === null) {
            // The requested archive vanished between request and run (deleted/pruned). Nothing was touched.
            $this->state->recordFailure('validate', 'The requested backup no longer exists.', $req['archive'], stuck: false, safetyBackup: null);

            return RestoreResult::failed('validate', 'The requested backup no longer exists.', $req['archive']);
        }

        return $this->execute($path, $req['actor_id'] ?? null, $req['actor_name'] ?? null, 'panel');
    }

    /** A run killed mid-restore (file lock free, state still `running`): hold for the operator. */
    private function holdInterrupted(array $req): RestoreResult
    {
        $name = is_string($req['archive'] ?? null) ? $req['archive'] : null;
        $this->state->recordFailure(
            'restore',
            'A previous restore was interrupted (the process was killed mid-restore).',
            $name,
            stuck: true,
            safetyBackup: $this->state->lastSafetyBackup(),
        );
        $this->audit('restore.failed', (string) $name, 'panel', [
            'stage' => 'restore',
            'interrupted' => true,
            'actor_id' => $req['actor_id'] ?? null,
            'actor_name' => $req['actor_name'] ?? null,
        ]);

        return RestoreResult::failed('restore', 'A previous restore was interrupted.', $name);
    }

    private function execute(string $archivePath, ?int $actorId, ?string $actorName, string $source): RestoreResult
    {
        $started = microtime(true);
        $name = basename($archivePath);

        $this->state->beginRun(); // running=true → the gate stays up across the DB swap

        // Restore from a PRIVATE temp copy of the target, not the archive in the backup dir, so that nothing
        // in that dir — the pre-restore safety snapshot we are about to write, a concurrent prune, an
        // operator deleting the file — can change the bytes we restore. Removed in `finally`.
        $src = null;
        try {
            $src = $this->stageTarget($archivePath);
        } catch (Throwable $e) {
            // Nothing was touched → record a validation failure (clears running + request, lifts the gate).
            $this->state->recordFailure('validate', $e->getMessage(), $name, stuck: false, safetyBackup: null);
            $this->audit('restore.failed', $name, $source, ['stage' => 'validate', 'error' => $this->trim($e), 'actor_id' => $actorId, 'actor_name' => $actorName]);
            report($e);

            return RestoreResult::failed('validate', $e->getMessage(), $name);
        }

        try {
            // (1) validate — manifest + dump SHA-256 + restorable engine. Nothing has been overwritten, so on
            //     failure we record a validation failure (clears running + request) and the gate lifts.
            try {
                $this->restore->validate($src); // throws on corrupt/foreign/cross-engine
            } catch (Throwable $e) {
                $this->state->recordFailure('validate', $e->getMessage(), $name, stuck: false, safetyBackup: null);
                $this->audit('restore.failed', $name, $source, ['stage' => 'validate', 'error' => $this->trim($e), 'actor_id' => $actorId, 'actor_name' => $actorName]);
                report($e);

                return RestoreResult::failed('validate', $e->getMessage(), $name);
            }

            // (2) pre-restore safety snapshot of the CURRENT state (best-effort) — makes the restore
            //     reversible. keep=0 so it can never prune another archive.
            $safety = null;
            if ((bool) config('hearth.backup.pre_restore_safety', true)) {
                try {
                    $safety = $this->backups->create(0)->name();
                } catch (Throwable $e) {
                    report($e); // the operator chose to overwrite; a failed safety snapshot must not abort
                }
            }

            // (3) restore DB + files from the staged copy. A failure here may leave a half-restored DB → HOLD.
            try {
                $report = $this->restore->restore($src);
            } catch (Throwable $e) {
                return $this->fail($e, $name, $safety, $source, $actorId, $actorName);
            }

            // (4) the restored DB may carry an OLDER schema than the deployed code. The DB-backed cache was
            //     just overwritten too, so flush it and re-derive the schema state from the restored DB —
            //     that is what lets the RH-10 maintenance gate + auto-upgrade tick notice "schema behind
            //     code" and migrate.
            $this->afterRestoreRefresh();

            $durationMs = (int) round((microtime(true) - $started) * 1000);

            // (5) success: clear the request + lift the RESTORE gate. If the restored schema is now behind the
            //     deployed code (auto mode), the RH-10 SchemaState gate keeps the site in maintenance and the
            //     auto-upgrade tick takes over — the intended hand-off.
            $this->state->recordSuccess($name, $durationMs, $safety, $report['db_driver'], $actorName);
            $this->audit('restore.completed', $name, $source, [
                'duration_ms' => $durationMs,
                'db_driver' => $report['db_driver'],
                'storage_restored' => $report['storage_restored'],
                'safety_backup' => $safety,
                'actor_id' => $actorId,
                'actor_name' => $actorName,
            ]);

            return RestoreResult::success($name, $durationMs, $safety, $report['db_driver']);
        } finally {
            // $src is a string here (the catch above returns on a staging failure), so a null check is dead;
            // is_file() is the meaningful guard before unlinking the private temp copy.
            if (is_file($src)) {
                @unlink($src);
            }
        }
    }

    /**
     * A failure DURING the restore step (post-validation): the DB may be half-applied. HOLD the site in
     * maintenance (stuck) — never auto-retry a destructive op — and name the pre-restore safety snapshot for
     * recovery. recordFailure clears running + the request; the gate stays up via `stuck`.
     */
    private function fail(Throwable $e, string $name, ?string $safety, string $source, ?int $actorId, ?string $actorName): RestoreResult
    {
        $this->state->recordFailure('restore', $e->getMessage(), $name, stuck: true, safetyBackup: $safety);
        $this->audit('restore.failed', $name, $source, [
            'stage' => 'restore',
            'error' => $this->trim($e),
            'safety_backup' => $safety,
            'actor_id' => $actorId,
            'actor_name' => $actorName,
        ]);
        report($e);

        return RestoreResult::failed('restore', $e->getMessage(), $name, $safety);
    }

    /** Re-derive cache + schema state from the freshly-restored DB (see step 4 above). */
    private function afterRestoreRefresh(): void
    {
        try {
            Artisan::call('cache:clear');
        } catch (Throwable $e) {
            report($e); // best effort — short-TTL fragments self-heal
        }
        foreach (['forum.index.tree', 'hearth.sitemap'] as $key) {
            try {
                Cache::forget($key);
            } catch (Throwable) {
                // best effort
            }
        }
        try {
            $this->schema->forget();
            $this->schema->refresh(); // re-check pending migrations against the RESTORED DB
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Run the closure under an exclusive FILE lock. A cache lock would be unreliable here — the restore
     * wipes the DB-backed cache mid-run — so the lock is an flock on a file outside storage/app. If the lock
     * cannot be created at all (storage not writable → the restore would fail anyway) we proceed without it
     * rather than wedge; the scheduler's withoutOverlapping is the belt.
     */
    private function locked(callable $fn): RestoreResult
    {
        $lockPath = (string) config('hearth.backup.restore_lock_path', storage_path('hearth-restore.lock'));
        $dir = \dirname($lockPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $handle = @fopen($lockPath, 'c');
        if ($handle === false) {
            return $fn(); // can't lock; the withoutOverlapping mutex remains the belt
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return RestoreResult::skipped('locked');
        }

        try {
            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Copy the target archive to a private temp file so the restore reads bytes nothing else can change. */
    private function stageTarget(string $archivePath): string
    {
        if (! is_file($archivePath)) {
            throw new BackupException('Backup archive not found: '.$archivePath);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'hearth-restore-src-');
        if ($tmp === false || ! @copy($archivePath, $tmp)) {
            if ($tmp !== false) {
                @unlink($tmp);
            }
            throw new BackupException('Could not stage the backup archive for restore.');
        }

        return $tmp;
    }

    /** Resolve a backup name to a real archive path inside the configured backup dir, or null. Path-safe. */
    private function resolveArchive(string $name): ?string
    {
        $name = basename($name);
        if (! preg_match('/^hearth-\d{8}-\d{6}\.zip$/', $name)) {
            return null;
        }
        $path = $this->backups->destination().DIRECTORY_SEPARATOR.$name;

        return is_file($path) ? $path : null;
    }

    /** Best-effort audit write — a logging hiccup must never fail (or un-record) an otherwise-good restore. */
    private function audit(string $action, string $archive, string $source, array $changes): void
    {
        try {
            Audit::log($action, null, array_merge(['archive' => $archive, 'source' => $source], $changes));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function trim(Throwable $e): string
    {
        return mb_substr($e->getMessage(), 0, 500);
    }
}
