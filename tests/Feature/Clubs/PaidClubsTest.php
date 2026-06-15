<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Clubs\ClubCreation;
use App\Membership\Payments\ManualPaymentProvider;
use App\Models\MembershipTier;
use App\Permissions\PermissionResolver;
use App\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| Phase 4 · M5.4 — the money-fenced paid-clubs hook: when clubs.require_membership is on, a non-staff member
| must ALSO hold the tier.create_clubs perk (granted via the existing manual/Stripe path; no new money path).
| Off by default — baseline behaviour is unchanged.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function setRequireMembership(bool $on = true): void
{
    app(Settings::class)->set('clubs.require_membership', $on);
}

function clubPermFlush(): void
{
    app(PermissionResolver::class)->flushMemo();
    Cache::flush();
}

it('does not require a membership by default (baseline unchanged)', function () {
    $tl2 = Users::inGroups(['members', 'tl2'], ['email' => 'pc-default@club.test']);

    expect(app(ClubCreation::class)->canCreate($tl2))->toBeTrue();
});

it('blocks a qualifying member without the membership perk when the flag is on', function () {
    setRequireMembership(true);
    $tl2 = Users::inGroups(['members', 'tl2'], ['email' => 'pc-noperk@club.test']);

    expect(app(ClubCreation::class)->canCreate($tl2))->toBeFalse();
    $this->actingAs($tl2)->get(route('clubs.create'))->assertForbidden();
});

it('allows a member who holds the create-clubs perk when the flag is on', function () {
    setRequireMembership(true);
    $member = Users::inGroups(['members', 'tl2'], ['email' => 'pc-perk@club.test']);
    $tier = MembershipTier::create(['name' => 'Patron', 'slug' => 'patron', 'price_cents' => 0, 'currency' => 'USD', 'interval' => 'monthly', 'perks' => ['tier.create_clubs'], 'is_active' => true]);
    app(ManualPaymentProvider::class)->grant($member, $tier);
    clubPermFlush();

    expect(app(ClubCreation::class)->canCreate($member->fresh()))->toBeTrue();
    $this->actingAs($member->fresh())->get(route('clubs.create'))->assertOk();
});

it('always lets staff create clubs even with the membership flag on', function () {
    setRequireMembership(true);
    $mod = Users::inGroups(['moderators'], ['email' => 'pc-staff@club.test']);

    expect(app(ClubCreation::class)->canCreate($mod))->toBeTrue();
});

it('saves the require-membership toggle from the admin clubs page', function () {
    $admin = Users::withTwoFactor(Users::inGroups(['admins'], ['email' => 'pc-admin@club.test']));

    Livewire::actingAs($admin)
        ->test('admin.settings.clubs')
        ->set('requireMembership', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(app(Settings::class)->bool('clubs.require_membership'))->toBeTrue();
});
