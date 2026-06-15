<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Membership\MembershipService;
use App\Models\MembershipTier;
use App\Permissions\PermissionResolver;
use App\Permissions\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Users;

/*
| Phase 4 · M5.1 — membership tiers grant/revoke perks THROUGH the permission engine (TierProjector →
| acl_entries). The capability is a normal canDo() at global scope, so the same resolver gates everything.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function flushPerms(): void
{
    app(PermissionResolver::class)->flushMemo();
    Cache::flush();
}

function makeTier(array $perks, bool $active = true, array $attrs = []): MembershipTier
{
    return MembershipTier::create(array_merge([
        'name' => 'Gold',
        'slug' => 'gold-'.bin2hex(random_bytes(3)),
        'price_cents' => 500,
        'currency' => 'USD',
        'interval' => 'monthly',
        'perks' => $perks,
        'is_active' => $active,
    ], $attrs));
}

it('grants a tier’s perks through the engine on activation', function () {
    $tier = makeTier(['tier.ad_free', 'tier.custom_title']);
    $user = Users::inGroups(['members', 'tl1'], ['email' => 'grant@tier.test']);

    expect($user->canDo('tier.ad_free', Scope::global()))->toBeFalse(); // not yet

    app(MembershipService::class)->activate($user, $tier);
    flushPerms();
    $user = $user->fresh();

    expect($user->canDo('tier.ad_free', Scope::global()))->toBeTrue();
    expect($user->canDo('tier.custom_title', Scope::global()))->toBeTrue();
    expect($user->canDo('tier.early_access', Scope::global()))->toBeFalse(); // not in this tier
});

it('revokes the perks on cancellation', function () {
    $tier = makeTier(['tier.ad_free']);
    $user = Users::inGroups(['members', 'tl1'], ['email' => 'cancel@tier.test']);
    $sub = app(MembershipService::class)->activate($user, $tier);
    flushPerms();
    expect($user->fresh()->canDo('tier.ad_free', Scope::global()))->toBeTrue();

    app(MembershipService::class)->cancel($sub);
    flushPerms();

    expect($user->fresh()->canDo('tier.ad_free', Scope::global()))->toBeFalse();
});

it('expires due subscriptions and revokes their perks (cron)', function () {
    $tier = makeTier(['tier.colored_username']);
    $user = Users::inGroups(['members', 'tl1'], ['email' => 'expire@tier.test']);
    app(MembershipService::class)->activate($user, $tier, 'manual', null, now()->subMinute()); // already past
    flushPerms();
    expect($user->fresh()->canDo('tier.colored_username', Scope::global()))->toBeTrue();

    $count = app(MembershipService::class)->expireDue();
    flushPerms();

    expect($count)->toBe(1);
    expect($user->fresh()->canDo('tier.colored_username', Scope::global()))->toBeFalse();
});

it('grants nothing for an inactive tier', function () {
    $tier = makeTier(['tier.ad_free'], active: false);
    $user = Users::inGroups(['members', 'tl1'], ['email' => 'inactive@tier.test']);

    app(MembershipService::class)->activate($user, $tier);
    flushPerms();

    expect($user->fresh()->canDo('tier.ad_free', Scope::global()))->toBeFalse();
});

it('unions perks across multiple active subscriptions', function () {
    $a = makeTier(['tier.ad_free']);
    $b = makeTier(['tier.early_access']);
    $user = Users::inGroups(['members', 'tl1'], ['email' => 'union@tier.test']);

    app(MembershipService::class)->activate($user, $a);
    app(MembershipService::class)->activate($user, $b);
    flushPerms();
    $user = $user->fresh();

    expect($user->canDo('tier.ad_free', Scope::global()))->toBeTrue();
    expect($user->canDo('tier.early_access', Scope::global()))->toBeTrue();
});

it('never grants a perk key outside the fixed perk universe', function () {
    $tier = makeTier(['tier.ad_free', 'admin.access', 'tier.bogus']); // poisoned list
    $user = Users::inGroups(['members', 'tl1'], ['email' => 'poison@tier.test']);

    app(MembershipService::class)->activate($user, $tier);
    flushPerms();
    $user = $user->fresh();

    expect($user->canDo('tier.ad_free', Scope::global()))->toBeTrue();   // valid perk granted
    expect($user->canDo('admin.access', Scope::global()))->toBeFalse();  // arbitrary key NEVER granted
    expect($user->canDo('tier.bogus', Scope::global()))->toBeFalse();    // unknown perk ignored
});
