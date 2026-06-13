<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Webhooks;

use App\Events\Followed;
use App\Events\MessageSent;
use App\Events\PostCreated;
use App\Events\ReputationAwarded;
use App\Events\TopicCreated;
use Illuminate\Events\Dispatcher;

/**
 * Bridges the core domain events to the outbound webhook dispatcher (ADR-0033). Registered via
 * Event::subscribe in AppServiceProvider. Payloads carry IDs + the minimum useful fields — never PII or message
 * bodies — so a delivery can't leak private content to a third party.
 */
final class WebhookEventSubscriber
{
    public function __construct(private readonly WebhookDispatcher $dispatcher) {}

    public function handlePostCreated(PostCreated $event): void
    {
        $this->dispatcher->dispatch('post.created', [
            'post_id' => $event->post->getKey(),
            'topic_id' => (int) $event->post->topic_id,
            'author_id' => $event->post->user_id === null ? null : (int) $event->post->user_id,
        ]);
    }

    public function handleTopicCreated(TopicCreated $event): void
    {
        $this->dispatcher->dispatch('topic.created', [
            'topic_id' => $event->topic->getKey(),
            'forum_id' => (int) $event->topic->forum_id,
            'author_id' => $event->topic->user_id === null ? null : (int) $event->topic->user_id,
        ]);
    }

    public function handleFollowed(Followed $event): void
    {
        $this->dispatcher->dispatch('user.followed', [
            'follower_id' => $event->follower->getKey(),
            'followee_id' => $event->followee->getKey(),
        ]);
    }

    public function handleReputationAwarded(ReputationAwarded $event): void
    {
        $this->dispatcher->dispatch('reputation.awarded', [
            'recipient_id' => $event->recipient->getKey(),
        ]);
    }

    public function handleMessageSent(MessageSent $event): void
    {
        // IDs only — never the message body (private content stays private).
        $this->dispatcher->dispatch('message.sent', [
            'conversation_id' => $event->conversation->getKey(),
            'message_id' => $event->message->getKey(),
            'sender_id' => $event->actor->getKey(),
        ]);
    }

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            PostCreated::class => 'handlePostCreated',
            TopicCreated::class => 'handleTopicCreated',
            Followed::class => 'handleFollowed',
            ReputationAwarded::class => 'handleReputationAwarded',
            MessageSent::class => 'handleMessageSent',
        ];
    }
}
