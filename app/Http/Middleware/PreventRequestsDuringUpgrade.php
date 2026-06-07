<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Backup\RestoreState;
use App\Upgrade\SchemaState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serve a branded maintenance 503 — never a raw SQL error — while an operability window is open: a no-SSH
 * automatic UPGRADE applying pending migrations (RH-10 / ADR-0021), or a no-SSH panel RESTORE overwriting
 * the database (RH-11 / ADR-0022). Both decisions are cheap on the request path: {@see RestoreState} is one
 * small file read and {@see SchemaState::shouldGateRequests()} is a cached flag + a glob fingerprint — no
 * DB-heavy migrator/schema check.
 *
 * ORDER MATTERS. The restore check runs FIRST and is file-based on purpose: a restore overwrites the
 * DB-backed cache the schema gate reads, so the file-backed restore state is what keeps the site gated
 * across the swap. The upgrade gate (cache-based) takes over afterwards if the restored schema turns out to
 * be behind the deployed code — the intended RH-11 → RH-10 hand-off.
 *
 * The allowlist is intentionally tiny: only the health endpoints (so the owner/Cowork can watch the window
 * remotely without SSH) and static assets/favicon (so the maintenance page styles itself). The admin panel
 * is deliberately NOT exempt — a restore is triggered before the window opens, and is then watched via the
 * self-refreshing maintenance page + /health, exactly like an auto-upgrade.
 */
final class PreventRequestsDuringUpgrade
{
    private const ALLOW = ['health', 'up', 'build/*', 'vendor/*', 'favicon.ico'];

    public function __construct(
        private readonly SchemaState $schema,
        private readonly RestoreState $restore,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is(...self::ALLOW)) {
            return $next($request);
        }

        // Restore first (file-based → survives the DB/cache wipe a restore performs).
        if ($this->restore->shouldGateRequests()) {
            return $this->maintenance($request, 'restore', $this->restore->isStuck(), $this->restore->lastSafetyBackup());
        }

        // Then the RH-10 upgrade gate (cache-based).
        if ($this->schema->shouldGateRequests()) {
            return $this->maintenance($request, 'upgrade', $this->schema->isStuck(), $this->schema->lastBackupName());
        }

        return $next($request);
    }

    private function maintenance(Request $request, string $mode, bool $stuck, ?string $backup): Response
    {
        $retryAfter = max(5, (int) config('hearth.upgrade.retry_after', 30));

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'maintenance',
                'message' => $this->jsonMessage($mode, $stuck),
            ], 503)->header('Retry-After', (string) $retryAfter);
        }

        return response()
            ->view('maintenance.upgrading', [
                'mode' => $mode,
                'stuck' => $stuck,
                'backup' => $backup,
                'retryAfter' => $retryAfter,
                'appName' => (string) config('app.name', 'Hearth'),
            ], 503)
            ->header('Retry-After', (string) $retryAfter);
    }

    private function jsonMessage(string $mode, bool $stuck): string
    {
        if ($mode === 'restore') {
            return $stuck
                ? 'A backup restore needs operator attention.'
                : 'The site is restoring a backup. Please retry in a moment.';
        }

        return $stuck
            ? 'The site is paused for an upgrade that needs operator attention.'
            : 'The site is upgrading. Please retry in a moment.';
    }
}
