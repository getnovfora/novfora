<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Membership\Payments\ManualPaymentProvider;
use App\Models\MembershipTier;
use App\Models\MemberSubscription;
use App\Models\User;
use App\Permissions\PermissionResolver;
use App\Permissions\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| Phase 4 · M5.2 — Admin → Memberships (manual grant/revoke surface, staff-gated).
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function memAdmin(): User
{
    return Users::withTwoFactor(Users::inGroups(['admins'], ['email' => 'mem-admin@acp.test']));
}

function memTier(): MembershipTier
{
    return MembershipTier::create(['name' => 'Gold', 'slug' => 'gold', 'price_cents' => 500, 'currency' => 'USD', 'interval' => 'monthly', 'perks' => ['tier.ad_free'], 'is_active' => true]);
}

function adminMemFlush(): void
{
    app(PermissionResolver::class)->flushMemo();
    Cache::flush();
}

it('forbids a non-admin from the memberships surface', function () {
    $member = Users::inGroups(['members', 'tl2'], ['email' => 'mem-not-admin@acp.test']);

    $this->actingAs($member)->get(route('admin.memberships'))->assertForbidden();
    Livewire::actingAs($member)->test('admin.member-grants')->assertStatus(403);
});

it('lets an admin grant a membership by username and flips the capability', function () {
    $member = Users::inGroups(['members', 'tl1'], ['username' => 'graceful', 'email' => 'grant-target@acp.test']);
    $tier = memTier();

    Livewire::actingAs(memAdmin())
        ->test('admin.member-grants')
        ->set('username', 'graceful')
        ->set('tierId', $tier->id)
        ->set('days', 0)
        ->call('grant')
        ->assertHasNoErrors();

    adminMemFlush();
    expect($member->fresh()->canDo('tier.ad_free', Scope::global()))->toBeTrue();
    expect(MemberSubscription::where('user_id', $member->id)->where('status', 'active')->exists())->toBeTrue();
});

it('grants by email and honours an expiry', function () {
    $member = Users::inGroups(['members', 'tl1'], ['username' => 'byemail', 'email' => 'by-email@acp.test']);
    $tier = memTier();

    Livewire::actingAs(memAdmin())
        ->test('admin.member-grants')
        ->set('username', 'by-email@acp.test')
        ->set('tierId', $tier->id)
        ->set('days', 30)
        ->call('grant')
        ->assertHasNoErrors();

    $sub = MemberSubscription::where('user_id', $member->id)->firstOrFail();
    expect($sub->expires_at)->not->toBeNull();
});

it('shows an error for an unknown member and grants nothing', function () {
    memTier();

    Livewire::actingAs(memAdmin())
        ->test('admin.member-grants')
        ->set('username', 'does-not-exist')
        ->call('grant')
        ->assertHasNoErrors() // no validation error — a soft error message instead
        ->assertSee('No member found');

    expect(MemberSubscription::count())->toBe(0);
});

it('lets an admin revoke a membership and the capability drops', function () {
    $member = Users::inGroups(['members', 'tl1'], ['username' => 'revokee', 'email' => 'revoke-target@acp.test']);
    $tier = memTier();
    $sub = app(ManualPaymentProvider::class)->grant($member, $tier);
    adminMemFlush();
    expect($member->fresh()->canDo('tier.ad_free', Scope::global()))->toBeTrue();

    Livewire::actingAs(memAdmin())
        ->test('admin.member-grants')
        ->call('revoke', $sub->id)
        ->assertHasNoErrors();

    adminMemFlush();
    expect($member->fresh()->canDo('tier.ad_free', Scope::global()))->toBeFalse();
});
