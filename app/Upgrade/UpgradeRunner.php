<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Upgrade;

use App\Backup\BackupService;
use App\Backup\RestoreState;
use App\Install\Installer;
use App\Permissions\PermissionSync;
use App\Support\Audit;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The no-SSH automatic upgrade orchestrator (RH-10 / ADR-0021). Makes getting-started §5's promise true
 * on the baseline tier: when deployed code has pending migrations, apply them behind a backup-first,
 * maintenance-safe window — driven by the single cron line, no SSH ever.
 *
 * THE RUN (strict order, kickoff §2), under a cache lock so it can never double-run:
 *   a. enter maintenance ({@see SchemaState::beginRun()} — the gate middleware then serves a branded 503);
 *   b. take a backup (the pre-upgrade restore point) — FAILURE ABORTS (stay pending, surface loudly);
 *   c. `migrate --force` over the configured paths (in-process via Artisan);
 *   d. refresh the relevant caches;
 *   e. exit maintenance + audit-log the run (versions, count, duration).
 *
 * COARSE-CRON SAFETY. If the process is killed mid-run, the cache lock auto-expires and nothing records a
 * failure; the next tick re-enters and `migrate --force` simply applies the remaining migrations
 * (already-applied ones are skipped) — resume-by-idempotency, not a half-upgrade.
 *
 * FAILURE POLICY (kickoff §4). On a migrate failure, best-effort roll back THIS run's batch (only if it
 * actually recorded one — otherwise rollback would undo the previous good batch), stay in maintenance,
 * and increment the attempt counter. Automatic mode HOLDS (stuck) once attempts hit the cap — no retry
 * loop. The pre-upgrade backup is the real recovery path (MySQL DDL is not transactional).
 *
 * (Not `final` so it can be swapped for a test double where a caller is unit-tested in isolation.)
 */
class UpgradeRunner
{
    private const LOCK = 'novfora:upgrade:lock';

    public function __construct(
        private readonly Installer $installer,
        private readonly SchemaState $schema,
        private readonly BackupService $backups,
        private readonly RestoreState $restoreState,
    ) {}

    /**
     * The scheduler entry point — runs every cron tick, cheap when nothing is pending. Always refreshes
     * the cached schema flag (so /health + the gate track reality even in manual mode), then runs the
     * upgrade only when automatic mode is on, something is pending, and we are not held for the operator.
     */
    public function runAutomatic(): UpgradeResult
    {
        if (! $this->installer->isInstalled()) {
            return UpgradeResult::skipped('not-installed');
        }

        // Never migrate against a database that is mid-restore (RH-11). A panel restore overwrites the whole
        // DB; let it finish (and re-derive the schema state) before we touch migrations. The restore runner's
        // post-restore SchemaState::refresh() then lets the NEXT tick pick up any now-pending migrations.
        if ($this->restoreState->shouldGateRequests()) {
            return UpgradeResult::skipped('restore-in-progress');
        }

        $this->schema->refresh();

        // Drift resolved without us (the operator re-uploaded the previous release, or applied the
        // migration externally): release any stuck hold so the maintenance gate lifts on its own — the
        // no-SSH recovery is "re-upload the previous zip", and it must self-clear within a cron tick.
        if (! $this->schema->isPending()) {
            if ($this->schema->isStuck()) {
                $this->schema->clearStuck();
            }

            return UpgradeResult::skipped('up-to-date');
        }

        if (! (bool) config('novfora.upgrade.auto', true)) {
            return UpgradeResult::skipped('manual-mode');
        }
        if ($this->schema->isStuck()) {
            return UpgradeResult::skipped('stuck'); // held for the operator — no retry loop
        }

        return $this->runLocked(auto: true);
    }

    /**
     * Operator-initiated (Admin → System → Upgrade, or `php artisan novfora:upgrade`). Ignores the auto
     * toggle and clears any stuck hold first — a human deliberately retrying is not the unattended loop the
     * cap guards against.
     */
    public function runManual(): UpgradeResult
    {
        if (! $this->installer->isInstalled()) {
            return UpgradeResult::skipped('not-installed');
        }

        // Defense in depth: the upgrade panel is itself gated during a restore window, but never migrate
        // against a mid-restore DB even if reached some other way (RH-11).
        if ($this->restoreState->shouldGateRequests()) {
            return UpgradeResult::skipped('restore-in-progress');
        }

        $this->schema->refresh();

        if (! $this->schema->isPending()) {
            $this->schema->clearStuck(); // operator confirmed nothing is pending → clear any stale hold

            return UpgradeResult::skipped('up-to-date');
        }

        // NB: the stuck hold is cleared inside execute() (under the lock), not here — so a run that loses
        // the lock race ('locked') never resets the operator hold / the unattended retry cap.
        return $this->runLocked(auto: false);
    }

    private function runLocked(bool $auto): UpgradeResult
    {
        // The closure runs only if the lock is acquired, and the lock is released afterwards (even on
        // throw). A non-UpgradeResult return means the lock was already held by a concurrent run.
        $ran = Cache::lock(self::LOCK, max(60, (int) config('novfora.upgrade.lock_seconds', 600)))
            ->get(fn () => $this->execute($auto));

        return $ran instanceof UpgradeResult ? $ran : UpgradeResult::skipped('locked');
    }

    private function execute(bool $auto): UpgradeResult
    {
        // A human-initiated run resets the unattended retry cap — but only now that we hold the lock, so a
        // run that lost the lock race never clears the operator's stuck hold.
        if (! $auto) {
            $this->schema->clearStuck();
        }

        // TOCTOU re-check under the lock with the authoritative DB read.
        $pending = $this->schema->pendingMigrationNames();
        if ($pending === []) {
            // Nothing to apply (e.g. another run beat us, or code changed without real migrations).
            // Stamp the fingerprint + clear the gate so we don't sit in a phantom window.
            $this->schema->recordSuccess(0, 0, $this->schema->lastBackupName());

            return UpgradeResult::skipped('up-to-date');
        }

        $started = microtime(true);
        $this->schema->beginRun(); // (a) enter maintenance

        // (b) backup — the pre-upgrade restore point. Failure ABORTS the upgrade.
        try {
            $backup = $this->backups->create()->name();
        } catch (Throwable $e) {
            return $this->fail($auto, 'backup', $e, backup: null);
        }

        // (c) migrate --force over the configured paths, so detection and execution can never disagree.
        $ranBefore = $this->schema->ranMigrationNames();
        try {
            Artisan::call('migrate', [
                '--force' => true,
                '--path' => $this->schema->migrationPaths(),
                '--realpath' => true,
            ]);
        } catch (Throwable $e) {
            $this->rollbackIfBatchApplied($ranBefore);

            return $this->fail($auto, 'migrate', $e, backup: $backup);
        }

        // (c.1) Re-provision role presets so a permission ADDED to a preset in this release reaches the
        // already-seeded site (ADR-0036 — closes the "403 on a new admin screen" class). Best-effort.
        $permissionsSynced = $this->syncPermissions();

        // (d) refresh the caches that could hold a pre-migration shape. Compiled views are content-hashed;
        // config isn't cached on the baseline tier; the schema-state flag is rewritten by recordSuccess().
        $this->clearAppCaches();

        $applied = count($pending);
        $durationMs = (int) round((microtime(true) - $started) * 1000);

        // (e) exit maintenance + record + audit.
        $this->schema->recordSuccess($applied, $durationMs, $backup);
        $this->audit('upgrade.completed', [
            'migrations' => $applied,
            'names' => array_slice($pending, 0, 50),
            'duration_ms' => $durationMs,
            'backup' => $backup,
            'permissions_synced' => $permissionsSynced,
            'mode' => $auto ? 'auto' : 'manual',
        ]);

        return UpgradeResult::success($applied, $durationMs, $backup);
    }

    private function fail(bool $auto, string $stage, Throwable $e, ?string $backup): UpgradeResult
    {
        $attempts = $this->schema->attempts() + 1;
        $maxAuto = max(1, (int) config('novfora.upgrade.max_auto_attempts', 2));

        // Automatic mode holds once attempts are exhausted (no retry loop). A manual failure holds
        // immediately — a human is watching, so surface it rather than leaving an un-flagged broken state.
        $stuck = $auto ? ($attempts >= $maxAuto) : true;

        $this->schema->recordFailure($stage, $e->getMessage(), $backup, $attempts, $stuck);

        $this->audit('upgrade.failed', [
            'stage' => $stage,
            'attempt' => $attempts,
            'stuck' => $stuck,
            'error' => mb_substr($e->getMessage(), 0, 500),
            'backup' => $backup,
            'mode' => $auto ? 'auto' : 'manual',
        ]);

        report($e); // to the log channel for the operator

        return UpgradeResult::failed($stage, $e->getMessage(), $backup);
    }

    /**
     * Re-provision role presets onto existing roles/groups so a permission ADDED to a preset in this
     * release reaches the already-seeded install (ADR-0036). ADDITIVE + idempotent, so it is safe on every
     * upgrade. BEST-EFFORT: a sync hiccup must never fail (or un-record) an otherwise-good schema upgrade —
     * the migrations already applied. Returns the number of changes applied, or null on failure (surfaced
     * via report() + an audit line so it isn't silent).
     */
    private function syncPermissions(): ?int
    {
        try {
            return app(PermissionSync::class)->sync()->totalChanges();
        } catch (Throwable $e) {
            report($e);
            $this->audit('upgrade.permissions_sync_failed', ['error' => mb_substr($e->getMessage(), 0, 500)]);

            return null;
        }
    }

    /** Best-effort audit write — a logging hiccup must never fail (or un-record) an otherwise-good upgrade. */
    private function audit(string $action, array $changes): void
    {
        try {
            Audit::log($action, null, $changes);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Best-effort rollback of THIS run's batch — only when the run actually recorded new migrations.
     * Otherwise migrate:rollback would undo the previous (good) batch, which is destructive; a single
     * migration that threw before recording leaves the pre-upgrade backup as the recovery path.
     *
     * @param  list<string>  $ranBefore
     */
    private function rollbackIfBatchApplied(array $ranBefore): void
    {
        try {
            $ranAfter = $this->schema->ranMigrationNames();
            $appliedThisRun = count($ranAfter) - count($ranBefore);
            if ($appliedThisRun > 0) {
                // Roll back exactly the migrations THIS run recorded (the most-recent N) — not a fixed
                // batch, so a multi-migration run that fails partway is fully undone, while a run that
                // recorded nothing touches no prior batch.
                Artisan::call('migrate:rollback', [
                    '--step' => $appliedThisRun,
                    '--force' => true,
                    '--path' => $this->schema->migrationPaths(),
                    '--realpath' => true,
                ]);
            }
        } catch (Throwable $e) {
            report($e); // rollback is best-effort; the backup is the authoritative safety net
        }
    }

    private function clearAppCaches(): void
    {
        foreach (['forum.index.tree', 'novfora.sitemap'] as $key) {
            try {
                Cache::forget($key);
            } catch (Throwable) {
                // best effort — a stale fragment self-heals on its short TTL
            }
        }
    }
}
