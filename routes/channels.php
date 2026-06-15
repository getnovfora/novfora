<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Broadcasting\ChannelAuthorizer;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels (Phase 4 · M4.2 — APEX)
|--------------------------------------------------------------------------
|
| Authorization for every PRIVATE realtime channel. Each callback is a thin
| delegate over App\Broadcasting\ChannelAuthorizer so the websocket boundary
| resolves through the EXACT same permission-engine + club-visibility +
| participant checks the HTTP surfaces use — they can never drift. A callback
| returning false (or a non-int placeholder) denies the subscription.
|
| These run on EVERY tier (the auth endpoint is registered regardless of the
| broadcaster), so a private-club / PM / hidden-thread leak is impossible even
| if the broadcaster itself is misconfigured.
|
*/

Broadcast::channel('notifications.{userId}', function (User $user, int $userId) {
    return app(ChannelAuthorizer::class)->ownsNotificationStream($user, $userId);
});

Broadcast::channel('thread.{topicId}', function (User $user, int $topicId) {
    return app(ChannelAuthorizer::class)->canViewThread($user, $topicId);
});

Broadcast::channel('conversation.{conversationId}', function (User $user, int $conversationId) {
    return app(ChannelAuthorizer::class)->canViewConversation($user, $conversationId);
});
