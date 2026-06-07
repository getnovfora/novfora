<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Upgrade\SchemaState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serve a branded maintenance 503 — never a raw SQL error — while the schema is behind the deployed code
 * (RH-10 / ADR-0021, kickoff §3). The decision is O(cache-read): {@see SchemaState::shouldGateRequests()}
 * reads a cached flag + a glob fingerprint, never the database, so the request path stays cheap.
 *
 * The gate engages in AUTOMATIC mode (the default) for the ≤~2-minute window from a no-SSH deploy until
 * the cron-driven upgrade completes, and whenever a run is in progress or a failed run is held for the
 * operator. In MANUAL mode a merely-pending state does NOT gate the whole site (the documented asymmetry):
 * the operator manages the upgrade themselves, so signed-in pages may error on new columns until they do.
 *
 * The allowlist is intentionally tiny: only the health endpoints (so the owner and Cowork can watch the
 * upgrade remotely without SSH) and static assets/favicon (so the maintenance page styles itself). Auto
 * mode needs no human interaction during the window, so nothing else is exempt.
 */
final class PreventRequestsDuringUpgrade
{
    private const ALLOW = ['health', 'up', 'build/*', 'vendor/*', 'favicon.ico'];

    public function __construct(private readonly SchemaState $schema) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is(...self::ALLOW) || ! $this->schema->shouldGateRequests()) {
            return $next($request);
        }

        return $this->maintenance($request);
    }

    private function maintenance(Request $request): Response
    {
        $retryAfter = max(5, (int) config('hearth.upgrade.retry_after', 30));
        $stuck = $this->schema->isStuck();

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'maintenance',
                'message' => $stuck
                    ? 'The site is paused for an upgrade that needs operator attention.'
                    : 'The site is upgrading. Please retry in a moment.',
            ], 503)->header('Retry-After', (string) $retryAfter);
        }

        return response()
            ->view('maintenance.upgrading', [
                'stuck' => $stuck,
                'backup' => $this->schema->lastBackupName(),
                'retryAfter' => $retryAfter,
                'appName' => (string) config('app.name', 'Hearth'),
            ], 503)
            ->header('Retry-After', (string) $retryAfter);
    }
}
