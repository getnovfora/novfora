<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Membership\MembershipService;
use App\Models\MembershipTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

/*
| Phase 4 · M5.1 — the member-facing membership/upgrade page.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('redirects a guest to login', function () {
    $this->get(route('membership.index'))->assertRedirect(route('login'));
});

it('lists active tiers and hides inactive ones', function () {
    MembershipTier::create(['name' => 'Gold Plan', 'slug' => 'gold', 'price_cents' => 500, 'currency' => 'USD', 'interval' => 'monthly', 'perks' => ['tier.ad_free'], 'is_active' => true]);
    MembershipTier::create(['name' => 'Hidden Plan', 'slug' => 'hidden', 'price_cents' => 900, 'currency' => 'USD', 'interval' => 'monthly', 'perks' => [], 'is_active' => false]);
    $member = Users::inGroups(['members', 'tl1'], ['email' => 'page@tier.test']);

    $this->actingAs($member)->get(route('membership.index'))
        ->assertOk()
        ->assertSee('Gold Plan')
        ->assertSee('Ad-free browsing')
        ->assertDontSee('Hidden Plan');
});

it('shows the member’s current plan', function () {
    $tier = MembershipTier::create(['name' => 'Platinum', 'slug' => 'plat', 'price_cents' => 1500, 'currency' => 'USD', 'interval' => 'monthly', 'perks' => ['tier.early_access'], 'is_active' => true]);
    $member = Users::inGroups(['members', 'tl1'], ['email' => 'current@tier.test']);
    app(MembershipService::class)->activate($member, $tier);

    $this->actingAs($member->fresh())->get(route('membership.index'))
        ->assertOk()
        ->assertSee('You’re on the')
        ->assertSee('Platinum');
});
