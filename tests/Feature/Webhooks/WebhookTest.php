<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\Forum;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Webhooks\WebhookDeliveryRunner;
use App\Webhooks\WebhookManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\Users;

/*
| Outbound webhooks (ADR-0033). The boundary pins: the SSRF guard refuses private targets; a subscribed event
| enqueues a delivery (and an unsubscribed one doesn't); the cron runner POSTs with a CORRECT HMAC signature
| (the same scheme the inbound verifier checks); a failure retries with backoff and gives up after max attempts.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function endpoint(array $events = ['post.created']): WebhookEndpoint
{
    return app(WebhookManager::class)->create('https://hooks.example.test/in', $events);
}

it('refuses an SSRF-prone webhook URL (loopback / private / non-http)', function () {
    $manager = app(WebhookManager::class);
    foreach (['http://127.0.0.1/x', 'http://localhost/x', 'http://10.0.0.5/x', 'http://169.254.1.1/x', 'ftp://host/x', 'https://box.local/x'] as $url) {
        expect(fn () => $manager->create($url, ['post.created']))->toThrow(InvalidArgumentException::class);
    }
    // A public https URL is accepted.
    expect($manager->create('https://hooks.example.test/in', ['post.created'])->url)->toBe('https://hooks.example.test/in');
});

it('enqueues a delivery for a subscribed event and not for an unsubscribed one', function () {
    $subscribed = endpoint(['post.created']);
    $unsubscribed = endpoint(['message.sent']); // a post/topic event must not produce a delivery here

    $author = Users::inGroups(['members', 'tl1']);
    $forum = Forum::create(['slug' => 'wh', 'title' => 'WH', 'type' => 'forum']);
    $topic = app(PostService::class)->createTopic($author, $forum, 'Hi', 'markdown', ['source' => 'op']);
    app(PostService::class)->reply($author, $topic, 'markdown', ['source' => 'a reply']);

    expect(WebhookDelivery::where('webhook_endpoint_id', $subscribed->id)->where('event', 'post.created')->where('status', 'pending')->count())->toBe(1)
        ->and(WebhookDelivery::where('webhook_endpoint_id', $unsubscribed->id)->count())->toBe(0);
});

it('delivers a pending webhook with a verifiable HMAC signature', function () {
    Http::fake(['*' => Http::response('', 200)]);
    $endpoint = endpoint(['post.created']);
    $secret = $endpoint->secret; // the cast decrypts the at-rest secret
    $delivery = WebhookDelivery::create([
        'webhook_endpoint_id' => $endpoint->id, 'event' => 'post.created',
        'payload' => ['event' => 'post.created', 'data' => ['post_id' => 1]],
        'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => now(),
    ]);

    expect(app(WebhookDeliveryRunner::class)->runPending())->toBe(1);

    Http::assertSent(function ($request) use ($secret): bool {
        $ts = $request->header('X-NovFora-Timestamp')[0] ?? '';
        $sig = $request->header('X-NovFora-Signature')[0] ?? '';
        $expected = hash_hmac('sha256', "{$ts}.{$request->body()}", $secret);

        return $request->url() === 'https://hooks.example.test/in' && $ts !== '' && hash_equals($expected, $sig);
    });
    expect($delivery->fresh()->status)->toBe('delivered')
        ->and($delivery->fresh()->response_status)->toBe(200);
});

it('retries with backoff on a failed delivery and fails after max attempts', function () {
    Http::fake(['*' => Http::response('nope', 500)]);
    $endpoint = endpoint(['post.created']);
    $delivery = WebhookDelivery::create([
        'webhook_endpoint_id' => $endpoint->id, 'event' => 'post.created', 'payload' => ['x' => 1],
        'status' => 'pending', 'attempts' => 0, 'max_attempts' => 3, 'next_attempt_at' => now(),
    ]);

    app(WebhookDeliveryRunner::class)->runPending();
    expect($delivery->fresh()->status)->toBe('pending')          // not given up yet
        ->and($delivery->fresh()->attempts)->toBe(1)
        ->and($delivery->fresh()->next_attempt_at)->not->toBeNull(); // backoff scheduled

    // Force the next two attempts to be due (a raw UPDATE, so no stale-model attribute is rewritten), then
    // exhaust them.
    WebhookDelivery::whereKey($delivery->id)->update(['next_attempt_at' => now()->subMinute()]);
    app(WebhookDeliveryRunner::class)->runPending();
    WebhookDelivery::whereKey($delivery->id)->update(['next_attempt_at' => now()->subMinute()]);
    app(WebhookDeliveryRunner::class)->runPending();

    expect($delivery->fresh()->status)->toBe('failed')
        ->and($delivery->fresh()->attempts)->toBe(3);
});

it('skips a delivery whose endpoint was deactivated', function () {
    Http::fake();
    $endpoint = endpoint(['post.created']);
    $delivery = WebhookDelivery::create([
        'webhook_endpoint_id' => $endpoint->id, 'event' => 'post.created', 'payload' => ['x' => 1],
        'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => now(),
    ]);
    $endpoint->update(['is_active' => false]);

    app(WebhookDeliveryRunner::class)->runPending();
    expect($delivery->fresh()->status)->toBe('failed');
    Http::assertNothingSent();
});
