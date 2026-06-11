<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Messaging;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use App\Models\UserRelationship;
use Illuminate\Support\Facades\DB;

/**
 * The private-messaging slice of the account-deletion cascade (ADR-0025, Opus xhigh). This is the seam the
 * broader account-deletion flow invokes for PMs; it implements the binding PM contract in ONE transaction:
 *   • the deleted user's AUTHORED messages are PSEUDONYMISED (user_id → NULL, body retained) so the thread
 *     stays coherent for the remaining participants — they render as "[Deleted]", exactly like a
 *     pseudonymised post;
 *   • the user's conversation_user PARTICIPANT rows are HARD-deleted;
 *   • a conversation is PURGED only once NO participant row remains (≥1 remaining → the thread survives);
 *   • conversations the user STARTED keep their thread but have created_by anonymised (NULL);
 *   • the user's user_relationships edges (ignore/follow — as actor OR target) are HARD-deleted.
 *
 * MUST run BEFORE the users row is removed. `messages.user_id` and `conversations.created_by` carry NO database
 * FK (the "anonymisable author" pattern, like posts.user_id), so they MUST be nulled in application code — a
 * raw users-row delete would leave them pointing at a gone id, never NULL. The conversation_user and
 * user_relationships FKs are ON DELETE CASCADE, so a later users delete is a harmless no-op on already-purged
 * rows; deleting them explicitly here lets us compute the purge set before the account vanishes.
 */
final class PmAccountCascade
{
    public function purge(User $user): void
    {
        $userId = (int) $user->getKey();

        DB::transaction(function () use ($userId) {
            // Anonymise the author of every message + every conversation the user started (no FK → app-layer).
            Message::where('user_id', $userId)->update(['user_id' => null]);
            Conversation::where('created_by', $userId)->update(['created_by' => null]);

            // Hard-delete the participant rows; capture their conversations first so empties can be purged.
            $affected = ConversationParticipant::where('user_id', $userId)
                ->pluck('conversation_id')->map(fn ($id): int => (int) $id)->unique();
            ConversationParticipant::where('user_id', $userId)->delete();

            foreach ($affected as $conversationId) {
                if (ConversationParticipant::where('conversation_id', $conversationId)->count() === 0) {
                    // No participant remains → purge the thread (its messages cascade via the FK).
                    Conversation::whereKey($conversationId)->delete();
                }
            }

            // Relationship edges are the user's own metadata — hard-delete on BOTH endpoints (like reactions).
            UserRelationship::where('user_id', $userId)
                ->orWhere('related_user_id', $userId)
                ->delete();
        });
    }
}
