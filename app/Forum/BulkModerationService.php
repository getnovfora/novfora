<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Forum;

use App\Models\Forum;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use App\Permissions\Scope;
use App\Support\ActorRank;
use App\Support\Audit;

/**
 * Cross-page bulk moderation (P2-M4 ◐). The defining rule is the RANK GUARD: every selected item is checked
 * with ActorRank::canActOn($actor, $item->author) before the action applies. An item the actor cannot act on
 * (a higher-ranked author) is SILENTLY SKIPPED — never actioned, never an error — and BOTH the applied and
 * skipped id sets are returned and audited. This is deliberately NOT transactional: partial success is the
 * design (skip the ineligible, act on the rest), the opposite of merge/split's all-or-nothing.
 *
 * Authors are eager-loaded once per call (whereIn + with('author')) so the rank check costs no per-row query;
 * the permission verdicts resolve through PermissionResolver's request memo, so repeated same-scope checks are
 * free. A null author (pseudonymised / deleted account) has no rank to out-rank → eligible. The forum
 * permission gate (post.delete.any / topic.moderate) is enforced HERE too, so a caller cannot bypass it.
 *
 * @phpstan-type BulkResult array{applied: list<int>, skipped: list<int>}
 */
final class BulkModerationService
{
    /**
     * Soft-delete the eligible posts among $postIds (gate: post.delete.any at each post's thread scope).
     *
     * @param  list<int>  $postIds
     * @return array{applied: list<int>, skipped: list<int>}
     */
    public function deletePosts(User $actor, array $postIds): array
    {
        [$eligible, $skipped] = $this->partitionPosts($actor, $postIds, 'post.delete.any');

        $applied = [];
        foreach ($eligible as $post) {
            $post->delete(); // soft-delete; the per-row syncAggregates observer keeps counters honest
            $applied[] = (int) $post->getKey();
        }

        $this->audit('bulk.posts.deleted', $actor, $applied, $skipped);

        return ['applied' => $applied, 'skipped' => $skipped];
    }

    /**
     * Lock (or unlock) the eligible topics among $topicIds (gate: topic.moderate at each topic's scope).
     *
     * @param  list<int>  $topicIds
     * @return array{applied: list<int>, skipped: list<int>}
     */
    public function lockTopics(User $actor, array $topicIds, bool $lock): array
    {
        [$eligible, $skipped] = $this->partitionTopics($actor, $topicIds);

        $applied = [];
        foreach ($eligible as $topic) {
            $topic->update(['status' => $lock ? 'locked' : 'open']);
            $applied[] = (int) $topic->getKey();
        }

        $this->audit($lock ? 'bulk.topics.locked' : 'bulk.topics.unlocked', $actor, $applied, $skipped);

        return ['applied' => $applied, 'skipped' => $skipped];
    }

    /**
     * Move the eligible topics among $topicIds into $targetForumId (gate: topic.moderate at BOTH the topic's
     * current scope AND the destination forum's scope — a mover must moderate where it lands).
     *
     * @param  list<int>  $topicIds
     * @return array{applied: list<int>, skipped: list<int>}
     */
    public function moveTopics(User $actor, array $topicIds, int $targetForumId): array
    {
        // Bind the destination gate to a REAL postable forum (type='forum') resolved from the actual model —
        // NOT a hardcoded Scope::forum() over a client-supplied id. `moveTarget` is an un-Locked, client-writable
        // bound prop, so a forged request could pass a CATEGORY id; checking against the real node prevents both
        // a wrong-scope verdict and re-parenting topics under a non-postable category container.
        $target = Forum::query()->where('type', 'forum')->find($targetForumId);
        $canModerateTarget = $target !== null && $actor->canDo('topic.moderate', $target->permissionScope());
        [$eligible, $skipped] = $this->partitionTopics($actor, $topicIds);

        $applied = [];
        foreach ($eligible as $topic) {
            if (! $canModerateTarget || (int) $topic->forum_id === $targetForumId) {
                $skipped[] = (int) $topic->getKey(); // cannot moderate destination, or it is a no-op move

                continue;
            }
            $topic->update(['forum_id' => $targetForumId]); // fires the topology AclVersion bump (Topic observer)
            $applied[] = (int) $topic->getKey();
        }

        $this->audit('bulk.topics.moved', $actor, $applied, $skipped, ['to' => $targetForumId]);

        return ['applied' => $applied, 'skipped' => $skipped];
    }

    /**
     * Soft-delete the eligible topics among $topicIds (gate: topic.moderate at each topic's scope).
     *
     * @param  list<int>  $topicIds
     * @return array{applied: list<int>, skipped: list<int>}
     */
    public function deleteTopics(User $actor, array $topicIds): array
    {
        [$eligible, $skipped] = $this->partitionTopics($actor, $topicIds);

        $applied = [];
        foreach ($eligible as $topic) {
            $topic->delete(); // soft-delete → recycle bin
            $applied[] = (int) $topic->getKey();
        }

        $this->audit('bulk.topics.deleted', $actor, $applied, $skipped);

        return ['applied' => $applied, 'skipped' => $skipped];
    }

    /**
     * Partition posts into [eligible models, skipped ids]: the actor must hold $permission at the post's
     * thread scope AND out-rank the post's author. Ids that resolve to no post are dropped (not "skipped").
     *
     * @param  list<int>  $postIds
     * @return array{0: list<Post>, 1: list<int>}
     */
    private function partitionPosts(User $actor, array $postIds, string $permission): array
    {
        $postIds = array_values(array_unique(array_map('intval', $postIds)));
        $eligible = [];
        $skipped = [];

        foreach (Post::with('author')->whereIn('id', $postIds)->get() as $post) {
            $allowed = $actor->canDo($permission, Scope::thread((int) $post->topic_id))
                && ($post->author === null || ActorRank::canActOn($actor, $post->author));
            $allowed ? $eligible[] = $post : $skipped[] = (int) $post->getKey();
        }

        return [$eligible, $skipped];
    }

    /**
     * Partition topics into [eligible models, skipped ids]: the actor must hold topic.moderate at the topic's
     * scope AND out-rank the topic's author.
     *
     * @param  list<int>  $topicIds
     * @return array{0: list<Topic>, 1: list<int>}
     */
    private function partitionTopics(User $actor, array $topicIds): array
    {
        $topicIds = array_values(array_unique(array_map('intval', $topicIds)));
        $eligible = [];
        $skipped = [];

        foreach (Topic::with('author')->whereIn('id', $topicIds)->get() as $topic) {
            $allowed = $actor->canDo('topic.moderate', $topic->permissionScope())
                && ($topic->author === null || ActorRank::canActOn($actor, $topic->author));
            $allowed ? $eligible[] = $topic : $skipped[] = (int) $topic->getKey();
        }

        return [$eligible, $skipped];
    }

    /**
     * @param  list<int>  $applied
     * @param  list<int>  $skipped
     * @param  array<string,mixed>  $extra
     */
    private function audit(string $action, User $actor, array $applied, array $skipped, array $extra = []): void
    {
        Audit::log($action, null, $extra + [
            'applied' => $applied,
            'skipped' => $skipped,
            'by' => (int) $actor->getKey(),
        ]);
    }
}
