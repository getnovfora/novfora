<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Exceptions\FriendlyDenialException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

/**
 * NOV-96 (ADR-0109) — the route-level friendly-403 (the fifth contract outcome) and the visible↔succeeds /
 * hidden↔403 PAIR on an adopted surface: the profile staff-tools control ↔ the user-delete confirm page.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('FriendlyDenialException::deny throws a 403 carrying the curated, translated reason', function () {
    expect(fn () => FriendlyDenialException::deny('permissions.denied.staff_only'))
        ->toThrow(FriendlyDenialException::class, (string) __('permissions.denied.staff_only'));
});

it('renders a friendly explainer (not a bare 403) when an authed non-staff viewer hits the confirm page', function () {
    $target = Users::inGroups(['members']);
    $viewer = Users::inGroups(['members']); // authenticated + verified, but holds no force-delete authority

    $this->actingAs($viewer)
        ->get(route('moderation.user-delete.confirm', $target))
        ->assertForbidden()
        ->assertSee(__('permissions.denied.heading'))
        ->assertSee(__('permissions.denied.staff_only'));
});

it('serves the friendly-403 as JSON for an API client', function () {
    $target = Users::inGroups(['members']);
    $viewer = Users::inGroups(['members']);

    $this->actingAs($viewer)
        ->getJson(route('moderation.user-delete.confirm', $target))
        ->assertForbidden()
        ->assertExactJson(['message' => (string) __('permissions.denied.staff_only')]);
});

it('lets an authorised staff viewer through to the real confirm page (the visible↔succeeds side)', function () {
    $target = Users::inGroups(['members']);
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));

    $this->actingAs($admin)
        ->get(route('moderation.user-delete.confirm', $target))
        ->assertOk();
});

it('pairs profile staff-tools visibility with the confirm-page authorisation (adopted surface)', function () {
    $target = Users::inGroups(['members']);
    $confirmUrl = route('moderation.user-delete.confirm', $target);
    $control = (string) __('profiles.delete_account'); // the staff-tools delete-account control label

    // Staff admin: the delete control is VISIBLE on the profile AND the confirm page succeeds.
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));
    $this->actingAs($admin)->get(route('profiles.show', $target))->assertSee($control);
    $this->actingAs($admin)->get($confirmUrl)->assertOk();

    // Plain member: the control is HIDDEN (ghost-UI kill) AND a forged navigation is a friendly 403.
    $member = Users::inGroups(['members']);
    $this->actingAs($member)->get(route('profiles.show', $target))->assertDontSee($control);
    $this->actingAs($member)->get($confirmUrl)->assertForbidden();
});
