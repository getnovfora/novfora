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
use Illuminate\Support\Str;

/**
 * Split selected posts out of a topic into a brand-new one (P2-M4 ⚙). The opening post can NEVER be moved —
 * that would orphan the source — so it is explicitly rejected. The selected posts are re-parented with a
 * single raw UPDATE (bypassing Post::syncAggregates exactly as merge does), then BOTH topics' counters are
 * re-derived authoritatively (TopicCounters). Unlike BULK moderation, split is an explicit, all-or-nothing
 * action: if ANY selected post is by an author the actor cannot act on, the WHOLE split is refused (not
 * silently skipped) — the actor chose those exact posts. Authorization (topic.moderate + the per-post rank
 * gate) is enforced here. One transaction: a mid-split failure commits nothing.
 */
final class SplitTopicService
{
    public function __construct(private readonly TopicCounters $counters) {}

    /**
     * @param  list<int>  $postIds
     * @return Topic the newly created topic (so the caller can redirect to it)
     *
     * @throws TopicModerationException
     */
    public function split(Topic $source, array $postIds, string $newTitle, User $actor): Topic
    {
        if (! $actor->canDo('topic.moderate', $source->permissionScope())) {
            throw TopicModerationException::notAuthorized();
        }

        $postIds = array_values(array_unique(array_map('intval', $postIds)));
        $newTitle = trim($newTitle);
        if ($postIds === [] || $newTitle === '') {
            throw TopicModerationException::nothingToSplit();
        }

        return DB::transaction(function () use ($source, $postIds, $newTitle, $actor): Topic {
            $src = Topic::lockForUpdate()->find($source->getKey());
            if (! $src instanceof Topic || $src->moved_to_topic_id !== null) {
                throw TopicModerationException::invalidState();
            }

            // Every selected post must belong to the source; load authors for the rank gate in the same query.
            $posts = Post::with('author')->whereIn('id', $postIds)->where('topic_id', $src->getKey())->get();
            if ($posts->count() !== count($postIds)) {
                throw TopicModerationException::postsNotInTopic();
            }

            // The OP anchors the source and cannot be split away. first_post_id is authoritative; fall back to
            // the lowest-position post if the pointer is somehow null.
            $opId = (int) ($src->first_post_id
                ?? Post::where('topic_id', $src->getKey())->orderBy('position')->orderBy('id')->value('id'));
            if (in_array($opId, $postIds, true)) {
                throw TopicModerationException::cannotMoveOpeningPost();
            }

            // Per-post rank gate — refuse the WHOLE split if any chosen post is by a higher-ranked author.
            foreach ($posts as $post) {
                if ($post->author && ! ActorRank::canActOn($actor, $post->author)) {
                    throw TopicModerationException::outranked();
                }
            }

            // (b) New topic in the SAME forum, owned by the actor, approved.
            $newTopic = Topic::create([
                'forum_id' => $src->forum_id,
                'user_id' => $actor->getKey(),
                'title' => $newTitle,
                'slug' => $this->slug($newTitle),
                'type' => 'normal',
                'status' => 'open',
                'approved_state' => 'approved',
            ]);

            // (c) Re-parent the selected posts in ONE statement (no per-row syncAggregates).
            DB::table('posts')->whereIn('id', $postIds)->update(['topic_id' => $newTopic->getKey()]);

            // (d) Authoritative recompute on BOTH topics + the (shared) forum. The new topic's created-observer
            // topic_count +1 is overwritten by the authoritative forum recompute.
            $this->counters->recomputeTopic((int) $src->getKey());
            $this->counters->recomputeTopic((int) $newTopic->getKey());
            $this->counters->recomputeForum((int) $src->forum_id);

            // (e) Audit.
            Audit::log('topic.split', $src, [
                'new' => (int) $newTopic->getKey(),
                'posts' => count($postIds),
                'by' => (int) $actor->getKey(),
            ]);

            return $newTopic->refresh();
        });
    }

    private function slug(string $title): string
    {
        return Str::slug(Str::limit($title, 60, '')) ?: 'topic';
    }
}
