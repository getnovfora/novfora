<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Notifications\Push\PushPayload;
use App\Notifications\Push\WebPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers a Web Push notification to ALL of a user's subscribed devices (Phase 4 · M3.2). QUEUED so it stays
 * off the hot request path and is drained by the baseline cron `queue:work` — no persistent worker. A
 * subscription the push service reports GONE (410/404) is pruned. A no-op when VAPID is not configured.
 */
final class SendPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /**
     * @param  array{thread_id?:int, topic_title?:string, post_id?:int, url?:string}  $payload
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $event,
        public readonly string $actorName,
        public readonly array $payload,
    ) {}

    public function handle(WebPushService $push): void
    {
        if (! $push->isConfigured()) {
            return; // push not set up — the in-app/email channels already delivered
        }

        $message = PushPayload::build($this->event, $this->actorName, $this->payload);

        PushSubscription::query()->where('user_id', $this->userId)->get()
            ->each(function (PushSubscription $subscription) use ($push, $message): void {
                if (! $push->send($subscription, $message)) {
                    $subscription->delete(); // gone — prune so we stop trying
                }
            });
    }
}
