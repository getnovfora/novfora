<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Analytics;

use App\Models\DailyMetric;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use App\Modules\Facades\Hook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Computes + reads privacy-conscious admin analytics (ADR-0035). Every figure is an AGGREGATE count — there is
 * NO per-user tracking, no IP logging, no PII. `rollup($date)` computes a closed set of metrics for a day and
 * upserts them (idempotent, so the daily cron and a backfill are both safe to re-run). Totals are computed
 * as-of the end of the day so a backfilled timeseries is correct, not just "now".
 *
 * MODULE SEAM (API 1.2): every user-count query runs through the `stats.users.query` filter (value: the
 * User query builder; arg: a context label like `analytics.users_total`) so a module can scope which
 * accounts count (e.g. a seeding plugin excluding simulated members). Aggregate counts only — the filter
 * never exposes rows.
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
            'users_new' => $this->users(User::query()->whereBetween('created_at', [$start, $end]), 'analytics.users_new')->count(),
            'users_total' => $this->users(User::query()->where('created_at', '<=', $end), 'analytics.users_total')->count(),
            'topics_new' => Topic::query()->whereBetween('created_at', [$start, $end])->count(),
            'topics_total' => Topic::query()->where('created_at', '<=', $end)->count(),
            'posts_new' => Post::query()->whereBetween('created_at', [$start, $end])->count(),
            'posts_total' => Post::query()->where('created_at', '<=', $end)->count(),
            'active_users' => $this->users(User::query()->whereBetween('last_active_at', [$start, $end]), 'analytics.active_users')->count(),
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
            'users_total' => $this->users(User::query(), 'analytics.users_total')->count(),
            'topics_total' => Topic::query()->count(),
            'posts_total' => Post::query()->count(),
            'active_users' => $this->users(User::query()->whereBetween('last_active_at', [now()->startOfDay(), now()->endOfDay()]), 'analytics.active_users')->count(),
        ];
    }

    /**
     * Run a user-count query through the `stats.users.query` module filter (see class docblock). A filter
     * returning anything but a builder is discarded — a faulty module can't break the dashboard.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    private function users(Builder $query, string $context): Builder
    {
        $filtered = Hook::applyFilters('stats.users.query', $query, $context);

        return $filtered instanceof Builder ? $filtered : $query;
    }

    /** @return Collection<int,DailyMetric> */
    public function all(): Collection
    {
        return DailyMetric::query()->orderBy('metric_date')->get();
    }
}
