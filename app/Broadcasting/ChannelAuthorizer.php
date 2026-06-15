<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Broadcasting;

use App\Models\Conversation;
use App\Models\Forum;
use App\Models\Topic;
use App\Models\User;
use App\Permissions\Scope;

/**
 * The single source of truth for WHO may subscribe to a realtime channel (Phase 4 · M4.2, APEX).
 *
 * Broadcast authorization is a security boundary every bit as load-bearing as an HTTP gate: a user may only
 * receive realtime events for content they can ALREADY view. These checks therefore resolve through the SAME
 * mechanisms the HTTP surfaces use — the permission engine (`forum.view`) + the query-level club visibility
 * gate (`Forum::clubContentVisibleTo`) for forum content, and the participant-only ConversationPolicy for
 * PMs (which live outside the scope tree). routes/channels.php is a thin delegate over this class so the
 * websocket and HTTP enforcement can never drift, and so the no-leak guarantee is unit-testable in isolation.
 *
 * Every method FAILS CLOSED: a missing/soft-deleted topic, conversation, or club resolves to "denied".
 */
final class ChannelAuthorizer
{
    /** notifications.{userId} — strictly the owner. No staff override: a notification stream is private. */
    public function ownsNotificationStream(User $user, int $userId): bool
    {
        return (int) $user->getKey() === $userId;
    }

    /**
     * thread.{topicId} — the viewer must be able to SEE the thread: `forum.view` at the thread scope AND the
     * club content gate (a private-club thread must never leak to a non-member, even though every member
     * inherits global `forum.view=ALLOW` on this public-by-default board — see ADR-0047/0051).
     */
    public function canViewThread(User $user, int $topicId): bool
    {
        $topic = Topic::query()->with('forum')->find($topicId);
        if ($topic === null) {
            return false; // unknown/deleted thread → no channel
        }

        if (! $user->canDo('forum.view', Scope::thread($topicId))) {
            return false;
        }

        $forum = $topic->forum;

        return $forum instanceof Forum && $forum->clubContentVisibleTo($user);
    }

    /**
     * conversation.{conversationId} — an ACTIVE participant only. PMs are outside the ACL scope tree, so this
     * is the ConversationPolicy decision (participant rows with no `left_at`); a non-participant or a
     * soft-left participant is denied. No staff override — staff are not silent readers of private messages.
     */
    public function canViewConversation(User $user, int $conversationId): bool
    {
        $conversation = Conversation::find($conversationId);

        return $conversation !== null && $user->can('view', $conversation);
    }
}
