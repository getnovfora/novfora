<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\MembershipTier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| Phase 4 · M5.1 — Admin → Membership tiers manager (staff-gated CRUD).
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function tierAdmin(): User
{
    return Users::withTwoFactor(Users::inGroups(['admins'], ['email' => 'tier-admin@acp.test']));
}

it('forbids a non-admin from the tier manager', function () {
    $member = Users::inGroups(['members', 'tl2'], ['email' => 'tier-member@acp.test']);

    $this->actingAs($member)->get(route('admin.tiers'))->assertForbidden();
    Livewire::actingAs($member)->test('admin.tiers')->assertStatus(403);
});

it('lets an admin create a tier with perks', function () {
    Livewire::actingAs(tierAdmin())
        ->test('admin.tiers')
        ->call('create')
        ->set('name', 'Gold')
        ->set('price', '5.00')
        ->set('currency', 'usd')
        ->set('interval', 'monthly')
        ->set('perks', ['tier.ad_free', 'tier.custom_title'])
        ->call('save')
        ->assertHasNoErrors();

    $tier = MembershipTier::where('name', 'Gold')->firstOrFail();
    expect($tier->slug)->toBe('gold');
    expect($tier->price_cents)->toBe(500);
    expect($tier->currency)->toBe('USD');
    expect($tier->perkKeys())->toEqual(['tier.ad_free', 'tier.custom_title']);
});

it('rejects a perk key outside the fixed universe at the form layer', function () {
    Livewire::actingAs(tierAdmin())
        ->test('admin.tiers')
        ->call('create')
        ->set('name', 'Hacky')
        ->set('price', '1.00')
        ->set('perks', ['admin.access'])
        ->call('save')
        ->assertHasErrors('perks.0');

    expect(MembershipTier::where('name', 'Hacky')->exists())->toBeFalse();
});

it('lets an admin edit and deactivate a tier', function () {
    $tier = MembershipTier::create(['name' => 'Silver', 'slug' => 'silver', 'price_cents' => 200, 'currency' => 'USD', 'interval' => 'monthly', 'perks' => ['tier.ad_free'], 'is_active' => true]);

    Livewire::actingAs(tierAdmin())
        ->test('admin.tiers')
        ->call('edit', $tier->id)
        ->set('name', 'Silver Plus')
        ->set('isActive', false)
        ->call('save')
        ->assertHasNoErrors();

    $tier->refresh();
    expect($tier->name)->toBe('Silver Plus');
    expect($tier->is_active)->toBeFalse();
});

it('deletes a tier on confirm', function () {
    $tier = MembershipTier::create(['name' => 'Temp', 'slug' => 'temp', 'price_cents' => 0, 'currency' => 'USD', 'interval' => 'one_time', 'perks' => [], 'is_active' => true]);

    Livewire::actingAs(tierAdmin())
        ->test('admin.tiers')
        ->call('delete', $tier->id) // arm
        ->call('delete', $tier->id) // confirm
        ->assertHasNoErrors();

    expect(MembershipTier::find($tier->id))->toBeNull();
});
