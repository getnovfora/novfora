<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Maintenance;

use App\Forum\TopicCounters;
use App\Models\Forum;
use App\Models\Topic;
use Illuminate\Support\Facades\DB;

/**
 * Drift-repair for the denormalised forum / topic / user counters (U16 / NOV-114). The live deltas
 * (`Post::booted` for `users.post_count` + forum/topic aggregates; `TopicCounters` in merge/split) keep these
 * accurate in normal operation; this is the operator's self-heal for the rare drift — an interrupted job, a
 * manual DB edit, a historical import. It SETs authoritative values (COUNT/MAX via `TopicCounters`), so it is
 * idempotent (re-running over a consistent board changes nothing) and chunked so it is safe on the
 * cron-drained baseline. Exposed via `novfora:forums:recompute-counters` and the ACP Maintenance page (there
 * as a queued job so it never blocks the request).
 */
final class CounterRebuildService
{
    public function __construct(private readonly TopicCounters $counters) {}

    /**
     * Recompute every forum + topic counter and the user post-counts.
     *
     * @return array{forums:int, topics:int, users:int} the number of rows recomputed
     */
    public function rebuildAll(int $chunk = 500): array
    {
        $chunk = max(1, $chunk);
        $topics = 0;
        $forums = 0;

        // TOPICS FIRST: recomputeForum() derives the forum's "last post" pointer by copying its newest topic's
        // DENORMALISED last_post_id/last_posted_at — the exact fields recomputeTopic() repairs. Repairing topics
        // first means the forum reads corrected values, so one pass converges (forums-first would heal the forum
        // from stale topic pointers and need a second run).
        Topic::query()->orderBy('id')->chunkById($chunk, function ($rows) use (&$topics): void {
            foreach ($rows as $topic) {
                $this->counters->recomputeTopic((int) $topic->id);
                $topics++;
            }
        });

        Forum::query()->orderBy('id')->chunkById($chunk, function ($rows) use (&$forums): void {
            foreach ($rows as $forum) {
                $this->counters->recomputeForum((int) $forum->id);
                $forums++;
            }
        });

        return ['forums' => $forums, 'topics' => $topics, 'users' => $this->rebuildUserPostCounts()];
    }

    /**
     * Self-heal `users.post_count` from the live (non-deleted) posts — the one counter with no recompute
     * service (only the one-off backfill migration + the live ±1 delta). Portable correlated subquery
     * (MySQL/MariaDB, PostgreSQL, SQLite), matching `forums.post_count`'s live set (`deleted_at IS NULL`).
     *
     * @return int rows affected
     */
    public function rebuildUserPostCounts(): int
    {
        return DB::update(
            'UPDATE users SET post_count = ('
            .'SELECT COUNT(*) FROM posts WHERE posts.user_id = users.id AND posts.deleted_at IS NULL'
            .')'
        );
    }
}
