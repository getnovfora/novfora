<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Events;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A message was sent into a conversation (P2-M2 Half-B). Dispatched by ConversationService AFTER the message
 * commits, so notification fan-out (SendPmNotification → every other active participant) stays off the send
 * path and drains on the queue (ADR-0011). Mirrors {@see Reacted}: the model references let the listener
 * resolve recipients without a re-query.
 */
final class MessageSent
{
    use Dispatchable;

    public function __construct(
        public readonly User $actor,
        public readonly Conversation $conversation,
        public readonly Message $message,
    ) {}
}
