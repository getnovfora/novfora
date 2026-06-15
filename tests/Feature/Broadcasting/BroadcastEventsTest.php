<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Events\MessageSent;
use App\Events\NotificationReceived;
use App\Events\PostCreated;
use App\Forum\PostService;
use App\Models\Conversation;
use App\Models\Forum;
use App\Models\Message;
use App\Models\Post;
use App\Notifications\Notifier;
use App\Services\Tier\ServiceTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| Phase 4 · M4.2 — domain events broadcast on PRIVATE channels, but only on the enhanced tier (broadcastWhen),
| carrying ids only (never private bodies). Channel AUTHORIZATION is covered by ChannelAuthorizationTest.
| Not validated against a real Reverb (ADR-0061).
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

/** Make the Broadcast capability read as Enhanced (reverb) without installing/contacting a real service. */
function enhanceBroadcast(): void
{
    app()->instance(ServiceTier::class, new ServiceTier([]));
    config(['broadcasting.default' => 'reverb']);
}

function eventForum(): Forum
{
    return Forum::create(['slug' => 'gen', 'title' => 'Gen', 'type' => 'forum']);
}

it('broadcasts a reply on the thread private channel, gated on the enhanced tier', function () {
    $topic = app(PostService::class)->createTopic(Users::inGroups(['members', 'tl2']), eventForum(), 'T', 'tiptap_json', Content::doc('hello world'));
    $post = Post::where('topic_id', $topic->id)->firstOrFail();
    $event = new PostCreated($post);

    expect($event->broadcastOn()[0]->name)->toBe('private-thread.'.$topic->id);
    expect($event->broadcastWith())->toMatchArray(['topic_id' => (int) $topic->id, 'post_id' => (int) $post->id]);
    expect($event->broadcastWhen())->toBeFalse(); // baseline: no broadcast

    enhanceBroadcast();
    expect($event->broadcastWhen())->toBeTrue(); // enhanced + approved
});

it('does not broadcast a pending reply even on the enhanced tier', function () {
    enhanceBroadcast();
    $topic = app(PostService::class)->createTopic(Users::inGroups(['moderators']), eventForum(), 'T', 'tiptap_json', Content::doc('op'));
    $reply = app(PostService::class)->reply(Users::inGroups(['members', 'tl0']), $topic, 'tiptap_json', Content::doc('held reply')); // TL0 → pending

    expect((new PostCreated($reply))->broadcastWhen())->toBeFalse();
});

it('broadcasts a PM on the conversation private channel, gated on the enhanced tier', function () {
    $convo = Conversation::factory()->create();
    $actor = Users::inGroups(['members', 'tl2'], ['email' => 'pm-actor@bcast.test']);
    $message = Message::factory()->create(['conversation_id' => $convo->id, 'user_id' => $actor->id]);
    $event = new MessageSent($actor, $convo, $message);

    expect($event->broadcastOn()[0]->name)->toBe('private-conversation.'.$convo->id);
    expect($event->broadcastWith())->toMatchArray(['conversation_id' => (int) $convo->id]);
    // The body is never on the wire — only ids.
    expect($event->broadcastWith())->not->toHaveKey('body');
    expect($event->broadcastWhen())->toBeFalse();

    enhanceBroadcast();
    expect($event->broadcastWhen())->toBeTrue();
});

it('broadcasts a notification ping only on the enhanced tier', function () {
    $event = new NotificationReceived(7, 3);

    expect($event->broadcastOn()[0]->name)->toBe('private-notifications.7');
    expect($event->broadcastWith())->toBe(['unread' => 3]);
    expect($event->broadcastWhen())->toBeFalse();

    enhanceBroadcast();
    expect($event->broadcastWhen())->toBeTrue();
});

it('the Notifier emits a realtime ping on the enhanced tier but not on the baseline', function () {
    $recipient = Users::inGroups(['members', 'tl1'], ['email' => 'rcpt@bcast.test']);
    $actor = Users::inGroups(['members', 'tl2'], ['email' => 'actor@bcast.test']);

    // Baseline → no ping (the bell stays on its poll).
    Event::fake([NotificationReceived::class]);
    app(Notifier::class)->send($recipient, 'reply', $actor, ['thread_id' => 1, 'topic_title' => 'X']);
    Event::assertNotDispatched(NotificationReceived::class);

    // Enhanced → a ping for the recipient.
    enhanceBroadcast();
    Event::fake([NotificationReceived::class]);
    app(Notifier::class)->send($recipient, 'mention', $actor, ['thread_id' => 2, 'topic_title' => 'Y']);
    Event::assertDispatched(NotificationReceived::class, fn (NotificationReceived $e) => $e->userId === (int) $recipient->id);
});

it('registers the broadcasting auth endpoint', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes());

    expect($routes->contains(fn ($r) => $r->uri() === 'broadcasting/auth'))->toBeTrue();
});
