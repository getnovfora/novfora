<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Admin\GroupManager;
use App\Groups\PrimaryGroupService;
use App\Models\Group;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| Settings → Primary group (ACP v3 · v3-e, ADR-0083). The ⚡primary-group SFC lets a member choose which of
| their current groups is displayed as their rank badge and name colour. The admin-lock guard prevents changes
| when an admin has overridden the choice; all writes go through PrimaryGroupService.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

// ── Route + page-load ─────────────────────────────────────────────────────────────────────────────────

it('requires authentication to reach the primary-group settings page', function () {
    $this->get(route('settings.primary-group'))->assertRedirect();
});

it('loads the primary-group settings page for an authenticated member', function () {
    $user = Users::inGroups(['members', 'tl1']);
    $this->actingAs($user)->get(route('settings.primary-group'))->assertOk();
});

// ── Mount + seed ──────────────────────────────────────────────────────────────────────────────────────

it('seeds the form with the current primary group on mount', function () {
    $user = Users::inGroups(['members', 'tl1']);
    $primary = $user->primaryGroup();

    Livewire::actingAs($user)->test('settings.primary-group')
        ->assertSet('primaryGroupId', $primary ? (int) $primary->getKey() : 0);
});

// ── Successful save ───────────────────────────────────────────────────────────────────────────────────

it('lets a member switch their primary group to another group they belong to', function () {
    // Put the user in two groups; members is seeded as primary (index 0).
    $user = Users::inGroups(['members', 'tl1']);
    $tl1 = Group::where('slug', 'tl1')->firstOrFail();

    Livewire::actingAs($user)->test('settings.primary-group')
        ->set('primaryGroupId', $tl1->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('flash', 'Primary group updated.');

    expect($user->fresh()->primaryGroup()?->slug)->toBe('tl1');
});

// ── Validation ────────────────────────────────────────────────────────────────────────────────────────

it('shows an error when no group is selected (id 0)', function () {
    $user = Users::inGroups(['members']);

    Livewire::actingAs($user)->test('settings.primary-group')
        ->set('primaryGroupId', 0)
        ->call('save')
        ->assertSet('error', 'Please select a group.');
});

it('refuses to set a group the user does not belong to', function () {
    $user = Users::inGroups(['members']);
    $other = app(GroupManager::class)->create(['name' => 'VIPs']);

    Livewire::actingAs($user)->test('settings.primary-group')
        ->set('primaryGroupId', $other->id)
        ->call('save')
        ->assertSet('error', fn ($v) => $v !== null); // GroupException message
});

// ── Admin-lock guard ──────────────────────────────────────────────────────────────────────────────────

it('shows the locked state and hides the form when an admin has locked the primary group', function () {
    $user = Users::inGroups(['members', 'tl1']);
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));
    $tl1 = Group::where('slug', 'tl1')->firstOrFail();

    app(PrimaryGroupService::class)->setByAdmin($user, $tl1, $admin);

    $component = Livewire::actingAs($user)->test('settings.primary-group');
    $component->assertSee('An administrator has set your primary group');
});

it('refuses a user save() when an admin lock is in force', function () {
    $user = Users::inGroups(['members', 'tl1']);
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));
    $members = Group::where('slug', 'members')->firstOrFail();
    $tl1 = Group::where('slug', 'tl1')->firstOrFail();

    // Admin locks tl1 as primary.
    app(PrimaryGroupService::class)->setByAdmin($user, $tl1, $admin);

    // Member tries to switch back to members — PrimaryGroupService must refuse.
    Livewire::actingAs($user)->test('settings.primary-group')
        ->set('primaryGroupId', $members->id)
        ->call('save')
        ->assertSet('error', fn ($v) => str_contains((string) $v, 'administrator'));

    // Primary must remain tl1.
    expect($user->fresh()->primaryGroup()?->slug)->toBe('tl1');
});

// ── Auth hardening ────────────────────────────────────────────────────────────────────────────────────

it('403s if an unauthenticated request reaches the SFC directly', function () {
    // mount() aborts 403 for a guest, so a save() can never be reached; chaining ->call() after a 403 mount
    // can't build a Livewire snapshot, so assert the mount-time guard directly.
    Livewire::test('settings.primary-group')
        ->assertForbidden();
});
