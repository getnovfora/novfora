<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Forum;

use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use App\Support\ActorRank;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Merge one topic into another (P2-M4 ⚙). Every post of $source is re-parented to $target with a single raw
 * UPDATE — deliberately bypassing Post::syncAggregates (which would fire once per moved post: an N+1 write
 * storm AND a double-count, since each per-row recompute would see a half-moved set). The denormalised
 * counters are re-derived ONCE, authoritatively, after the move (TopicCounters). $source is NOT hard-deleted:
 * it is soft-deleted and stamped moved_to_topic_id, so its URL 301-redirects to $target forever
 * (TopicController::show) and no inbound link breaks. Authorization — topic.moderate on BOTH scopes plus the
 * actor-rank gate against the source author — is enforced HERE, not just in the UI, so the service is safe to
 * call from anywhere. The whole mutation is one transaction: a mid-merge failure commits nothing.
 */
final class MergeTopicsService
{
    public function __construct(private readonly TopicCounters $counters) {}

    /**
     * @throws TopicModerationException when the merge is invalid (unauthorized, out-ranked, same topic, or an
     *                                  endpoint is unapproved / trashed / already a redirect shell)
     */
    public function merge(Topic $source, Topic $target, User $actor): void
    {
        // A merge writes to BOTH topics, so authorize both scopes up front. Rank-gate the source author — the
        // merge dissolves their topic, the same authority a delete would require.
        if (! $actor->canDo('topic.moderate', $source->permissionScope())
            || ! $actor->canDo('topic.moderate', $target->permissionScope())) {
            throw TopicModerationException::notAuthorized();
        }
        if ($source->author && ! ActorRank::canActOn($actor, $source->author)) {
            throw TopicModerationException::outranked();
        }

        DB::transaction(function () use ($source, $target, $actor) {
            // Re-read both endpoints FOR UPDATE inside the txn so a concurrent merge/delete cannot race us
            // into moving posts into (or out of) a topic that changed underneath the gate.
            $src = Topic::withTrashed()->lockForUpdate()->find($source->getKey());
            $tgt = Topic::withTrashed()->lockForUpdate()->find($target->getKey());

            if ($src === null || $tgt === null || $src->getKey() === $tgt->getKey()) {
                throw TopicModerationException::sameTopic();
            }
            if ($src->trashed() || $tgt->trashed()
                || $src->approved_state !== 'approved' || $tgt->approved_state !== 'approved'
                || $src->moved_to_topic_id !== null || $tgt->moved_to_topic_id !== null) {
                throw TopicModerationException::invalidState();
            }

            $sourceForumId = (int) $src->forum_id;
            $targetForumId = (int) $tgt->forum_id;

            // (b) Re-parent every post in ONE statement — no per-row model events (no syncAggregates storm).
            // The source's positions (1..n) collide with the target's, so OFFSET them past the target's
            // current max: the source posts APPEND after the target's (the target keeps its opening post as
            // the anchor), relative order preserved, no position clash. $offset is an int — safe to inline.
            $offset = (int) Post::where('topic_id', $tgt->getKey())->max('position');
            DB::table('posts')->where('topic_id', $src->getKey())->update([
                'topic_id' => $tgt->getKey(),
                'position' => DB::raw('position + '.$offset),
            ]);

            // (c) Stamp the permanent redirect, then (d) soft-delete the now-empty source shell. The deleted
            // observer's topic_count -1 on the source forum is overwritten by the authoritative recompute below.
            $src->forceFill(['moved_to_topic_id' => $tgt->getKey(), 'status' => 'merged'])->saveQuietly();
            $src->delete();

            // (e) Authoritative recompute: the target gains the posts; both forums' counters are re-derived.
            $this->counters->recomputeTopic((int) $tgt->getKey());
            foreach (array_unique([$sourceForumId, $targetForumId]) as $forumId) {
                $this->counters->recomputeForum($forumId);
            }

            // (f) Audit (append-only). The actor is recorded in the 'by' field explicitly, so a non-web caller
            // still captures who (Audit::log's actor_id column falls back to auth()->id() — a pre-existing helper
            // limitation). Cross-forum merges are intentional (kickoff step e recomputes BOTH forums' counters).
            Audit::log('topic.merged', $src, ['into' => (int) $tgt->getKey(), 'by' => (int) $actor->getKey()]);
        });
    }
}
