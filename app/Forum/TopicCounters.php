<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Forum;

use App\Models\Forum;
use App\Models\Post;
use App\Models\Topic;

/**
 * Authoritative topic + forum counter recompute for bulk post moves (P2-M4 merge/split). Bulk-moving posts
 * with a raw UPDATE deliberately bypasses Post::syncAggregates — the per-row observer that would otherwise
 * fire once per moved post (an N+1 write storm) AND double-count (each per-row recompute would see a
 * half-moved set). The denormalised counters are instead re-derived ONCE here, after the move. Every value
 * is a COUNT/MAX over the live post set — never an incremental ±delta — so a recompute is drift-free and
 * self-healing: it cannot be skewed by a missed or duplicated event, and an authoritative SET OVERWRITES any
 * observer delta that fired during the structural change (e.g. the source topic's soft-delete -1).
 *
 * Counter definitions (recorded in DECISIONS): topic_count = non-trashed topics in the forum; post_count =
 * non-trashed posts whose (non-trashed) topic is in the forum — posts under a soft-deleted topic do not
 * count. saveQuietly throughout: these run after the structural change inside the caller's transaction and
 * must not re-fire the topic/forum observers (AclVersion bumps, nested recounts).
 *
 * Not final by design: the merge/split rollback tests inject a throwing double to prove the whole mutation
 * commits atomically (a recompute failure must roll back the post move).
 */
class TopicCounters
{
    /**
     * Re-derive a topic's post pointers + reply_count from its (non-trashed) posts — exactly the topic half
     * of Post::syncAggregates: first = lowest (position, id); last = newest (created_at, id); reply_count =
     * posts - 1, never negative. withTrashed on the lookup so a soft-deleted topic can still be addressed
     * (merge never recomputes its emptied source shell, but split's source stays live).
     */
    public function recomputeTopic(int $topicId): void
    {
        $topic = Topic::withTrashed()->find($topicId);
        if (! $topic instanceof Topic) {
            return;
        }

        $base = Post::where('topic_id', $topicId);
        $total = (clone $base)->count();
        $first = (clone $base)->orderBy('position')->orderBy('id')->first(['id']);
        $last = (clone $base)->orderByDesc('created_at')->orderByDesc('id')->first(['id', 'user_id', 'created_at']);

        $topic->forceFill([
            'first_post_id' => $first?->getKey(),
            'last_post_id' => $last?->getKey(),
            'last_post_user_id' => $last?->user_id,
            'last_posted_at' => $last?->created_at,
            'reply_count' => max(0, $total - 1),
        ])->saveQuietly();
    }

    /**
     * Re-derive a forum's denormalised topic_count + post_count + "last post" pointers from its live content.
     * The "last post" follows the forum's most-recently-active topic — the same definition the index row uses.
     * An authoritative SET (not ±delta), so it overwrites any observer delta that fired during the move.
     */
    public function recomputeForum(int $forumId): void
    {
        $forum = Forum::withTrashed()->find($forumId);
        if (! $forum instanceof Forum) {
            return;
        }

        $topicIds = Topic::where('forum_id', $forumId)->pluck('id');
        $postCount = $topicIds->isEmpty() ? 0 : Post::whereIn('topic_id', $topicIds)->count();

        $activeTopic = Topic::where('forum_id', $forumId)
            ->whereNotNull('last_posted_at')
            ->orderByDesc('last_posted_at')
            ->first(['id', 'last_post_id', 'last_posted_at']);

        $forum->forceFill([
            'topic_count' => $topicIds->count(),
            'post_count' => $postCount,
            'last_post_id' => $activeTopic?->last_post_id,
            'last_topic_id' => $activeTopic?->getKey(),
            'last_posted_at' => $activeTopic?->last_posted_at,
        ])->saveQuietly();
    }
}
