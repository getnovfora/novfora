<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Events;

use App\Services\Tier\Capability;
use App\Services\Tier\ServiceTier;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A new in-app notification landed for a recipient (Phase 4 · M4.2). Broadcast on the recipient's PRIVATE
 * channel so the notification bell can update instantly on the enhanced tier; on the baseline it does not
 * broadcast at all (broadcastWhen) and the bell stays on its Livewire poll. The payload is a minimal ping —
 * the unread count — carrying no notification content, so nothing sensitive crosses the wire.
 */
final class NotificationReceived implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(
        public readonly int $userId,
        public readonly int $unreadCount,
    ) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('notifications.'.$this->userId)];
    }

    /** Only broadcast when a realtime broadcaster is actually configured (enhanced tier). */
    public function broadcastWhen(): bool
    {
        return app(ServiceTier::class)->isEnhanced(Capability::Broadcast);
    }

    /** @return array<string, int> */
    public function broadcastWith(): array
    {
        return ['unread' => $this->unreadCount];
    }

    public function broadcastAs(): string
    {
        return 'notification.received';
    }
}
