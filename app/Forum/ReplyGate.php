<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Forum;

use App\Models\Forum;
use App\Models\Topic;
use App\Models\User;

/**
 * The single reply-authorization policy (NF-01, NOV-144). Every reply mutation — the Livewire composer (mount
 * AND submit), the REST API, the scheduled-publish cron — and the topic page's "can reply" affordance resolve
 * through here, so a thread-level restriction applied while a composer is open (or while a reply is queued)
 * holds at the moment of the write, and the surfaces cannot drift.
 *
 * The predicate re-derives, at mutation time, everything the topic page enforces for VIEWING plus the
 * posting rules, all at the THREAD scope (Topic::permissionScope()), never the parent forum's:
 *   1. the topic still exists and is not a moved/merged shadow;
 *   2. club content visibility (404 — no disclosure of a private club);
 *   3. forum.view at the thread scope (a thread-level NEVER on viewing also blocks replying);
 *   4. the moderation fence: a pending topic accepts replies only from its author or a topic.moderate holder
 *      (404, mirroring TopicController's fence);
 *   5. post.create at the thread scope;
 *   6. the locked-status gate (Topic::isReplyable());
 *   7. club participation (active membership or staff). Content visibility already passed at step 2, so a
 *      non-member of a club they can read gets an honest 403 (a private club already 404'd at step 2).
 *
 * PostService stays authorization-free by design (system callers such as the load-test seeder use it
 * directly); user-initiated reply paths MUST call this gate first.
 */
final class ReplyGate
{
    /** The viewer may reply. */
    public const ALLOWED = 'allowed';

    /** The viewer may not learn the topic is replyable (or exists) — callers answer 404. */
    public const HIDDEN = 'hidden';

    /** The viewer can see the topic but may not reply — callers answer 403. */
    public const DENIED = 'denied';

    /** @return self::ALLOWED|self::HIDDEN|self::DENIED */
    public function check(User $user, Topic $topic): string
    {
        if ($topic->trashed() || ! $topic->exists) {
            return self::HIDDEN;
        }

        $forum = $topic->forum;
        if (! $forum instanceof Forum || ! $forum->clubContentVisibleTo($user)) {
            return self::HIDDEN;
        }

        $scope = $topic->permissionScope();
        if (! $user->canDo('forum.view', $scope)) {
            return self::DENIED;
        }

        if ($topic->approved_state !== 'approved'
            && (int) $topic->user_id !== (int) $user->getKey()
            && ! $user->canDo('topic.moderate', $scope)) {
            return self::HIDDEN;
        }

        if ($topic->moved_to_topic_id !== null
            || ! $user->canDo('post.create', $scope)
            || ! $topic->isReplyable()
            || ! $forum->clubParticipationAllowed($user)) {
            return self::DENIED;
        }

        return self::ALLOWED;
    }

    public function allows(?User $user, Topic $topic): bool
    {
        return $user instanceof User && $this->check($user, $topic) === self::ALLOWED;
    }

    /** Abort the request unless the user may reply: 404 when the topic must stay undisclosed, else 403. */
    public function authorize(?User $user, Topic $topic): void
    {
        abort_unless($user instanceof User, 403);

        $verdict = $this->check($user, $topic);
        abort_if($verdict === self::HIDDEN, 404);
        abort_if($verdict === self::DENIED, 403);
    }
}
