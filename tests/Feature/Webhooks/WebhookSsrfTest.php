<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\WebhookDelivery;
use App\Support\Ssrf\SsrfException;
use App\Webhooks\WebhookDeliveryRunner;
use App\Webhooks\WebhookManager;
use App\Webhooks\WebhookUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

/*
| Outbound-webhook SSRF / DNS-rebinding battery (ADR-0033 hardening) — PERMANENT. An admin supplies the
| endpoint URL, and delivery happens LATER than the create-time check, so a public-looking hostname can be
| re-pointed at an internal address before it is hit. The guard must: validate at delivery, resolve EVERY
| record, block if ANY is internal, pin the connection to a validated IP, and re-validate every redirect hop.
| The resolver is injected so DNS is deterministic without real network.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

// ── create/update-time check (cheap, no DNS) ──────────────────────────────────────────────────────
it('refuses an SSRF-prone URL at config time and accepts a public one', function () {
    $guard = new WebhookUrlGuard;
    foreach ([
        'http://127.0.0.1/x', 'http://localhost/x', 'http://10.0.0.5/x', 'http://169.254.169.254/latest/meta-data',
        'http://[::1]/x', 'http://100.64.0.1/x', 'ftp://host/x', 'https://box.local/x', 'https://svc.internal/x',
    ] as $url) {
        expect(fn () => $guard->assertConfigUrl($url))->toThrow(InvalidArgumentException::class);
    }
    // A public hostname is accepted at config time (its A records are validated at delivery).
    $guard->assertConfigUrl('https://hooks.example.test/in');
    expect(true)->toBeTrue();
});

// ── delivery-time DNS rebinding ───────────────────────────────────────────────────────────────────
it('blocks delivery when the host RESOLVES to a private IP (rebinding), sending nothing', function () {
    Http::fake();
    $guard = new WebhookUrlGuard(fn () => ['10.0.0.5']); // public at save, private at delivery

    expect(fn () => $guard->deliver('https://rebind.example/in', 'body', []))->toThrow(SsrfException::class);
    Http::assertNothingSent();
});

it('blocks a delivery aimed at the cloud metadata endpoint', function () {
    Http::fake();
    $guard = new WebhookUrlGuard(fn () => ['169.254.169.254']);

    expect(fn () => $guard->deliver('https://metadata.example/latest/meta-data/', 'body', []))
        ->toThrow(SsrfException::class);
    Http::assertNothingSent();
});

it('blocks when ANY resolved record is internal (mixed A records)', function () {
    Http::fake();
    $guard = new WebhookUrlGuard(fn () => ['203.0.113.10', '127.0.0.1']);

    expect(fn () => $guard->deliver('https://mixed.example/in', 'body', []))->toThrow(SsrfException::class);
    Http::assertNothingSent();
});

it('fails closed when the host resolves to no addresses', function () {
    Http::fake();
    $guard = new WebhookUrlGuard(fn () => []);

    expect(fn () => $guard->deliver('https://no-records.example/in', 'body', []))->toThrow(SsrfException::class);
    Http::assertNothingSent();
});

// ── redirect re-validation ──────────────────────────────────────────────────────────────────────
it('blocks a redirect to an internal address (re-validates every hop)', function () {
    Http::fake([
        'https://public.example/*' => Http::response('', 302, ['Location' => 'https://internal.example/secret']),
        'https://internal.example/*' => Http::response('LEAKED', 200),
    ]);
    $guard = new WebhookUrlGuard(fn (string $host): array => $host === 'internal.example' ? ['10.0.0.5'] : ['203.0.113.10']);

    expect(fn () => $guard->deliver('https://public.example/start', 'body', []))->toThrow(SsrfException::class);
});

it('rejects a redirect with a missing/unsafe Location (response-splitting hygiene)', function () {
    Http::fake([
        // A 302 whose Location is empty triggers the unsafe-location guard (the transport forbids raw CRLF,
        // and the CRLF branch itself is regression-tested via UrlSafety::locationIsUnsafe in the oEmbed suite).
        'https://public.example/*' => Http::response('', 302, []),
    ]);
    $guard = new WebhookUrlGuard(fn () => ['203.0.113.10']);

    expect(fn () => $guard->deliver('https://public.example/start', 'body', []))->toThrow(SsrfException::class);
});

// ── happy path + dev escape ───────────────────────────────────────────────────────────────────────
it('delivers to a public-resolving host and returns the response', function () {
    Http::fake(['*' => Http::response('ok', 200)]);
    $guard = new WebhookUrlGuard(fn () => ['203.0.113.10']);

    $response = $guard->deliver('https://ok.example/in', 'the-body', ['X-NovFora-Signature' => 'sig']);

    expect($response->status())->toBe(200);
    Http::assertSent(fn ($request) => $request->url() === 'https://ok.example/in' && $request->body() === 'the-body');
});

it('skips validation entirely when allow_private is on (local dev only)', function () {
    config(['novfora.webhooks.allow_private' => true]);
    Http::fake(['*' => Http::response('', 204)]);
    $guard = new WebhookUrlGuard(fn () => ['10.0.0.5']); // would be blocked, but the dev escape bypasses it

    expect($guard->deliver('http://127.0.0.1:9000/in', 'body', [])->status())->toBe(204);
});

// ── integration: the runner schedules a retry on an SSRF block, delivers nothing ───────────────────
it('the delivery runner retries (does not deliver) a webhook whose host rebinds internal', function () {
    Http::fake();
    // A public-looking hostname passes the create-time check…
    $this->app->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn () => ['10.0.0.5']));
    $endpoint = app(WebhookManager::class)->create('https://looked-public.example/in', ['post.created']);

    // …but at delivery the guard resolves it to a private IP and refuses.
    $delivery = WebhookDelivery::create([
        'webhook_endpoint_id' => $endpoint->id, 'event' => 'post.created',
        'payload' => ['event' => 'post.created', 'data' => ['post_id' => 1]],
        'status' => 'pending', 'attempts' => 0, 'max_attempts' => 3, 'next_attempt_at' => now(),
    ]);

    app(WebhookDeliveryRunner::class)->runPending();

    expect($delivery->fresh()->status)->toBe('pending')                 // not delivered
        ->and($delivery->fresh()->attempts)->toBe(1)                    // one attempt counted
        ->and($delivery->fresh()->last_error)->toContain('blocked address')
        ->and($delivery->fresh()->next_attempt_at)->not->toBeNull();    // backoff scheduled
    Http::assertNothingSent();
});
