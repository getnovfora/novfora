<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

/**
 * Participant-only authorization for private conversations (P2-M2 Half-B). PMs live OUTSIDE the forum scope
 * tree, so access is not an ACL-scope decision (the Gate::before hook only routes Scope-typed args to the
 * resolver — a Conversation arg falls through to this policy). A non-participant gets a hard 403 on read /
 * reply / invite — never a data leak. `left_at` makes a participant inactive (soft-leave): they no longer
 * read or reply. `can_invite` gates who may grow the conversation. Auto-discovered (Conversation →
 * ConversationPolicy). The pm.send NEVER gate (TL0) and rate/cap/ignore controls are enforced separately in
 * ConversationService at send time — this policy is purely "are you in this thread?".
 */
class ConversationPolicy
{
    /** Read the conversation and its messages. */
    public function view(User $user, Conversation $conversation): bool
    {
        return $this->isActiveParticipant($user, $conversation);
    }

    /** Post a reply into the conversation (pm.send + rate are re-checked by the service at send time). */
    public function reply(User $user, Conversation $conversation): bool
    {
        return $this->isActiveParticipant($user, $conversation);
    }

    /** Add another participant — an active participant who additionally holds the can_invite flag. */
    public function invite(User $user, Conversation $conversation): bool
    {
        $row = $conversation->participantRows()
            ->where('user_id', $user->getKey())
            ->whereNull('left_at')
            ->first();

        return $row !== null && (bool) $row->can_invite;
    }

    private function isActiveParticipant(User $user, Conversation $conversation): bool
    {
        return $conversation->participantRows()
            ->where('user_id', $user->getKey())
            ->whereNull('left_at')
            ->exists();
    }
}
