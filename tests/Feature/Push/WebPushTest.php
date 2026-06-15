<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// Web Push (Phase 4 · M3.2). NOTE: actual delivery to a live push service is NOT validated here (no browser
// subscription / push endpoint in this environment). These tests cover the subscription lifecycle, the opt-in
// gating, the payload build, the send/prune job logic (with a mocked sender), and the no-push fallback.

use App\Jobs\SendPushNotification;
use App\Models\NotificationPreference;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\Notifier;
use App\Notifications\Push\PushPayload;
use App\Notifications\Push\WebPushService;
use App\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function pushUser(string $email): User
{
    return Users::inGroups(['members', 'tl1'], ['email' => $email]);
}

function subscribe(User $user, string $endpoint = 'https://push.example.test/abc'): PushSubscription
{
    return PushSubscription::create([
        'user_id' => $user->id,
        'endpoint' => $endpoint,
        'endpoint_hash' => PushSubscription::hashEndpoint($endpoint),
        'public_key' => 'p256dh-key',
        'auth_token' => 'auth-key',
        'content_encoding' => 'aes128gcm',
    ]);
}

function configureVapid(): void
{
    $s = app(Settings::class);
    $s->set('push.vapid_public_key', 'BPUBLICKEY');
    $s->set('push.vapid_private_key', 'PRIVATEKEY');
    $s->set('push.vapid_subject', 'mailto:admin@novfora.test');
}

// ── Subscription lifecycle ───────────────────────────────────────────────────────────────────────────────

it('lets a user subscribe a device and stores one row per endpoint', function () {
    $user = pushUser('sub@push.test');

    $this->actingAs($user)->postJson(route('push.subscribe'), [
        'endpoint' => 'https://push.example.test/dev1',
        'keys' => ['p256dh' => 'pk', 'auth' => 'ak'],
    ])->assertStatus(201);

    expect(PushSubscription::where('user_id', $user->id)->count())->toBe(1);

    // Re-subscribing the same endpoint refreshes, never duplicates.
    $this->actingAs($user)->postJson(route('push.subscribe'), [
        'endpoint' => 'https://push.example.test/dev1',
        'keys' => ['p256dh' => 'pk2', 'auth' => 'ak2'],
    ])->assertStatus(201);

    expect(PushSubscription::where('user_id', $user->id)->count())->toBe(1);
    expect(PushSubscription::where('user_id', $user->id)->first()->public_key)->toBe('pk2');
});

it('lets a user unsubscribe a device', function () {
    $user = pushUser('unsub@push.test');
    subscribe($user, 'https://push.example.test/gone');

    $this->actingAs($user)->postJson(route('push.unsubscribe'), ['endpoint' => 'https://push.example.test/gone'])->assertOk();

    expect(PushSubscription::where('user_id', $user->id)->count())->toBe(0);
});

it('requires authentication to subscribe', function () {
    $this->postJson(route('push.subscribe'), ['endpoint' => 'https://x.test/a', 'keys' => ['p256dh' => 'p', 'auth' => 'a']])
        ->assertUnauthorized();
});

// ── VAPID public-key endpoint ────────────────────────────────────────────────────────────────────────────

it('reports push disabled until VAPID is configured', function () {
    $user = pushUser('pk-off@push.test');

    $this->actingAs($user)->getJson(route('push.public-key'))->assertOk()->assertJson(['enabled' => false]);

    configureVapid();
    $this->actingAs($user)->getJson(route('push.public-key'))->assertOk()->assertJson(['enabled' => true, 'publicKey' => 'BPUBLICKEY']);
});

// ── Opt-in gating via the Notifier ───────────────────────────────────────────────────────────────────────

it('dispatches a push job only when the recipient has a subscription', function () {
    Queue::fake();
    $actor = pushUser('actor@push.test');
    $subscribed = pushUser('has-sub@push.test');
    $unsubscribed = pushUser('no-sub@push.test');
    subscribe($subscribed);

    app(Notifier::class)->send($subscribed, 'reply', $actor, ['thread_id' => 1, 'url' => '/t/1']);
    app(Notifier::class)->send($unsubscribed, 'reply', $actor, ['thread_id' => 1, 'url' => '/t/1']);

    Queue::assertPushed(SendPushNotification::class, 1);
    Queue::assertPushed(SendPushNotification::class, fn (SendPushNotification $j) => $j->userId === (int) $subscribed->id);
});

it('still delivers the in-app notification when push is unavailable (no-push fallback)', function () {
    Queue::fake();
    $actor = pushUser('actor2@push.test');
    $recipient = pushUser('inapp@push.test'); // no subscription

    app(Notifier::class)->send($recipient, 'reply', $actor, ['thread_id' => 7, 'url' => '/t/7']);

    Queue::assertNotPushed(SendPushNotification::class);
    expect(DatabaseNotification::where('notifiable_id', $recipient->id)->where('type', 'reply')->exists())->toBeTrue();
});

it('does not push when the recipient disabled the push channel for the event', function () {
    Queue::fake();
    $actor = pushUser('actor3@push.test');
    $recipient = pushUser('optout@push.test');
    subscribe($recipient);
    NotificationPreference::create(['user_id' => $recipient->id, 'event_type' => 'reply', 'channel' => 'push', 'enabled' => false]);

    app(Notifier::class)->send($recipient, 'reply', $actor, ['thread_id' => 1, 'url' => '/t/1']);

    Queue::assertNotPushed(SendPushNotification::class);
});

// ── Payload build ────────────────────────────────────────────────────────────────────────────────────────

it('builds a push payload from the notification data', function () {
    $p = PushPayload::build('reply', 'Alice', ['topic_title' => 'Hello world', 'url' => '/topics/9', 'thread_id' => 9]);

    expect($p['title'])->toBe('New reply');
    expect($p['body'])->toContain('Alice replied');
    expect($p['body'])->toContain('Hello world');
    expect($p['url'])->toBe('/topics/9');
    expect($p['tag'])->toBe('reply:9');
});

// ── Send + prune job (mocked sender; no real delivery) ───────────────────────────────────────────────────

it('sends to every subscription and prunes the ones the push service reports gone', function () {
    $user = pushUser('prune@push.test');
    $live = subscribe($user, 'https://push.example.test/live');
    $dead = subscribe($user, 'https://push.example.test/dead');

    $mock = Mockery::mock(WebPushService::class);
    $mock->shouldReceive('isConfigured')->andReturnTrue();
    $mock->shouldReceive('send')->andReturnUsing(fn (PushSubscription $s) => $s->endpoint !== 'https://push.example.test/dead');

    (new SendPushNotification($user->id, 'reply', 'Bob', ['thread_id' => 1, 'url' => '/t/1']))->handle($mock);

    expect(PushSubscription::find($live->id))->not->toBeNull();
    expect(PushSubscription::find($dead->id))->toBeNull(); // pruned
});

it('the push job is a no-op when VAPID is not configured', function () {
    $user = pushUser('noop@push.test');
    subscribe($user);
    $mock = Mockery::mock(WebPushService::class);
    $mock->shouldReceive('isConfigured')->andReturnFalse();
    $mock->shouldNotReceive('send');

    (new SendPushNotification($user->id, 'reply', 'Bob', []))->handle($mock);

    expect(PushSubscription::where('user_id', $user->id)->count())->toBe(1); // nothing pruned
});
