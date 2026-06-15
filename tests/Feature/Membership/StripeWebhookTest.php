<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\MembershipTier;
use App\Models\MemberSubscription;
use App\Permissions\PermissionResolver;
use App\Permissions\Scope;
use App\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Users;

/*
| Phase 4 · M5.3 (APEX untrusted-input) — the Stripe webhook is unauthenticated and receives untrusted bytes;
| trust is the HMAC, never reachability. Dormant (404) until enabled; fail-closed on oversize/forgery/malformed;
| grants idempotently on a signed checkout.session.completed. Synthetic signed events — NOT a real Stripe.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

const STRIPE_WH_SECRET = 'whsec_test_secret';

function enableStripeWebhook(): void
{
    $s = app(Settings::class);
    $s->set('payments.stripe.secret_key', 'sk_test_x');
    $s->set('payments.stripe.webhook_secret', STRIPE_WH_SECRET);
    $s->set('payments.stripe.enabled', true);
}

/** @return array{0:string,1:string} [rawBody, Stripe-Signature header] */
function stripeSigned(array $payload, string $secret = STRIPE_WH_SECRET): array
{
    $raw = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
    $t = now()->getTimestamp();
    $v1 = hash_hmac('sha256', "{$t}.{$raw}", $secret);

    return [$raw, "t={$t},v1={$v1}"];
}

function postStripeWebhook($test, string $raw, string $sig)
{
    return $test->call('POST', '/webhooks/stripe', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => $sig,
        'CONTENT_TYPE' => 'application/json',
    ], $raw);
}

function completedEvent(int $userId, int $tierId, string $sessionId = 'cs_test_1'): array
{
    return [
        'id' => 'evt_'.$sessionId,
        'type' => 'checkout.session.completed',
        'data' => ['object' => ['id' => $sessionId, 'metadata' => ['user_id' => $userId, 'tier_id' => $tierId]]],
    ];
}

function stripeTier(): MembershipTier
{
    return MembershipTier::create(['name' => 'Gold', 'slug' => 'gold-'.bin2hex(random_bytes(3)), 'price_cents' => 500, 'currency' => 'USD', 'interval' => 'monthly', 'perks' => ['tier.ad_free'], 'is_active' => true]);
}

// ── Dormant until enabled ────────────────────────────────────────────────────────────────────────────────

it('404s the webhook while Stripe is disabled (dormant by default)', function () {
    [$raw, $sig] = stripeSigned(completedEvent(1, 1));

    postStripeWebhook($this, $raw, $sig)->assertNotFound();
});

// ── Fail-closed on forgery / oversize / malformed ────────────────────────────────────────────────────────

it('rejects a forged signature with 401 and grants nothing', function () {
    enableStripeWebhook();
    $user = Users::inGroups(['members', 'tl1'], ['email' => 'forge@pay.test']);
    $tier = stripeTier();
    [$raw] = stripeSigned(completedEvent((int) $user->id, (int) $tier->id));

    $this->call('POST', '/webhooks/stripe', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => 't='.now()->getTimestamp().',v1=deadbeef',
        'CONTENT_TYPE' => 'application/json',
    ], $raw)->assertStatus(401);

    expect(MemberSubscription::count())->toBe(0);
});

it('rejects a stale (replayed) timestamp with 401', function () {
    enableStripeWebhook();
    $raw = (string) json_encode(completedEvent(1, 1));
    $oldT = now()->getTimestamp() - 1000; // outside the 300s window
    $sig = 't='.$oldT.',v1='.hash_hmac('sha256', "{$oldT}.{$raw}", STRIPE_WH_SECRET);

    postStripeWebhook($this, $raw, $sig)->assertStatus(401);
});

it('rejects an oversize body with 413', function () {
    enableStripeWebhook();
    $raw = str_repeat('a', 524289); // > 512 KB
    $t = now()->getTimestamp();
    $sig = "t={$t},v1=".hash_hmac('sha256', "{$t}.{$raw}", STRIPE_WH_SECRET);

    postStripeWebhook($this, $raw, $sig)->assertStatus(413);
});

// ── Grant on a valid completed checkout ──────────────────────────────────────────────────────────────────

it('grants the tier on a valid checkout.session.completed', function () {
    enableStripeWebhook();
    $user = Users::inGroups(['members', 'tl1'], ['email' => 'sw-grant@pay.test']);
    $tier = stripeTier();
    [$raw, $sig] = stripeSigned(completedEvent((int) $user->id, (int) $tier->id, 'cs_grant'));

    postStripeWebhook($this, $raw, $sig)->assertOk();

    app(PermissionResolver::class)->flushMemo();
    Cache::flush();
    expect($user->fresh()->canDo('tier.ad_free', Scope::global()))->toBeTrue();
    expect(MemberSubscription::where('provider', 'stripe')->where('provider_ref', 'cs_grant')->where('status', 'active')->exists())->toBeTrue();
});

it('is idempotent on a replayed session id', function () {
    enableStripeWebhook();
    $user = Users::inGroups(['members', 'tl1'], ['email' => 'dup@pay.test']);
    $tier = stripeTier();

    [$raw1, $sig1] = stripeSigned(completedEvent((int) $user->id, (int) $tier->id, 'cs_dup'));
    postStripeWebhook($this, $raw1, $sig1)->assertOk();

    [$raw2, $sig2] = stripeSigned(completedEvent((int) $user->id, (int) $tier->id, 'cs_dup'));
    postStripeWebhook($this, $raw2, $sig2)->assertOk()->assertJson(['status' => 'duplicate']);

    expect(MemberSubscription::where('provider', 'stripe')->where('provider_ref', 'cs_dup')->count())->toBe(1);
});

// ── Shape-only: ignore unrelated events; 422 incomplete; ack-but-no-grant on unknown mapping ────────────

it('acknowledges an unrelated event type without action', function () {
    enableStripeWebhook();
    [$raw, $sig] = stripeSigned(['id' => 'evt_x', 'type' => 'payment_intent.created', 'data' => ['object' => []]]);

    postStripeWebhook($this, $raw, $sig)->assertOk()->assertJson(['status' => 'ignored']);
    expect(MemberSubscription::count())->toBe(0);
});

it('422s a completed checkout missing its metadata', function () {
    enableStripeWebhook();
    [$raw, $sig] = stripeSigned(['id' => 'evt_y', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_nometa']]]);

    postStripeWebhook($this, $raw, $sig)->assertStatus(422);
});

it('acknowledges but does not grant when the user/tier cannot be mapped', function () {
    enableStripeWebhook();
    [$raw, $sig] = stripeSigned(completedEvent(999999, 888888, 'cs_unknown'));

    postStripeWebhook($this, $raw, $sig)->assertOk()->assertJson(['status' => 'unknown']);
    expect(MemberSubscription::count())->toBe(0);
});
