<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Forum;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Whether a stored notification's referenced club topic is still visible to the recipient.
 *
 * A reply/mention/reaction notification SNAPSHOTS the club topic title at send time. That snapshot must be
 * re-gated against the recipient's CURRENT club access EVERYWHERE it is shown — the notifications page AND
 * the bell dropdown — or a member who left a club, or a club gone private, would leak the topic's title
 * (M1.5 / the private-club no-leak rule). Mirrors BookmarkController's re-check of a saved reference.
 *
 * Extracted from NotificationController so the dropdown (P3 polish) reuses the SAME gate, not a copy.
 */
final class NotificationVisibility
{
    public static function visibleTo(DatabaseNotification $notification, User $user): bool
    {
        if (! in_array($notification->type, ['reply', 'mention', 'reaction'], true)) {
            return true; // non-topic notifications carry no club content
        }

        $threadId = $notification->data['thread_id'] ?? null;
        if (! is_numeric($threadId)) {
            return true;
        }

        $topic = Topic::find((int) $threadId);
        if (! $topic instanceof Topic) {
            return true; // a deleted topic has no club content left to leak
        }

        $forum = $topic->forum;

        return ! $forum instanceof Forum || $forum->clubContentVisibleTo($user);
    }
}
