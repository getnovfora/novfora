<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Analytics;

use App\Models\AuditLog;
use App\Models\DailyMetric;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use App\Modules\Facades\Hook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
    public const METRICS = [
        'users_new', 'users_total', 'topics_new', 'topics_total', 'posts_new', 'posts_total', 'active_users',
        // Hearth health signals (NOV-127) — every one truthfully + reproducibly derivable from immutable rows.
        // (A signup-cohort RETENTION signal is deliberately omitted: it cannot be reconstructed for a past day
        // from the overwrite-only users.last_active_at, so per the "omit if not truthfully derivable" rule it is
        // a deferred follow-up that needs a per-day activity-history table — see ADR-0114.)
        'hearth_first_response_min', 'hearth_unanswered_pct', 'hearth_staff_actions',
    ];

    /**
     * The audit-log actions that count as staff CONTENT-moderation work (the "staff response load" signal). It is
     * an honest moderation set: workflow bookkeeping (report.assigned / report.unassigned — self-inflatable and
     * not content moderation) is excluded, and every content action that ships is included (stick, spam-clean,
     * merge, split, bulk).
     */
    private const MODERATION_ACTIONS = [
        'topic.locked', 'topic.unlocked', 'topic.pinned', 'topic.unpinned', 'topic.moved',
        'topic.deleted', 'topic.restored', 'topic.approved', 'topic.rejected', 'topic.announced', 'topic.unannounced',
        'topic.type.sticky', 'topic.type.normal', 'topic.merged', 'topic.split',
        'post.deleted', 'post.restored', 'post.approved', 'post.rejected',
        'wall.approved', 'wall.rejected', 'wall.deleted', 'spam.cleaned',
        'bulk.posts.deleted', 'bulk.topics.locked', 'bulk.topics.unlocked', 'bulk.topics.moved', 'bulk.topics.deleted',
        'report.resolved', 'report.dismissed',
        'ban.created', 'ban.lifted', 'warning.issued', 'moderator.assigned', 'moderator.revoked',
    ];

    public function rollup(Carbon $date): void
    {
        $start = $date->copy()->startOfDay();
        $end = $date->copy()->endOfDay();

        $response = $this->topicResponseStats($start, $end);

        $values = [
            'users_new' => $this->users(User::query()->whereBetween('created_at', [$start, $end]), 'analytics.users_new')->count(),
            'users_total' => $this->users(User::query()->where('created_at', '<=', $end), 'analytics.users_total')->count(),
            'topics_new' => Topic::query()->whereBetween('created_at', [$start, $end])->count(),
            'topics_total' => Topic::query()->where('created_at', '<=', $end)->count(),
            'posts_new' => Post::query()->whereBetween('created_at', [$start, $end])->count(),
            'posts_total' => Post::query()->where('created_at', '<=', $end)->count(),
            'active_users' => $this->users(User::query()->whereBetween('last_active_at', [$start, $end]), 'analytics.active_users')->count(),

            // Hearth signals — see class notes; all measured from IMMUTABLE rows AS OF end-of-day, so a re-roll or
            // a backfill of a past day reproduces the same figure (no live-aggregate reads).
            'hearth_first_response_min' => $response['first_response_min'],
            'hearth_unanswered_pct' => $response['unanswered_pct'],
            // Count of staff CONTENT-moderation actions recorded in the audit log this day (immutable rows).
            'hearth_staff_actions' => AuditLog::query()->whereIn('action', self::MODERATION_ACTIONS)->whereBetween('created_at', [$start, $end])->count(),
        ];

        foreach ($values as $key => $value) {
            DailyMetric::query()->updateOrCreate(
                ['metric_date' => $start->toDateString(), 'metric_key' => $key],
                ['value' => (int) $value],
            );
        }
    }

    /**
     * First-response + unanswered stats for topics CREATED in [start, end], measured AS OF end-of-day — only
     * replies that existed by `$end` count, so a re-roll or a backfill reproduces the same figure (everything is
     * derived from immutable `created_at`, never a live aggregate like reply_count). One grouped SQL aggregate
     * joins posts→topics and excludes each topic's OP via a column compare, so there is no per-id `IN` list to
     * hit a driver's bind-variable limit. `first_response` is the industry-standard first-response TIME (over
     * answered topics only — a never-answered topic has no first-response value); the paired unanswered % on the
     * same tile row discloses the never-answered share. Merge/split can move in a post that predates the topic
     * (a negative gap) — those are excluded from the average rather than clamped, so they never fabricate a
     * 0-minute "instant response".
     *
     * @return array{first_response_min:int, unanswered_pct:int}
     */
    private function topicResponseStats(Carbon $start, Carbon $end): array
    {
        $total = Topic::query()->whereBetween('created_at', [$start, $end])->count();
        if ($total === 0) {
            return ['first_response_min' => 0, 'unanswered_pct' => 0];
        }

        // One row per ANSWERED topic (created this day, with a non-OP reply that existed by end-of-day). Query
        // builder (stdClass rows), with explicit soft-delete filters to match reply_count's live-non-deleted set.
        // A topic with a null first_post_id yields no row (OP not distinguishable) → unanswered, never a 0-gap.
        $answered = DB::table('posts')
            ->join('topics', 'topics.id', '=', 'posts.topic_id')
            ->whereBetween('topics.created_at', [$start, $end])
            ->where('posts.created_at', '<=', $end)
            ->whereColumn('posts.id', '!=', 'topics.first_post_id')
            ->whereNull('posts.deleted_at')
            ->whereNull('topics.deleted_at')
            ->groupBy('posts.topic_id')
            ->selectRaw('MIN(posts.created_at) as first_reply_at, MIN(topics.created_at) as topic_created_at')
            ->get();

        $gaps = [];
        foreach ($answered as $row) {
            $gap = (Carbon::parse((string) $row->first_reply_at)->getTimestamp() - Carbon::parse((string) $row->topic_created_at)->getTimestamp()) / 60;
            if ($gap >= 0) {
                $gaps[] = $gap;
            }
        }

        return [
            'first_response_min' => $gaps === [] ? 0 : (int) round(array_sum($gaps) / count($gaps)),
            'unanswered_pct' => (int) round(($total - $answered->count()) / $total * 100),
        ];
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
