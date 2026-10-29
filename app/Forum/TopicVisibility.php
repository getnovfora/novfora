<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Forum;

use App\Models\AclEntry;
use App\Models\Ban;
use App\Models\Forum;
use App\Models\Topic;
use App\Models\User;
use App\Permissions\PermissionValue;
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
 * counted). Thread-level overrides are rare, so instead of resolving every row it fetches the few CANDIDATE
 * threads whose verdict could differ from the forum's (see resolveCandidates — filtered in SQL by the viewer's
 * holders, the decisive value and expiry) and confirms only those through the permission engine.
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

        return $user instanceof User && $user->exists
            && (self::isAuthor($user, $topic) || $user->canDo('topic.moderate', $scope));
    }

    /** The author check, null-safe: a guest (null key) never "authors" an author-less (user_id NULL) topic. */
    public static function isAuthor(User $user, Topic $topic): bool
    {
        return $user->exists && $topic->user_id !== null && (int) $topic->user_id === (int) $user->getKey();
    }

    /**
     * Constrain a topic query on ONE forum to what the viewer may see. The caller must already have checked
     * club visibility and forum.view at the forum scope (the board-level gate); this adds the moderation fence
     * and the per-thread view denials — both resolved at the THREAD scope, exactly like canView() and the topic
     * page, so a listing never shows a topic its own page would 404 (or hide one it would serve).
     *
     * @param  Builder<Topic>  $query
     */
    public function constrain(Builder $query, Forum $forum, ?User $user): void
    {
        $viewer = $user ?? User::guest();
        $member = $user instanceof User && $user->exists ? $user : null;

        if ($member !== null && $member->canDo('topic.moderate', $forum->permissionScope())) {
            // A forum moderator sees held topics — except threads where they are recused at the thread scope
            // (a thread NEVER on topic.moderate, or a thread ban), which fall back to the member fence.
            $recused = $this->resolveCandidates($forum, $member, 'topic.moderate', PermissionValue::Never, false);
            if ($recused !== []) {
                $query->where(fn ($q) => $q->where('approved_state', 'approved')
                    ->orWhere('user_id', $member->getKey())
                    ->orWhereNotIn('id', $recused));
            }
        } else {
            // Everyone else sees approved topics, their own held ones, and held ones in threads they moderate
            // through a thread-scoped grant.
            $threadModerated = $member === null ? []
                : $this->resolveCandidates($forum, $member, 'topic.moderate', PermissionValue::Allow, true);
            $query->where(function ($q) use ($member, $threadModerated) {
                $q->where('approved_state', 'approved');
                if ($member !== null) {
                    $q->orWhere('user_id', $member->getKey());
                }
                if ($threadModerated !== []) {
                    $q->orWhereIn('id', $threadModerated);
                }
            });
        }

        $denied = $this->resolveCandidates($forum, $viewer, 'forum.view', PermissionValue::Never, false);
        if ($denied !== []) {
            $query->whereNotIn('id', $denied);
        }
    }

    /**
     * Threads in this forum whose verdict for $permission can differ from the forum's, resolved through the
     * permission engine. The CALLER knows the forum-scope verdict; a thread can only flip it via an entry AT the
     * thread node held by this viewer (user or one of their groups) and still unexpired, or — for a DENY — via an
     * active thread-scoped ban. So only those candidates are fetched (bounded in SQL by holder, value and expiry)
     * and confirmed with canDo(): normally zero rows, never one resolution per listed topic.
     *
     * $value = Never → returns the candidates that resolve DENIED (the forum allowed; a thread NEVER/ban flips it).
     * $value = Allow → returns the candidates that resolve ALLOWED (the forum denied; a thread ALLOW may flip it).
     *
     * @return list<int>
     */
    private function resolveCandidates(Forum $forum, User $viewer, string $permission, PermissionValue $value, bool $wantAllowed): array
    {
        $topicIds = Topic::query()->select('id')->where('forum_id', $forum->getKey());
        $groupIds = $viewer->groupIds();

        $candidates = AclEntry::query()
            ->where('permission_key', $permission)
            ->where('scope_type', 'thread')
            ->where('value', $value->value)
            ->whereIn('scope_id', $topicIds)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(function ($q) use ($viewer, $groupIds) {
                $q->whereRaw('1 = 0');
                if ($viewer->exists) {
                    $q->orWhere(fn ($q2) => $q2->where('holder_type', 'user')->where('holder_id', $viewer->getKey()));
                }
                if ($groupIds !== []) {
                    $q->orWhere(fn ($q2) => $q2->where('holder_type', 'group')->whereIn('holder_id', $groupIds));
                }
            })
            ->pluck('scope_id');

        if ($value === PermissionValue::Never && $viewer->exists) {
            $candidates = $candidates->merge(Ban::query()
                ->where('user_id', $viewer->getKey())
                ->where('scope_type', 'thread')
                ->whereIn('scope_id', $topicIds)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->pluck('scope_id'));
        }

        return $candidates->map(fn ($id): int => (int) $id)->unique()
            ->filter(fn (int $id): bool => $viewer->canDo($permission, Scope::thread($id)) === $wantAllowed)
            ->values()->all();
    }
}
