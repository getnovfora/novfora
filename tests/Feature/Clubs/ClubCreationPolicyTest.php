<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Clubs\ClubCreation;
use App\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function setClubPolicy(string $policy, ?int $minTl = null): void
{
    $settings = app(Settings::class);
    $settings->set('clubs.creation_policy', $policy);
    if ($minTl !== null) {
        $settings->set('clubs.creation_min_trust_level', (string) $minTl);
    }
}

// ── Policy: any ──────────────────────────────────────────────────────────────────────────────────────────

it('lets any verified member create a club under the any policy', function () {
    setClubPolicy('any');
    $tl0 = Users::inGroups(['members', 'tl0'], ['email' => 'any-tl0@policy.test']);

    expect(app(ClubCreation::class)->canCreate($tl0))->toBeTrue();
});

it('still blocks an unverified member under the any policy', function () {
    setClubPolicy('any');
    $unverified = Users::inGroups(['members', 'tl2'], ['email' => 'unverified@policy.test']);
    $unverified->forceFill(['email_verified_at' => null])->save();

    expect(app(ClubCreation::class)->canCreate($unverified->fresh()))->toBeFalse();
});

// ── Policy: trust (threshold) ────────────────────────────────────────────────────────────────────────────

it('enforces the default trust threshold of 2', function () {
    setClubPolicy('trust', 2);
    $tl1 = Users::inGroups(['members', 'tl1'], ['email' => 'trust-tl1@policy.test']);
    $tl2 = Users::inGroups(['members', 'tl2'], ['email' => 'trust-tl2@policy.test']);

    expect(app(ClubCreation::class)->canCreate($tl1))->toBeFalse();
    expect(app(ClubCreation::class)->canCreate($tl2))->toBeTrue();
});

it('honours a custom trust threshold of 3', function () {
    setClubPolicy('trust', 3);
    $tl2 = Users::inGroups(['members', 'tl2'], ['email' => 'trust3-tl2@policy.test']);
    $tl3 = Users::inGroups(['members', 'tl3'], ['email' => 'trust3-tl3@policy.test']);

    expect(app(ClubCreation::class)->canCreate($tl2))->toBeFalse();
    expect(app(ClubCreation::class)->canCreate($tl3))->toBeTrue();
});

// ── Policy: staff ────────────────────────────────────────────────────────────────────────────────────────

it('restricts creation to staff under the staff policy', function () {
    setClubPolicy('staff');
    $tl4 = Users::inGroups(['members', 'tl4'], ['email' => 'staff-tl4@policy.test']);
    $mod = Users::inGroups(['moderators'], ['email' => 'staff-mod@policy.test']);
    $admin = Users::inGroups(['admins'], ['email' => 'staff-admin@policy.test']);

    expect(app(ClubCreation::class)->canCreate($tl4))->toBeFalse();
    expect(app(ClubCreation::class)->canCreate($mod))->toBeTrue();
    expect(app(ClubCreation::class)->canCreate($admin))->toBeTrue();
});

it('always lets staff create regardless of policy', function () {
    setClubPolicy('trust', 4);
    $mod = Users::inGroups(['moderators'], ['email' => 'always-mod@policy.test']);

    expect(app(ClubCreation::class)->canCreate($mod))->toBeTrue();
});

// ── ACP page ─────────────────────────────────────────────────────────────────────────────────────────────

it('saves the creation policy from the admin settings page', function () {
    $admin = Users::withTwoFactor(Users::inGroups(['admins'], ['email' => 'acp-admin@policy.test']));

    Livewire::actingAs($admin)
        ->test('admin.settings.clubs')
        ->set('policy', 'any')
        ->call('save')
        ->assertHasNoErrors();

    expect(app(Settings::class)->string('clubs.creation_policy'))->toBe('any');
});

it('forbids a non-admin from the club settings page', function () {
    $member = Users::inGroups(['members', 'tl2'], ['email' => 'acp-member@policy.test']);

    $this->actingAs($member)->get(route('admin.settings.clubs'))->assertForbidden();
    Livewire::actingAs($member)->test('admin.settings.clubs')->assertStatus(403);
});
