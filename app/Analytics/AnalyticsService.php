<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Analytics;

use App\Models\DailyMetric;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Computes + reads privacy-conscious admin analytics (ADR-0035). Every figure is an AGGREGATE count — there is
 * NO per-user tracking, no IP logging, no PII. `rollup($date)` computes a closed set of metrics for a day and
 * upserts them (idempotent, so the daily cron and a backfill are both safe to re-run). Totals are computed
 * as-of the end of the day so a backfilled timeseries is correct, not just "now".
 */
final class AnalyticsService
{
    /** The closed set of metric keys (a fixed schema — never derived from input). */
    public const METRICS = ['users_new', 'users_total', 'topics_new', 'topics_total', 'posts_new', 'posts_total', 'active_users'];

    public function rollup(Carbon $date): void
    {
        $start = $date->copy()->startOfDay();
        $end = $date->copy()->endOfDay();

        $values = [
            'users_new' => User::query()->whereBetween('created_at', [$start, $end])->count(),
            'users_total' => User::query()->where('created_at', '<=', $end)->count(),
            'topics_new' => Topic::query()->whereBetween('created_at', [$start, $end])->count(),
            'topics_total' => Topic::query()->where('created_at', '<=', $end)->count(),
            'posts_new' => Post::query()->whereBetween('created_at', [$start, $end])->count(),
            'posts_total' => Post::query()->where('created_at', '<=', $end)->count(),
            'active_users' => User::query()->whereBetween('last_active_at', [$start, $end])->count(),
        ];

        foreach ($values as $key => $value) {
            DailyMetric::query()->updateOrCreate(
                ['metric_date' => $start->toDateString(), 'metric_key' => $key],
                ['value' => (int) $value],
            );
        }
    }

    /** Rollup a window of days ending today (used by the cron — finalises yesterday + refreshes today). */
    public function rollupRecent(int $days = 1): void
    {
        for ($i = $days; $i >= 0; $i--) {
            $this->rollup(now()->subDays($i));
        }
    }

    /**
     * The recent daily series for the dashboard, as `metric_key => [ [date, value], … ]`.
     *
     * @return array<string, list<array{date:string, value:int}>>
     */
    public function series(int $days = 30): array
    {
        $rows = DailyMetric::query()
            ->where('metric_date', '>=', now()->subDays($days)->toDateString())
            ->orderBy('metric_date')
            ->get();

        $out = [];
        foreach (self::METRICS as $key) {
            $out[$key] = $rows->where('metric_key', $key)
                ->map(fn (DailyMetric $m): array => ['date' => (string) $m->metric_date, 'value' => $m->value])
                ->values()->all();
        }

        return $out;
    }

    /** Current live totals (cheap counts) for the dashboard's headline cards — no PII. @return array<string,int> */
    public function liveTotals(): array
    {
        return [
            'users_total' => User::query()->count(),
            'topics_total' => Topic::query()->count(),
            'posts_total' => Post::query()->count(),
            'active_users' => User::query()->whereBetween('last_active_at', [now()->startOfDay(), now()->endOfDay()])->count(),
        ];
    }

    /** @return Collection<int,DailyMetric> */
    public function all(): Collection
    {
        return DailyMetric::query()->orderBy('metric_date')->get();
    }
}
