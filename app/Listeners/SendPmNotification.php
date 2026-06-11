<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Listeners;

use App\Events\MessageSent;
use App\Models\User;
use App\Notifications\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * P2-M2 Half-B — turns a {@see MessageSent} domain event into a `pm.received` notification to every OTHER
 * active participant. The pm.received vocabulary + mail/in-app/digest renderers + prefs row were seeded by M2
 * Half-A; this is its first LIVE emitter, wired end-to-end through the {@see Notifier}, so each recipient's
 * per-event×channel preference AND digest cadence are honoured independently (immediate → mail now;
 * daily/weekly → staged into the digest; off/unsubscribed → none). Forced-absence: a dead mail transport never
 * surfaces — the Notifier swallows it and the in-app DB notification still lands.
 *
 * AUTO-DISCOVERED from handle(MessageSent) — do NOT also Event::listen() it (that double-notifies). QUEUED so
 * the fan-out stays off the send path and drains on the cron queue (ADR-0011). $deleteWhenMissingModels drops
 * the job if the conversation/actor was deleted before it ran. Passing the conversation id as `thread_id` makes
 * a second unread message in the same conversation MERGE into one notification rather than stacking. The
 * Notifier's own self-skip also covers the actor; we exclude them here to save a wasted send + query.
 */
final class SendPmNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $deleteWhenMissingModels = true;

    public function __construct(private readonly Notifier $notifier) {}

    public function handle(MessageSent $event): void
    {
        $conversation = $event->conversation;
        $actor = $event->actor;

        $recipientIds = $conversation->participantRows()
            ->whereNull('left_at')
            ->where('user_id', '!=', $actor->getKey())
            ->pluck('user_id');

        if ($recipientIds->isEmpty()) {
            return;
        }

        $payload = [
            'thread_id' => (int) $conversation->getKey(),   // conversation id → merge repeat unread PMs into one notification
            'topic_title' => $conversation->subject,        // may be null; the mail subject falls back gracefully
            'url' => url('/messages/'.$conversation->getKey()),
        ];

        foreach ($recipientIds as $userId) {
            $recipient = User::find($userId);
            if ($recipient instanceof User) {
                $this->notifier->send($recipient, 'pm.received', $actor, $payload);
            }
        }
    }
}
