<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Install\Installer;
use App\Services\Tier\ServiceTier;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * `GET /health` — a machine-readable status endpoint for uptime monitoring (M5, brief §6 "health
 * checks"). Reports the liveness of the database, cache, and the cron-driven queue, plus the active
 * service tier and install state.
 *
 * Contract: it NEVER throws (every probe is wrapped) and NEVER leaks secrets — only booleans, counts,
 * ages, and labels. Returns HTTP 200 when healthy/degraded and 503 when the database is unreachable, so
 * a monitor can alert on the status code alone. Works before AND after install.
 */
class HealthController extends Controller
{
    /** A heartbeat the queue-drain schedule refreshes each run; staleness => the cron may have stopped. */
    public const QUEUE_HEARTBEAT = 'hearth:health:queue_drained_at';

    public function __invoke(ServiceTier $tier, Installer $installer): JsonResponse
    {
        $db = $this->checkDatabase();
        $cache = $this->checkCache();
        $queue = $this->checkQueue($db['ok']);

        $down = ! $db['ok'];
        $degraded = ! $cache['ok'] || ($queue['ok'] === false);

        $status = $down ? 'down' : ($degraded ? 'degraded' : 'ok');

        return response()->json([
            'status' => $status,
            'app' => config('app.name', 'Hearth'),
            'version' => config('app.version', '1.0.0-mvp'),
            'installed' => $installer->isInstalled(),
            'tier' => $this->tierLabel($tier),
            'checks' => [
                'database' => $db,
                'cache' => $cache,
                'queue' => $queue,
            ],
            'time' => now()->toIso8601String(),
        ], $down ? 503 : 200);
    }

    /** @return array{ok:bool} */
    private function checkDatabase(): array
    {
        try {
            DB::connection()->select('select 1');

            return ['ok' => true];
        } catch (Throwable) {
            return ['ok' => false];
        }
    }

    /** @return array{ok:bool} */
    private function checkCache(): array
    {
        try {
            $token = 'hearth:health:'.bin2hex(random_bytes(4));
            Cache::put($token, '1', 10);
            $ok = Cache::get($token) === '1';
            Cache::forget($token);

            return ['ok' => $ok];
        } catch (Throwable) {
            return ['ok' => false];
        }
    }

    /**
     * Queue health = how many jobs are waiting + how long since the cron last drained it. `ok` is null
     * (unknown) when no heartbeat has been recorded yet, true when fresh, false when stale.
     *
     * @return array{ok:bool|null, pending:int|null, last_drained_age_seconds:int|null}
     */
    private function checkQueue(bool $dbOk): array
    {
        $pending = null;
        if ($dbOk && config('queue.default') === 'database') {
            try {
                $pending = (int) DB::table(config('queue.connections.database.table', 'jobs'))->count();
            } catch (Throwable) {
                $pending = null;
            }
        }

        $age = null;
        $ok = null;
        try {
            $last = Cache::get(self::QUEUE_HEARTBEAT);
            if (is_numeric($last)) {
                $age = max(0, now()->timestamp - (int) $last);
                // The drain runs every cron tick (≤ a few minutes). 30 min stale => something is wrong.
                $ok = $age < 1800;
            }
        } catch (Throwable) {
            // leave unknown
        }

        return ['ok' => $ok, 'pending' => $pending, 'last_drained_age_seconds' => $age];
    }

    private function tierLabel(ServiceTier $tier): string
    {
        try {
            return $tier->snapshot()->overall->label();
        } catch (Throwable) {
            return 'unknown';
        }
    }
}
