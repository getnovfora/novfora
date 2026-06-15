<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Membership\Payments\PaymentException;
use App\Membership\Payments\PaymentProviders;
use App\Membership\Payments\StripePaymentProvider;
use App\Models\MembershipTier;
use App\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\Users;

/*
| Phase 4 · M5.3 — the Stripe provider is DISABLED by default (no charge can be initiated). When enabled it
| creates a hosted Checkout Session — card data never touches the server. Validated against a MOCKED HTTP
| client (request shape), NOT live Stripe (ADR-0065).
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function enableStripeProvider(): void
{
    $s = app(Settings::class);
    $s->set('payments.stripe.secret_key', 'sk_test_x');
    $s->set('payments.stripe.enabled', true);
}

function providerTier(): MembershipTier
{
    return MembershipTier::create(['name' => 'Gold', 'slug' => 'gold', 'price_cents' => 500, 'currency' => 'USD', 'interval' => 'monthly', 'perks' => ['tier.ad_free'], 'is_active' => true]);
}

it('is disabled by default and refuses checkout (the money fence)', function () {
    $provider = app(StripePaymentProvider::class);
    $user = Users::inGroups(['members', 'tl1'], ['email' => 'sp-off@pay.test']);

    expect($provider->isEnabled())->toBeFalse();
    expect($provider->supportsSelfCheckout())->toBeFalse();
    expect(fn () => $provider->checkout($user, providerTier()))->toThrow(PaymentException::class);
});

it('creates a hosted checkout session when enabled, with no card data on the wire', function () {
    Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_test_123', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_123'], 200)]);
    enableStripeProvider();

    $user = Users::inGroups(['members', 'tl1'], ['email' => 'sp-on@pay.test']);
    $tier = providerTier();

    $result = app(StripePaymentProvider::class)->checkout($user, $tier);

    expect($result->redirectUrl)->toBe('https://checkout.stripe.com/c/pay/cs_test_123');

    Http::assertSent(function ($request) use ($user, $tier) {
        return str_starts_with($request->url(), 'https://api.stripe.com/v1/checkout/sessions')
            && $request['mode'] === 'subscription'
            && (int) ($request['metadata']['user_id'] ?? 0) === (int) $user->id
            && (int) ($request['metadata']['tier_id'] ?? 0) === (int) $tier->id
            && ! str_contains(mb_strtolower((string) json_encode($request->data())), 'card'); // never card data
    });
});

it('only appears in the registry as a self-checkout provider once enabled', function () {
    expect(app(PaymentProviders::class)->get('stripe'))->toBeNull(); // disabled by default

    enableStripeProvider();

    expect(app(PaymentProviders::class)->get('stripe'))->toBeInstanceOf(StripePaymentProvider::class);
    expect(array_keys(app(PaymentProviders::class)->selfCheckout()))->toContain('stripe');
});

// ── Member self-checkout route ───────────────────────────────────────────────────────────────────────────

it('404s the self-checkout route when no provider is enabled', function () {
    $tier = providerTier();
    $member = Users::inGroups(['members', 'tl1'], ['email' => 'co-off@pay.test']);

    $this->actingAs($member)->post(route('membership.checkout', $tier))->assertNotFound();
});

it('redirects to Stripe when self-checkout is enabled', function () {
    Http::fake(['api.stripe.com/*' => Http::response(['url' => 'https://checkout.stripe.com/c/pay/cs_route'], 200)]);
    enableStripeProvider();
    $tier = providerTier();
    $member = Users::inGroups(['members', 'tl1'], ['email' => 'co-on@pay.test']);

    $this->actingAs($member)->post(route('membership.checkout', $tier))
        ->assertRedirect('https://checkout.stripe.com/c/pay/cs_route');
});
