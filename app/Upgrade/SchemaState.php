<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Upgrade;

use App\Install\Installer;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The schema-state authority for the no-SSH automatic upgrade (RH-10 / ADR-0021).
 *
 * Two read budgets, deliberately split:
 *  • The REQUEST path ({@see shouldGateRequests()}, {@see isPending()}) must stay cheap — a cache read
 *    plus a glob+sha1 of the migration filenames (the "code fingerprint"). It does NOT run the migrator's
 *    DB-heavy schema check or query the migrations table (it only resolves the migrator singleton to read
 *    its in-memory path list — no DB). This is what lets every page decide "is the schema behind the
 *    deployed code?" without a per-request DB-heavy check (perf budget, kickoff §1).
 *  • The SCHEDULER path ({@see refresh()}, {@see hasPendingMigrations()}) may do the real, DB-heavy check
 *    (it runs once a minute from the single cron line, not on a request).
 *
 * THE INVALIDATION TRICK. The cached flag is "refreshed by the scheduler tick and invalidated by a
 * release/build marker change" (kickoff §1). The marker is the set of migration files on disk: when a new
 * release with new migrations is extracted over a live install, the {@see codeFingerprint()} changes, so
 * even before the next scheduler tick every request sees "fingerprint mismatch ⇒ pending" and is gated —
 * closing the deploy→first-tick window where new code would otherwise 500 on a column the DB lacks.
 *
 * STATE SHAPE (one cache key, scalars only). Storing only scalars/arrays is load-bearing: the app sets
 * cache.serializable_classes=false (RH-9 anti-object-injection), so any object cached on a serializing
 * store deserialises to __PHP_Incomplete_Class. Every value here is bool|int|string|null.
 *   [
 *     'pending'     => bool,        // outstanding migrations at the last DB check
 *     'fingerprint' => string,      // codeFingerprint() at the last DB check / successful run
 *     'upgrading'   => bool,        // a run is in progress right now (enter/exit maintenance)
 *     'stuck'       => bool,        // auto-upgrade exhausted its retries; HOLD for the operator
 *     'attempts'    => int,         // consecutive failed automatic attempts for the current fingerprint
 *     'checked_at'  => int,         // unix ts of the last refresh()
 *     'last'        => array|null,  // last run summary (result/at/migrations/duration/backup/stage/error)
 *   ]
 */
final class SchemaState
{
    /** One cache key holds the whole state (one O(1) read on the request path). */
    public const KEY = 'novfora:schema:state';

    /** Non-blocking lock so only one request performs the empty-state bootstrap check (no stampede). */
    private const BOOTSTRAP_LOCK = 'novfora:schema:bootstrap';

    /** @var array<string,string> memoised fingerprints, keyed by the joined migration paths */
    private static array $fingerprintMemo = [];

    // ── Request-path reads (cheap: cache + glob, never the migrator/DB) ─────────────────────────────

    /**
     * Should the maintenance gate hold this request? True when a run is in progress or stuck, or when the
     * schema is behind the deployed code AND automatic mode is on. In MANUAL mode a merely-pending state
     * does NOT gate the whole site — the documented asymmetry (kickoff §5): the admin must be able to
     * reach the panel to apply, so signed-in pages may error on new columns until they do. Fails OPEN on
     * a cache error (a cache blip should not 503 the entire site; the window is short).
     */
    public function shouldGateRequests(): bool
    {
        try {
            $s = $this->state();
            if ($s === []) {
                // No recorded state. Two cases look identical here: a fresh install (already migrated by the
                // installer → nothing pending) and the FIRST deploy that introduces this mechanism over an
                // older release (the old code wrote no state, yet the new schema is outstanding). The
                // fingerprint trick can't tell them apart with nothing to compare against, so do ONE
                // authoritative check — which also populates the cache, so this bootstrap cost is paid once,
                // not per request. Skip it pre-install (RedirectIfNotInstalled owns that) and fail open.
                if (! app(Installer::class)->isInstalled()) {
                    return false;
                }
                // One request does the authoritative check; concurrent first-requests fall open for that
                // instant (reads are null-safe, so an ungated page can't 500 on a missing column anyway).
                Cache::lock(self::BOOTSTRAP_LOCK, 10)->get(fn () => $this->refresh());
                $s = $this->state();
                if ($s === []) {
                    return false; // refresh couldn't write (DB/cache down) → don't 503 the whole site
                }
            }
            if ($this->upgradingActive($s)) {
                return true;  // mid-migration — never serve the app
            }
            if (($s['stuck'] ?? false) === true) {
                return true;  // a failed auto-upgrade — hold for the operator, show the recovery hint
            }
            if (! $this->pendingFromState($s)) {
                return false;
            }

            return (bool) config('novfora.upgrade.auto', true);
        } catch (Throwable) {
            return false;
        }
    }

    /** Outstanding migrations, mode-independent (cached flag OR a new-release fingerprint mismatch). */
    public function isPending(): bool
    {
        return $this->pendingFromState($this->state());
    }

    public function isUpgrading(): bool
    {
        return $this->upgradingActive($this->state());
    }

    /**
     * Whether a run is GENUINELY in progress — not a stale flag from a hard-killed process. {@see beginRun()}
     * stamps `upgrading_at`; once that is older than the lock window (the longest a run could hold the upgrade
     * lock), the flag is treated as expired and ignored. This is what stops a process killed (SIGKILL / OOM /
     * fatal) between beginRun() and the success/failure record from wedging the whole site at 503 forever —
     * in automatic mode the next tick re-runs and clears it; in manual mode it self-clears at the window.
     *
     * @param  array<string,mixed>  $s
     */
    private function upgradingActive(array $s): bool
    {
        if (($s['upgrading'] ?? false) !== true) {
            return false;
        }
        $startedAt = (int) ($s['upgrading_at'] ?? 0);
        $window = max(60, (int) config('novfora.upgrade.lock_seconds', 600));

        return $startedAt > 0 && (now()->timestamp - $startedAt) < $window;
    }

    public function isStuck(): bool
    {
        return ($this->state()['stuck'] ?? false) === true;
    }

    public function attempts(): int
    {
        return (int) ($this->state()['attempts'] ?? 0);
    }

    /**
     * A non-secret fingerprint of the deployed migration set — the "release marker". Changes exactly when
     * migrations are added/removed/renamed, i.e. exactly when the schema could need upgrading. A glob +
     * sha1 of basenames: microseconds, no DB. Memoised per request (paths are stable within a process).
     */
    public function codeFingerprint(): string
    {
        $paths = $this->migrationPaths();
        $memoKey = implode('|', $paths);
        if (isset(self::$fingerprintMemo[$memoKey])) {
            return self::$fingerprintMemo[$memoKey];
        }

        $names = [];
        foreach ($paths as $path) {
            foreach (glob(rtrim($path, '/\\').DIRECTORY_SEPARATOR.'*.php') ?: [] as $file) {
                $names[] = basename($file);
            }
        }
        sort($names);

        return self::$fingerprintMemo[$memoKey] = $names === []
            ? 'none'
            : substr(hash('sha256', implode("\n", $names)), 0, 16);
    }

    // ── Scheduler-path reads (DB-heavy; only run from the cron tick / a run) ────────────────────────

    /**
     * Re-check the database and refresh the cached flag (the scheduler tick). Returns the pending state.
     * On a DB error it leaves the prior state untouched (a transient DB blip must not flap the gate).
     */
    public function refresh(): bool
    {
        try {
            $pending = $this->hasPendingMigrations();
        } catch (Throwable) {
            return $this->isPending();
        }

        $this->put([
            'pending' => $pending,
            'fingerprint' => $this->codeFingerprint(),
            'checked_at' => now()->timestamp,
        ]);

        return $pending;
    }

    public function hasPendingMigrations(): bool
    {
        return $this->pendingMigrationNames() !== [];
    }

    /**
     * The authoritative pending set via the migrator. Empty when the migrations table doesn't exist yet
     * (a fresh, not-yet-installed DB) — the installer, not this mechanism, handles a first install, so we
     * never gate one. @return list<string>
     */
    public function pendingMigrationNames(): array
    {
        $migrator = app('migrator');

        if (! $migrator->repositoryExists()) {
            return [];
        }

        $files = $migrator->getMigrationFiles($this->migrationPaths());
        $ran = $migrator->getRepository()->getRan();
        $names = array_map(fn ($f) => $migrator->getMigrationName($f), array_values($files));

        return array_values(array_diff($names, $ran));
    }

    /** Applied migration names — used by the runner to know whether a failed run left a rollback-able batch. */
    public function ranMigrationNames(): array
    {
        $migrator = app('migrator');
        if (! $migrator->repositoryExists()) {
            return [];
        }

        return $migrator->getRepository()->getRan();
    }

    /**
     * The absolute migration directories — detection, the fingerprint, and the upgrade run all use this one
     * list, so they can never disagree. It mirrors what `php artisan migrate` runs by default (any
     * package-registered paths + the app's database/migrations), plus the configurable list (overridable so
     * tests can inject a fixture "release" and modules can extend it). @return list<string>
     */
    public function migrationPaths(): array
    {
        $configured = config('novfora.upgrade.migration_paths');
        $configured = is_array($configured) && $configured !== []
            ? array_values($configured)
            : [database_path('migrations')];

        try {
            $registered = app('migrator')->paths(); // paths added via loadMigrationsFrom() (none in core today)
        } catch (Throwable) {
            $registered = [];
        }

        return array_values(array_unique(array_merge($registered, $configured)));
    }

    // ── State mutation (only the runner calls these, under the upgrade lock) ─────────────────────────

    /** Enter the maintenance window for an in-progress run. The timestamp lets the flag self-expire if the
     * process is hard-killed before it can record success/failure (see {@see upgradingActive()}). */
    public function beginRun(): void
    {
        $this->put(['upgrading' => true, 'upgrading_at' => now()->timestamp]);
    }

    /** A clean run: clear pending/stuck, reset attempts, stamp the fingerprint, record a non-secret summary. */
    public function recordSuccess(int $migrationsApplied, int $durationMs, ?string $backup): void
    {
        $this->put([
            'pending' => false,
            'upgrading' => false,
            'stuck' => false,
            'attempts' => 0,
            'fingerprint' => $this->codeFingerprint(),
            'checked_at' => now()->timestamp,
            'last' => [
                'result' => 'success',
                'at' => now()->toIso8601String(),
                'migrations' => $migrationsApplied,
                'duration_ms' => $durationMs,
                'backup' => $backup,
            ],
        ]);
    }

    /**
     * A failed run: exit the in-progress flag but KEEP the site gated (pending stays true). `attempts` and
     * `stuck` are decided by the runner (mode-aware) and passed in.
     */
    public function recordFailure(string $stage, string $error, ?string $backup, int $attempts, bool $stuck): void
    {
        $this->put([
            'upgrading' => false,
            'stuck' => $stuck,
            'attempts' => $attempts,
            'last' => [
                'result' => 'failed',
                'stage' => $stage,
                'at' => now()->toIso8601String(),
                'error' => mb_substr($error, 0, 500),
                'backup' => $backup,
            ],
        ]);
    }

    /** Operator override: clear a stuck hold so a fresh attempt can run. */
    public function clearStuck(): void
    {
        $this->put(['stuck' => false, 'attempts' => 0]);
    }

    /** The pre-upgrade backup recorded by the last run, for the operator recovery hint. */
    public function lastBackupName(): ?string
    {
        $last = $this->state()['last'] ?? null;

        return is_array($last) && isset($last['backup']) && is_string($last['backup']) ? $last['backup'] : null;
    }

    // ── Reporting ───────────────────────────────────────────────────────────────────────────────────

    /** The non-secret block for GET /health and the admin panel. No paths, no error text, no secrets. */
    public function healthBlock(): array
    {
        $s = $this->state();
        $last = $s['last'] ?? null;

        return [
            'pending' => $this->isPending(),
            'upgrading' => $this->upgradingActive($s),
            'stuck' => (bool) ($s['stuck'] ?? false),
            'auto' => (bool) config('novfora.upgrade.auto', true),
            'last' => is_array($last) ? [
                'result' => $last['result'] ?? null,
                'at' => $last['at'] ?? null,
                'migrations' => $last['migrations'] ?? null,
            ] : null,
        ];
    }

    /** The full last-run summary for the (authenticated) admin panel — may include the failure stage/error. */
    public function lastRun(): ?array
    {
        $last = $this->state()['last'] ?? null;

        return is_array($last) ? $last : null;
    }

    // ── Cache primitives ────────────────────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    public function state(): array
    {
        try {
            $v = Cache::get(self::KEY);
        } catch (Throwable) {
            return [];
        }

        return is_array($v) ? $v : [];
    }

    /** @param array<string,mixed> $patch */
    public function put(array $patch): void
    {
        // Scalars/arrays only (RH-9): an object here would not survive a serializing store under
        // cache.serializable_classes=false. The whole state is intentionally primitive.
        Cache::put(self::KEY, array_merge($this->state(), $patch), now()->addDays(30));
    }

    public function forget(): void
    {
        try {
            Cache::forget(self::KEY);
        } catch (Throwable) {
            // best effort
        }
    }

    /** @param array<string,mixed> $s */
    private function pendingFromState(array $s): bool
    {
        if ($s === []) {
            return false;
        }
        if (($s['pending'] ?? false) === true) {
            return true;
        }

        return ($s['fingerprint'] ?? null) !== $this->codeFingerprint();
    }
}
