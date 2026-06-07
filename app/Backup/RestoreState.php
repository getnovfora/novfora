<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Backup;

use App\Upgrade\SchemaState;
use Throwable;

/**
 * The state authority for the no-SSH panel restore (RH-11). The sibling of {@see SchemaState},
 * but **file-backed, not cache-backed** — and that is the whole point.
 *
 * WHY A FILE, NOT THE CACHE. A restore OVERWRITES the live database. On the baseline tier the cache,
 * session, AND queue all live in that database (`.env.example`: CACHE_STORE/SESSION_DRIVER/QUEUE_CONNECTION
 * = database). So the instant a restore replaces the DB, any cache-backed maintenance flag is wiped — the
 * gate would lift mid-restore and serve requests against a half-restored database. The state therefore
 * lives in a small JSON file **outside `storage/app`** (which the restore targets), so it survives the DB
 * swap and keeps the maintenance gate up across it. The same reason rules out a DB-queue job for the
 * restore: its own `jobs` row would vanish mid-flight. {@see RestoreRunner} is driven from the cron line
 * instead, coordinated entirely through this file.
 *
 * SINGLE-ATTEMPT, FAIL-SAFE. A restore is destructive, so it is NOT auto-retried: it either succeeds, or it
 * is **held for the operator** (`stuck`) — the site stays gated rather than serving a possibly half-restored
 * DB. There is no attempt counter and no resume loop. Recovery from a stuck restore: re-restore from the
 * panel once it is reachable, or — the no-SSH escape, since the gate holds the panel too — delete this state
 * file via FTP / the host file manager (the same deliberate filesystem action that resets the install
 * marker), then restore a known-good backup. The gate engages while a restore is requested, running, OR
 * stuck; unlike SchemaState's `upgrading` flag, a stale `running` is NOT auto-cleared to "open" (a
 * half-restored DB is genuinely broken, so failing safe means staying in maintenance — a crash mid-restore
 * is detected by the runner via its file lock and converted to `stuck`).
 *
 * STATE SHAPE (scalars/arrays only, like SchemaState — portable + torn-read-tolerant):
 *   [
 *     'requested'   => array{archive:string, actor_id:?int, actor_name:?string, requested_at:int}|null,
 *     'running'     => bool,        // a restore is executing right now
 *     'running_at'  => int,         // unix ts beginRun() stamped (for /health age + crash detection)
 *     'stuck'       => bool,        // a restore failed/was interrupted and is held for the operator
 *     'last'        => array|null,  // last run summary (result/at/archive/duration/error/safety_backup)
 *   ]
 */
final class RestoreState
{
    /** @var array<string,mixed>|null per-request memo of the decoded file (invalidated on put/forget). */
    private ?array $memo = null;

    /** The JSON state file. Must live OUTSIDE storage/app (the restore target) so a restore can't wipe it. */
    public function path(): string
    {
        return (string) config('hearth.backup.restore_state_path', storage_path('hearth-restore.json'));
    }

    // ── Gate / read predicates (cheap: one small file read, no DB) ───────────────────────────────────

    /**
     * Should the maintenance gate hold this request? True whenever a restore is requested, running, or
     * stuck. Fails OPEN on a read error (a momentary torn read must not 503 a healthy site; the window is
     * brief and the next read succeeds) — consistent with SchemaState's philosophy.
     */
    public function shouldGateRequests(): bool
    {
        try {
            $s = $this->state();

            return ($s['running'] ?? false) === true
                || ($s['stuck'] ?? false) === true
                || $this->pendingRequest() !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{archive:string, actor_id:?int, actor_name:?string, requested_at:int}|null */
    public function pendingRequest(): ?array
    {
        $r = $this->state()['requested'] ?? null;

        return is_array($r) && isset($r['archive']) && is_string($r['archive']) ? $r : null;
    }

    public function isRequested(): bool
    {
        return $this->pendingRequest() !== null;
    }

    public function requestedArchive(): ?string
    {
        return $this->pendingRequest()['archive'] ?? null;
    }

    public function isRunning(): bool
    {
        return ($this->state()['running'] ?? false) === true;
    }

    public function isStuck(): bool
    {
        return ($this->state()['stuck'] ?? false) === true;
    }

    // ── Mutation (only the runner / the panel request path call these) ───────────────────────────────

    /** Record an operator-requested restore (the panel path). The gate engages on the next request. */
    public function request(string $archive, ?int $actorId, ?string $actorName): void
    {
        $this->put([
            'requested' => [
                'archive' => $archive,
                'actor_id' => $actorId,
                'actor_name' => $actorName,
                'requested_at' => now()->timestamp,
            ],
            'running' => false,
            'stuck' => false,
        ]);
    }

    public function clearRequest(): void
    {
        $this->put(['requested' => null]);
    }

    /** Enter the restore window. The timestamp feeds /health and surfaces a long-running/interrupted run. */
    public function beginRun(): void
    {
        $this->put(['running' => true, 'running_at' => now()->timestamp]);
    }

    /** A clean restore: clear the request + running + stuck, record a non-secret summary. */
    public function recordSuccess(string $archive, int $durationMs, ?string $safetyBackup, ?string $dbDriver, ?string $actorName): void
    {
        $this->put([
            'requested' => null,
            'running' => false,
            'stuck' => false,
            'last' => [
                'result' => 'success',
                'at' => now()->toIso8601String(),
                'archive' => $archive,
                'duration_ms' => $durationMs,
                'safety_backup' => $safetyBackup,
                'db_driver' => $dbDriver,
                'actor_name' => $actorName,
            ],
        ]);
    }

    /**
     * A failed (or interrupted) restore. Always clears `running` and the `requested` instruction (single
     * attempt — never auto-retried). `stuck` is true when the failure occurred after the DB may have been
     * touched (the restore stage / a crash) so the site stays gated for the operator; false for a
     * validation failure where nothing was touched (the gate then lifts).
     */
    public function recordFailure(string $stage, string $error, ?string $archive, bool $stuck, ?string $safetyBackup): void
    {
        $this->put([
            'requested' => null,
            'running' => false,
            'stuck' => $stuck,
            'last' => [
                'result' => 'failed',
                'stage' => $stage,
                'at' => now()->toIso8601String(),
                'archive' => $archive,
                'error' => mb_substr($error, 0, 500),
                'safety_backup' => $safetyBackup,
            ],
        ]);
    }

    /** The pre-restore safety snapshot recorded by the last run, for the operator recovery hint. */
    public function lastSafetyBackup(): ?string
    {
        $last = $this->state()['last'] ?? null;

        return is_array($last) && isset($last['safety_backup']) && is_string($last['safety_backup'])
            ? $last['safety_backup']
            : null;
    }

    // ── Reporting ───────────────────────────────────────────────────────────────────────────────────

    /** The non-secret block for GET /health and the admin panel. No paths, no error text. */
    public function healthBlock(): array
    {
        $s = $this->state();
        $last = $s['last'] ?? null;

        return [
            'requested' => $this->isRequested(),
            'running' => (bool) ($s['running'] ?? false),
            'stuck' => (bool) ($s['stuck'] ?? false),
            'last' => is_array($last) ? [
                'result' => $last['result'] ?? null,
                'at' => $last['at'] ?? null,
                'archive' => $last['archive'] ?? null,
            ] : null,
        ];
    }

    /** The full last-run summary for the (authenticated) admin panel — may include the failure stage/error. */
    public function lastRun(): ?array
    {
        $last = $this->state()['last'] ?? null;

        return is_array($last) ? $last : null;
    }

    // ── File primitives ─────────────────────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    public function state(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        try {
            $path = $this->path();
            if (! is_file($path)) {
                return $this->memo = [];
            }
            $raw = file_get_contents($path);
            $decoded = $raw === false ? null : json_decode($raw, true);

            return $this->memo = is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            return $this->memo = [];
        }
    }

    /** @param array<string,mixed> $patch */
    public function put(array $patch): void
    {
        $merged = array_merge($this->state(), $patch);
        // Drop null-valued keys so the file stays small + a cleared request really disappears (false/0 stay).
        $merged = array_filter($merged, fn ($v) => $v !== null);

        $path = $this->path();
        $dir = \dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        // LOCK_EX serialises writers; the JSON is tiny so the write is effectively atomic for readers.
        @file_put_contents($path, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        @chmod($path, 0600); // it records who requested the restore — keep it owner-only

        $this->memo = $merged;
    }

    public function forget(): void
    {
        $this->memo = null;
        try {
            $path = $this->path();
            if (is_file($path)) {
                @unlink($path);
            }
        } catch (Throwable) {
            // best effort
        }
    }
}
