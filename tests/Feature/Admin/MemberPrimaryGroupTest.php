<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Admin\GroupManager;
use App\Groups\PrimaryGroupService;
use App\Models\AuditLog;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| Admin → Members → (member) → Primary group (ACP v3 · v3-e, ADR-0083). The ⚡admin.members.edit-primary-group
| SFC lets an admin set and lock a member's primary group, or clear the lock. Authorization re-asserted in
| mount() AND every action; non-admin attempts are rejected with 403.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function admin2faForPg(): User
{
    return Users::withTwoFactor(Users::inGroups(['admins']));
}

// ── Route ─────────────────────────────────────────────────────────────────────────────────────────────

it('resolves the admin primary-group route for a valid user', function () {
    $admin = admin2faForPg();
    $member = Users::inGroups(['members']);

    $this->actingAs($admin)
        ->get(route('admin.members.primary-group', $member))
        ->assertOk()
        ->assertSee('Primary group');
});

it('404s when the target user does not exist', function () {
    $admin = admin2faForPg();

    $this->actingAs($admin)
        ->get(route('admin.members.primary-group', ['user' => 999999]))
        ->assertNotFound();
});

it('forbids a logged-in non-admin from the admin primary-group route', function () {
    $member = Users::inGroups(['members']);
    $target = Users::inGroups(['members']);

    // A logged-in non-admin is FORBIDDEN (403) by EnsureSystemPanelAccess — only a guest is redirected to login.
    $this->actingAs($member)
        ->get(route('admin.members.primary-group', $target))
        ->assertForbidden();
});

// ── Mount ─────────────────────────────────────────────────────────────────────────────────────────────

it("seeds the form with the target member's current primary group on mount", function () {
    $admin = admin2faForPg();
    $member = Users::inGroups(['members', 'tl1']);
    $primary = $member->primaryGroup();

    Livewire::actingAs($admin)->test('admin.members.edit-primary-group', ['userId' => $member->id])
        ->assertSet('primaryGroupId', $primary ? (int) $primary->getKey() : 0)
        ->assertSet('isLocked', false);
});

it("shows isLocked = true when the member's primary is already admin-locked", function () {
    $admin = admin2faForPg();
    $member = Users::inGroups(['members', 'tl1']);
    $tl1 = Group::where('slug', 'tl1')->firstOrFail();

    app(PrimaryGroupService::class)->setByAdmin($member, $tl1, $admin);

    Livewire::actingAs($admin)->test('admin.members.edit-primary-group', ['userId' => $member->id])
        ->assertSet('isLocked', true);
});

// ── Set + lock ────────────────────────────────────────────────────────────────────────────────────────

it('sets and locks the primary group for the target member', function () {
    $admin = admin2faForPg();
    $member = Users::inGroups(['members', 'tl1']);
    $tl1 = Group::where('slug', 'tl1')->firstOrFail();

    Livewire::actingAs($admin)->test('admin.members.edit-primary-group', ['userId' => $member->id])
        ->set('primaryGroupId', $tl1->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('flash', 'Primary group set and locked.')
        ->assertSet('isLocked', true);

    $fresh = $member->fresh();
    expect($fresh->primaryGroup()?->slug)->toBe('tl1');
    expect(app(PrimaryGroupService::class)->isAdminLocked($fresh))->toBeTrue();
});

it('writes an audit log entry when the admin sets the primary group', function () {
    $admin = admin2faForPg();
    $member = Users::inGroups(['members', 'tl1']);
    $tl1 = Group::where('slug', 'tl1')->firstOrFail();

    Livewire::actingAs($admin)->test('admin.members.edit-primary-group', ['userId' => $member->id])
        ->set('primaryGroupId', $tl1->id)
        ->call('save');

    expect(AuditLog::where('action', 'group.primary.set')->exists())->toBeTrue();
});

it('shows an error if no group id is selected', function () {
    $admin = admin2faForPg();
    $member = Users::inGroups(['members']);

    Livewire::actingAs($admin)->test('admin.members.edit-primary-group', ['userId' => $member->id])
        ->set('primaryGroupId', 0)
        ->call('save')
        ->assertSet('error', 'Please select a group.');
});

it('refuses to set a group the target member does not belong to', function () {
    $admin = admin2faForPg();
    $member = Users::inGroups(['members']);
    $other = app(GroupManager::class)->create(['name' => 'VIPs']);

    Livewire::actingAs($admin)->test('admin.members.edit-primary-group', ['userId' => $member->id])
        ->set('primaryGroupId', $other->id)
        ->call('save')
        ->assertSet('error', fn ($v) => $v !== null);
});

// ── Clear lock ────────────────────────────────────────────────────────────────────────────────────────

it('clears the admin lock and hands the choice back to the member', function () {
    $admin = admin2faForPg();
    $member = Users::inGroups(['members', 'tl1']);
    $tl1 = Group::where('slug', 'tl1')->firstOrFail();

    // First lock it.
    app(PrimaryGroupService::class)->setByAdmin($member, $tl1, $admin);

    Livewire::actingAs($admin)->test('admin.members.edit-primary-group', ['userId' => $member->id])
        ->call('clearLock')
        ->assertHasNoErrors()
        ->assertSet('isLocked', false)
        ->assertSet('flash', 'Lock cleared — the member can now choose their own primary group.');

    // tl1 should remain primary but the lock must be gone.
    $fresh = $member->fresh();
    expect($fresh->primaryGroup()?->slug)->toBe('tl1');
    expect(app(PrimaryGroupService::class)->isAdminLocked($fresh))->toBeFalse();
});

it('writes an audit log entry when the admin clears the lock', function () {
    $admin = admin2faForPg();
    $member = Users::inGroups(['members', 'tl1']);
    $tl1 = Group::where('slug', 'tl1')->firstOrFail();

    app(PrimaryGroupService::class)->setByAdmin($member, $tl1, $admin);

    Livewire::actingAs($admin)->test('admin.members.edit-primary-group', ['userId' => $member->id])
        ->call('clearLock');

    expect(AuditLog::where('action', 'group.primary.unlocked')->exists())->toBeTrue();
});

// ── Auth hardening ────────────────────────────────────────────────────────────────────────────────────

it('403s a non-admin who tries to reach the primary-group SFC (mount self-guard)', function () {
    $member = Users::inGroups(['members', 'tl1']);
    $target = Users::inGroups(['members']);

    // ensureAdmin() aborts 403 in mount(), so a non-admin can never reach save()/clearLock() — the action
    // guards are defence-in-depth behind this. (Chaining ->call() after a 403 mount can't build a snapshot.)
    Livewire::actingAs($member)->test('admin.members.edit-primary-group', ['userId' => $target->id])
        ->assertForbidden();
});

it('403s an admin without confirmed 2FA at the primary-group SFC', function () {
    $admin = Users::inGroups(['admins']); // has admin.access but no confirmed 2FA → the staff-2FA fence trips
    $target = Users::inGroups(['members']);

    Livewire::actingAs($admin)->test('admin.members.edit-primary-group', ['userId' => $target->id])
        ->assertForbidden();
});
