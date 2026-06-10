<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Listeners;

use App\Events\Reacted;
use App\Models\Topic;
use App\Models\User;
use App\Notifications\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * P2-M2 — turns the P2-M1 {@see Reacted} domain event into a `reaction` notification to the POST AUTHOR. This
 * is the live emitter wired END-TO-END through the {@see Notifier}, so it honours per-event×channel preferences
 * AND digest cadence exactly like reply/mention: an immediate-cadence author gets a mail now, a daily/weekly
 * author has it staged into their digest, an unsubscribed author gets none. (The score-weight → reputation
 * consumer remains held for P2-M3, amendment #4 — this listener is only about notifications.)
 *
 * AUTO-DISCOVERED: Laravel event-discovery registers this from its handle(Reacted) signature — do NOT also
 * Event::listen() it (that double-registers → double-notifies). QUEUED (ShouldQueue): the notification work is
 * pushed to the DB queue (cron-drained on baseline) so it stays OFF the hot react/toggle action path (which
 * holds a tight query budget) — the same best-effort, within-a-cron-interval contract as all baseline mail.
 * `$deleteWhenMissingModels` quietly drops the job if the post/author was deleted before it ran.
 *
 * Reacted fires on add/change only (never on toggle-off) and AFTER the reaction commits, so the author is never
 * notified of a reaction that rolled back. The Notifier skips self-notification (reacting to your own post).
 */
final class SendReactionNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $deleteWhenMissingModels = true;

    public function __construct(private readonly Notifier $notifier) {}

    public function handle(Reacted $event): void
    {
        $post = $event->post;

        $author = $post->user_id ? User::find($post->user_id) : null;
        if (! $author instanceof User) {
            return; // an authorless (e.g. deleted-user) post has no one to notify
        }

        $topic = $post->topic_id ? Topic::find($post->topic_id) : null;

        $this->notifier->send($author, 'reaction', $event->actor, [
            'thread_id' => (int) $post->topic_id,
            'topic_title' => $topic?->title,
            'post_id' => (int) $post->getKey(),
            'url' => $topic instanceof Topic ? route('topics.show', $topic->getKey()) : null,
            'reaction_type' => $event->type,
        ]);
    }
}
