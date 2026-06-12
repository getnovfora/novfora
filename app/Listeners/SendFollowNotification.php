<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Listeners;

use App\Events\Followed;
use App\Models\User;
use App\Models\UserRelationship;
use App\Notifications\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Notify the followee when someone follows them (P2-M5) — the REAL emitter for the 'follow' event vocab
 * seated in M2 Half-A (NotificationController::EVENTS, the in-app/mail/digest renderers and the prefs rows
 * all exist; this wires the producer). QUEUED off the request path and drained by the cron queue worker
 * (ADR-0011), auto-discovered via the typed handle() signature — mirrors SendReactionNotification.
 *
 * The followee's IGNORE graph is honoured at DELIVERY time (mirrors SendPmNotification): someone who
 * ignores the follower keeps the follow edge (ignore hides the person, it does not forbid the follow) but
 * receives no notification about it. Notifier::send() then applies the per-event prefs + suppression.
 */
final class SendFollowNotification implements ShouldQueue
{
    use InteractsWithQueue;

    /** A follower/followee deleted before the queue drains = silently drop the job, never an exception. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(private readonly Notifier $notifier) {}

    public function handle(Followed $event): void
    {
        // Re-resolve both ends fresh — the cron-drained queue may run minutes later, after either account
        // changed or vanished (the cascade deletes notifications anyway; this avoids writing one at all).
        $follower = User::find($event->follower->getKey());
        $followee = User::find($event->followee->getKey());
        if (! $follower instanceof User || ! $followee instanceof User) {
            return;
        }

        // The followee ignores the follower → no notification (user_id = the ignorer, related_user_id =
        // the ignored — the same edge direction ConversationService/SendPmNotification consult).
        $ignored = UserRelationship::query()
            ->where('user_id', $followee->getKey())
            ->where('related_user_id', $follower->getKey())
            ->where('type', UserRelationship::TYPE_IGNORE)
            ->exists();
        if ($ignored) {
            return;
        }

        $this->notifier->send($followee, 'follow', $follower, [
            'url' => route('profiles.show', $follower->getKey()),
        ]);
    }
}
