<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Admin;

use App\Backup\BackupService;
use App\Backup\RestoreState;
use App\Http\Controllers\HealthController;
use App\Models\AuditLog;
use App\Models\Post;
use App\Models\Report;
use App\Models\Topic;
use App\Models\User;
use App\Services\Tier\ServiceTier;
use App\Upgrade\SchemaState;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The data behind the ACP dashboard (ACP v1, PART 1). Every figure is either an O(cache-read) lookup or
 * a cheap indexed count — the dashboard is read-only and must never become a heavy page. The health
 * indicators reuse the same internals as GET /health (DB / cache / cron-heartbeat / schema / restore /
 * backup age / tier) without going through HTTP, so the dashboard and the monitor agree.
 */
final class DashboardData
{
    public function __construct(
        private readonly ServiceTier $tier,
        private readonly SchemaState $schema,
        private readonly RestoreState $restore,
        private readonly BackupService $backups,
    ) {}

    /**
     * Headline community counts, cached 60s so a busy admin reloading the dashboard never hammers COUNT(*).
     *
     * @return array{members:int,topics:int,posts:int}
     */
    public function stats(): array
    {
        return Cache::remember('hearth:admin:dashboard_stats', now()->addSeconds(60), fn (): array => [
            'members' => User::count(),
            'topics' => Topic::count(),
            'posts' => Post::count(),
        ]);
    }

    /**
     * Operator to-do counts — the approval queue (held topics + posts) and open reports. Cheap indexed
     * counts (kept live, not cached, so the badge is honest). Mirrors ModerationController@dashboard.
     *
     * @return array{queue:int,reports:int}
     */
    public function pendingActions(): array
    {
        return [
            'queue' => Topic::where('approved_state', 'pending')->count()
                + Post::where('approved_state', 'pending')->count(),
            'reports' => Report::where('status', 'open')->count(),
        ];
    }

    /** @return array<string,mixed> */
    public function health(): array
    {
        $db = $this->checkDatabase();

        return [
            'database' => $db,
            'cache' => $this->checkCache(),
            'queue' => $this->checkQueue(),
            'schema' => $this->safe(fn () => $this->schema->healthBlock(), ['pending' => false, 'upgrading' => false, 'stuck' => false, 'auto' => true, 'last' => null]),
            'restore' => $this->safe(fn () => $this->restore->healthBlock(), ['requested' => false, 'running' => false, 'stuck' => false, 'last' => null]),
            'backup_age' => $this->backupAgeSeconds(),
            'tier' => $this->tierLabel(),
        ];
    }

    /** @return Collection<int,AuditLog> */
    public function recentAudit(int $limit = 8): Collection
    {
        return AuditLog::with('actor')->latest('id')->limit($limit)->get();
    }

    /** @return array{ok:bool} */
    private function checkDatabase(): array
    {
        try {
            DB::connection()->select('select 1');

            return ['ok' => true];
        } catch (\Throwable) {
            return ['ok' => false];
        }
    }

    /** @return array{ok:bool} */
    private function checkCache(): array
    {
        try {
            $token = 'hearth:admin:health:'.bin2hex(random_bytes(4));
            Cache::put($token, '1', 10);
            $ok = Cache::get($token) === '1';
            Cache::forget($token);

            return ['ok' => $ok];
        } catch (\Throwable) {
            return ['ok' => false];
        }
    }

    /** @return array{ok:bool|null,age:int|null} */
    private function checkQueue(): array
    {
        $age = null;
        $ok = null;
        try {
            $last = Cache::get(HealthController::QUEUE_HEARTBEAT);
            if (is_numeric($last)) {
                $age = max(0, now()->timestamp - (int) $last);
                $ok = $age < 1800; // the cron drains every minute; 30 min stale ⇒ something is wrong
            }
        } catch (\Throwable) {
            // unknown
        }

        return ['ok' => $ok, 'age' => $age];
    }

    private function backupAgeSeconds(): ?int
    {
        try {
            $items = $this->backups->list();
            if ($items === []) {
                return null;
            }
            $newest = max(array_map(static fn (array $i): int => (int) $i['created'], $items));

            return $newest > 0 ? max(0, now()->timestamp - $newest) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function tierLabel(): string
    {
        try {
            return $this->tier->snapshot()->overall->label();
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    /**
     * @template T
     *
     * @param  callable():T  $fn
     * @param  T  $fallback
     * @return T
     */
    private function safe(callable $fn, mixed $fallback): mixed
    {
        try {
            return $fn();
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
