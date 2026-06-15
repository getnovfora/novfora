<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Events;

use App\Models\Post;
use App\Services\Tier\Capability;
use App\Services\Tier\ServiceTier;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A reply was created and committed (P2-M3). Dispatched by PostService::reply AFTER the write commits and
 * only for an APPROVED reply. The opening post of a topic is NOT a reply and is never dispatched here (it is
 * covered by TopicCreated), so the feed shows "created a topic" once, not also "replied".
 *
 * Phase 4 · M4.2: it also broadcasts on the thread's PRIVATE channel so an open thread can live-append the
 * reply on the enhanced tier. Only subscribers who pass the thread channel authorization (forum.view + the
 * club gate, routes/channels.php) receive it. The payload carries only ids — the client refetches the
 * rendered reply — so a private-club body never crosses the wire. On the baseline it does not broadcast.
 */
final class PostCreated implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(public readonly Post $post) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('thread.'.$this->post->topic_id)];
    }

    public function broadcastWhen(): bool
    {
        return $this->post->approved_state === 'approved'
            && app(ServiceTier::class)->isEnhanced(Capability::Broadcast);
    }

    /** @return array<string, int> */
    public function broadcastWith(): array
    {
        return [
            'post_id' => (int) $this->post->getKey(),
            'topic_id' => (int) $this->post->topic_id,
            'user_id' => (int) $this->post->user_id,
        ];
    }

    public function broadcastAs(): string
    {
        return 'post.created';
    }
}
