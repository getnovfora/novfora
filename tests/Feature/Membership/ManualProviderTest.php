<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Membership\Payments\ManualPaymentProvider;
use App\Membership\Payments\PaymentException;
use App\Membership\Payments\PaymentProviders;
use App\Models\MembershipTier;
use App\Permissions\PermissionResolver;
use App\Permissions\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Users;

/*
| Phase 4 · M5.2 — the offline/MANUAL payment provider: the only live-granting path. Grant activates a
| subscription + grants perks through the engine; revoke cancels + revokes. Self-checkout is unsupported.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function manualTier(array $perks = ['tier.ad_free']): MembershipTier
{
    return MembershipTier::create(['name' => 'Gold', 'slug' => 'gold-'.bin2hex(random_bytes(3)), 'price_cents' => 500, 'currency' => 'USD', 'interval' => 'monthly', 'perks' => $perks, 'is_active' => true]);
}

function manualPermFlush(): void
{
    app(PermissionResolver::class)->flushMemo();
    Cache::flush();
}

it('grants a membership and its perks through the engine', function () {
    $user = Users::inGroups(['members', 'tl1'], ['email' => 'mp-grant@pay.test']);
    $tier = manualTier(['tier.ad_free']);

    $sub = app(ManualPaymentProvider::class)->grant($user, $tier);
    manualPermFlush();

    expect($sub->status)->toBe('active');
    expect($sub->provider)->toBe('manual');
    expect($user->fresh()->canDo('tier.ad_free', Scope::global()))->toBeTrue();
});

it('grants with an expiry', function () {
    $user = Users::inGroups(['members', 'tl1'], ['email' => 'mp-exp@pay.test']);
    $tier = manualTier();

    $sub = app(ManualPaymentProvider::class)->grant($user, $tier, now()->addDays(30));

    expect($sub->expires_at)->not->toBeNull();
    expect($sub->expires_at->isFuture())->toBeTrue();
});

it('revokes a membership and its perks', function () {
    $user = Users::inGroups(['members', 'tl1'], ['email' => 'mp-rev@pay.test']);
    $tier = manualTier(['tier.ad_free']);
    $provider = app(ManualPaymentProvider::class);

    $sub = $provider->grant($user, $tier);
    manualPermFlush();
    expect($user->fresh()->canDo('tier.ad_free', Scope::global()))->toBeTrue();

    $provider->revoke($sub);
    manualPermFlush();

    expect($sub->fresh()->status)->toBe('cancelled');
    expect($user->fresh()->canDo('tier.ad_free', Scope::global()))->toBeFalse();
});

it('does not support member self-checkout and throws if asked', function () {
    $user = Users::inGroups(['members', 'tl1'], ['email' => 'mp-co@pay.test']);
    $tier = manualTier();
    $provider = app(ManualPaymentProvider::class);

    expect($provider->isEnabled())->toBeTrue();
    expect($provider->supportsSelfCheckout())->toBeFalse();

    expect(fn () => $provider->checkout($user, $tier))->toThrow(PaymentException::class);
});

it('registers the manual provider as enabled but not self-checkout', function () {
    $registry = app(PaymentProviders::class);

    expect(array_keys($registry->enabled()))->toContain('manual');
    expect($registry->get('manual'))->toBeInstanceOf(ManualPaymentProvider::class);
    expect($registry->selfCheckout())->toBe([]); // manual is admin-driven, never a buy button
});
