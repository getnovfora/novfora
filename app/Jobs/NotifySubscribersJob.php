<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Jobs;

use App\Forum\SubscriptionService;
use App\Models\Forum;
use App\Models\Post;
use App\Models\User;
use App\Notifications\Notifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * The BOUNDED + QUEUED subscription fan-out (M2, ADR-0097 — apex). On a new approved reply (topic followers)
 * or a new topic (forum followers), notify the subscribers — but NEVER as a synchronous unbounded loop in the
 * request thread (the P5.1 @mention lesson). The recipient set is:
 *   - capped at subscriptions.fanout_cap (logged when hit — no silent truncation),
 *   - chunked (100 at a time), drained by the cron queue worker,
 *   - filtered per-recipient by the SAME visibility gate the inline reply/mention notifier uses
 *     (clubContentVisibleTo + forum.view) — a follower who can't see the forum is never notified,
 *   - and run through Notifier::send(), which applies each user's notification prefs + digest cadence.
 */
class NotifySubscribersJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @param  list<int>  $excludeUserIds  already-notified inline (author, OP author, @mentioned) */
    public function __construct(
        public int $postId,
        public string $subscribableType,
        public int $subscribableId,
        public array $excludeUserIds = [],
    ) {}

    public function handle(SubscriptionService $subscriptions, Notifier $notifier): void
    {
        $post = Post::find($this->postId);
        if (! $post instanceof Post || $post->approved_state !== 'approved') {
            return; // gone, or no longer approved
        }

        $topic = $post->topic;
        if ($topic === null) {
            return;
        }
        $forum = $topic->forum_id ? Forum::find($topic->forum_id) : null;
        $actor = $post->user_id ? User::find($post->user_id) : null;
        if (! $actor instanceof User || ! $forum instanceof Forum) {
            // FAIL CLOSED: a topic whose forum can't be loaded (soft-deleted / gone by the time this queued job
            // runs) must NOT fan out — the per-recipient visibility fence below needs a live forum, so an
            // un-loadable forum means "nobody can see it" (apex-review defense-in-depth, ADR-0097).
            return;
        }

        $ids = $subscriptions->subscriberIds($this->subscribableType, $this->subscribableId, $this->excludeUserIds);
        if ($ids === []) {
            return;
        }

        $cap = max(1, (int) config('novfora.subscriptions.fanout_cap', 2000));
        if (count($ids) > $cap) {
            Log::warning('subscription fan-out capped — some followers were not notified for this event', [
                'post_id' => $this->postId, 'subscribers' => count($ids), 'cap' => $cap,
            ]);
            $ids = array_slice($ids, 0, $cap);
        }

        $payload = [
            'thread_id' => (int) $topic->id,
            'topic_title' => $topic->title,
            'post_id' => (int) $post->id,
            'url' => route('topics.show', $topic->id),
        ];

        foreach (array_chunk($ids, 100) as $chunk) {
            User::query()->whereIn('id', $chunk)->get()->each(function (User $u) use ($forum, $actor, $payload, $notifier): void {
                // Privacy fence: never notify a follower who can't see the forum (private club / restricted forum).
                if (! $forum->clubContentVisibleTo($u) || ! $u->canDo('forum.view', $forum->permissionScope())) {
                    return;
                }
                $notifier->send($u, 'subscription', $actor, $payload); // prefs + digest applied inside send()
            });
        }
    }
}
