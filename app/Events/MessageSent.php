<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Events;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Tier\Capability;
use App\Services\Tier\ServiceTier;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A message was sent into a conversation (P2-M2 Half-B). Dispatched by ConversationService AFTER the message
 * commits, so notification fan-out (SendPmNotification → every other active participant) stays off the send
 * path and drains on the queue (ADR-0011). Mirrors {@see Reacted}: the model references let the listener
 * resolve recipients without a re-query.
 *
 * Phase 4 · M4.2: it also broadcasts on the conversation's PRIVATE channel so an open PM thread updates live
 * on the enhanced tier. Only ACTIVE participants pass the conversation channel authorization
 * (routes/channels.php → ChannelAuthorizer::canViewConversation), so a non-participant can never receive a
 * private message over the socket. The payload carries only ids — never the message body. Baseline: no broadcast.
 */
final class MessageSent implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(
        public readonly User $actor,
        public readonly Conversation $conversation,
        public readonly Message $message,
    ) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversation.'.$this->conversation->getKey())];
    }

    public function broadcastWhen(): bool
    {
        return app(ServiceTier::class)->isEnhanced(Capability::Broadcast);
    }

    /** @return array<string, int> */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => (int) $this->conversation->getKey(),
            'message_id' => (int) $this->message->getKey(),
            'actor_id' => (int) $this->actor->getKey(),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }
}
