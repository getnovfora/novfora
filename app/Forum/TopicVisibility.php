<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Forum;

use App\Models\AclEntry;
use App\Models\Ban;
use App\Models\Forum;
use App\Models\Topic;
use App\Models\User;
use App\Permissions\Scope;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Topics visible to this viewer" (NF-03, NOV-146) — one definition shared by the web board listing, the REST
 * API, and single-topic reads, so the surfaces cannot drift. A topic is visible when:
 *   1. its forum's club content is visible and the viewer holds forum.view at the THREAD scope — a thread-level
 *      NEVER (or a thread-scoped ban) hides that one topic even inside a readable forum;
 *   2. it is not soft-deleted;
 *   3. it passes the moderation fence: a pending topic is visible only to its author or a topic.moderate holder.
 *
 * The listing form is query-aware, so filtering happens BEFORE pagination and counts (no hidden row is ever
 * counted). Thread-level denials are rare, so instead of resolving every row it finds the few CANDIDATE threads
 * that carry a thread-scoped forum.view entry or a thread-scoped ban, resolves only those through the
 * permission engine, and excludes the denied ids — two bounded queries, never per-row.
 */
final class TopicVisibility
{
    /** Whether the viewer may see this one topic (the TopicController@show fences, minus the redirects). */
    public function canView(?User $user, Topic $topic): bool
    {
        $viewer = $user ?? User::guest();
        $forum = $topic->forum;
        $scope = $topic->permissionScope();

        if ($topic->trashed() || ! $forum instanceof Forum || ! $forum->clubContentVisibleTo($user)
            || ! $viewer->canDo('forum.view', $scope)) {
            return false;
        }

        if ($topic->approved_state === 'approved') {
            return true;
        }

        return $user instanceof User
            && ((int) $topic->user_id === (int) $user->getKey() || $user->canDo('topic.moderate', $scope));
    }

    /**
     * Constrain a topic query on ONE forum to what the viewer may see. The caller must already have checked
     * club visibility and forum.view at the forum scope (the board-level gate); this adds the moderation fence
     * and the per-thread denials.
     *
     * @param  Builder<Topic>  $query
     */
    public function constrain(Builder $query, Forum $forum, ?User $user): void
    {
        $canModerate = $user?->canDo('topic.moderate', $forum->permissionScope()) ?? false;
        if (! $canModerate) {
            $query->where(function ($q) use ($user) {
                $q->where('approved_state', 'approved');
                if ($user instanceof User) {
                    $q->orWhere('user_id', $user->getKey());
                }
            });
        }

        $denied = $this->deniedThreadIds($forum, $user);
        if ($denied !== []) {
            $query->whereNotIn('id', $denied);
        }
    }

    /**
     * Thread ids in this forum the viewer may NOT view although the forum itself is readable. Only threads with a
     * thread-scoped forum.view ACL entry, or a thread-scoped ban on this viewer, can differ from the forum's
     * verdict, so only those candidates are resolved.
     *
     * @return list<int>
     */
    private function deniedThreadIds(Forum $forum, ?User $user): array
    {
        $topicIds = Topic::query()->select('id')->where('forum_id', $forum->getKey());

        $candidates = AclEntry::query()
            ->where('permission_key', 'forum.view')
            ->where('scope_type', 'thread')
            ->whereIn('scope_id', $topicIds)
            ->pluck('scope_id');

        if ($user instanceof User && $user->exists) {
            $candidates = $candidates->merge(Ban::query()
                ->where('user_id', $user->getKey())
                ->where('scope_type', 'thread')
                ->whereIn('scope_id', $topicIds)
                ->pluck('scope_id'));
        }

        $viewer = $user ?? User::guest();

        return $candidates->map(fn ($id): int => (int) $id)->unique()
            ->reject(fn (int $id): bool => $viewer->canDo('forum.view', Scope::thread($id)))
            ->values()->all();
    }
}
