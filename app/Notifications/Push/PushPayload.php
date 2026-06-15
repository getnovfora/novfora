<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Notifications\Push;

/**
 * Builds the Web Push message JSON from the SAME notification data the in-app/email channels use (Phase 4 ·
 * M3.2). Pure mapping — no I/O — so it is trivially testable. The service worker reads {title, body, url, tag}.
 */
final class PushPayload
{
    /**
     * @param  array{thread_id?:int, topic_title?:string, post_id?:int, url?:string}  $payload
     * @return array{title:string, body:string, url:string, tag:string}
     */
    public static function build(string $event, string $actorName, array $payload): array
    {
        $title = match ($event) {
            'reply' => 'New reply',
            'mention' => 'You were mentioned',
            'reaction' => 'New reaction',
            'pm.received' => 'New message',
            'follow' => 'New follower',
            'moderation' => 'Moderation update',
            'club' => 'Club update',
            default => 'Notification',
        };

        $topic = isset($payload['topic_title']) ? ' in “'.$payload['topic_title'].'”' : '';
        $body = match ($event) {
            'reply' => $actorName.' replied'.$topic,
            'mention' => $actorName.' mentioned you'.$topic,
            'reaction' => $actorName.' reacted to your post'.$topic,
            'pm.received' => $actorName.' sent you a message',
            'follow' => $actorName.' followed you',
            default => $actorName.' '.$event,
        };

        return [
            'title' => $title,
            'body' => $body,
            'url' => (string) ($payload['url'] ?? '/'),
            // Merge-by-thread so the OS coalesces multiple notifications for the same thread.
            'tag' => $event.':'.(string) ($payload['thread_id'] ?? '0'),
        ];
    }
}
