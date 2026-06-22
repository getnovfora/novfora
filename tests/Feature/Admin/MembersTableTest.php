<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\User;
use App\Permissions\PermissionValue as V;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Acl;
use Tests\Support\Users;

/*
| ACP v4 · A1 (ADR-0096) — the ⚡members directory table. APEX (member PII boundary): the listing + every
| action self-guard (admin.access + admin.members.access + staff-2FA, since Livewire bypasses route
| middleware), the email column is gated behind users.manage (no PII leak / no PII search), and the sort
| column is allow-listed so a forged key never reaches orderBy(). Plus filter/sort correctness.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

/** Full admin (admins group → users.manage + admin.members.access), 2FA-confirmed so it clears the staff gate. */
function membersFullAdmin(): User
{
    return Users::withTwoFactor(Users::inGroups(['admins']));
}

/** A restricted admin that reaches the Members section (admin.access + admin.members.access) but has NO users.manage. */
function membersRestrictedAdmin(Acl $acl): User
{
    $grp = $acl->group('memviewer', ['priority' => 50]);
    $acl->grant($grp, 'admin.access', $acl->global, V::Allow);
    $acl->grant($grp, 'admin.members.access', $acl->global, V::Allow);

    return $acl->user(['memviewer']); // not a staff group → no 2FA requirement
}

// ── Route + component gate ───────────────────────────────────────────────────────────────────────────────

it('redirects a guest from the members table', function () {
    $this->get(route('admin.members.index'))->assertRedirect();
});

it('forbids a logged-in non-admin from the members route', function () {
    $this->actingAs(Users::inGroups(['members']))->get(route('admin.members.index'))->assertForbidden();
});

it('403s the component itself for a non-admin (Livewire bypasses route middleware)', function () {
    Livewire::actingAs(Users::inGroups(['members']))->test('admin.members')->assertForbidden();
});

it('403s a staff admin who has not confirmed 2FA', function () {
    Livewire::actingAs(Users::inGroups(['admins']))->test('admin.members')->assertForbidden();
});

it('403s an admin who lacks admin.members.access (section gate)', function () {
    $acl = Acl::make();
    $grp = $acl->group('adminnomembers', ['priority' => 50]);
    $acl->grant($grp, 'admin.access', $acl->global, V::Allow); // admin.access but NOT admin.members.access
    Livewire::actingAs($acl->user(['adminnomembers']))->test('admin.members')->assertForbidden();
});

it('loads the members table for a 2FA admin', function () {
    $this->actingAs(membersFullAdmin())->get(route('admin.members.index'))->assertOk();
});

// ── Email PII gate ───────────────────────────────────────────────────────────────────────────────────────

it('shows the email column to an admin with users.manage', function () {
    Users::inGroups(['members'], ['username' => 'zoe', 'email' => 'zoe@example.test']);

    Livewire::actingAs(membersFullAdmin())->test('admin.members')->assertSee('zoe@example.test');
});

it('never renders email to a restricted admin without users.manage (no PII leak)', function () {
    $acl = Acl::make();
    $restricted = membersRestrictedAdmin($acl);
    Users::inGroups(['members'], ['username' => 'zoe', 'email' => 'zoe@example.test']);

    Livewire::actingAs($restricted)->test('admin.members')
        ->assertSee('zoe')                    // still listed by username
        ->assertDontSee('zoe@example.test');  // the address is never rendered
});

it('does not search email for an actor who cannot see it', function () {
    $acl = Acl::make();
    $restricted = membersRestrictedAdmin($acl);
    Users::inGroups(['members'], ['username' => 'alice', 'email' => 'secret@example.test']);

    Livewire::actingAs($restricted)->test('admin.members')
        ->set('search', 'secret@example.test') // an email term must NOT surface the user…
        ->assertDontSee('alice');              // …because email is not in the searchable set for them
});

it('does search email for an actor who can see it', function () {
    Users::inGroups(['members'], ['username' => 'bob', 'email' => 'findme@example.test']);

    Livewire::actingAs(membersFullAdmin())->test('admin.members')
        ->set('search', 'findme@example.test')
        ->assertSee('bob');
});

// ── Filters ──────────────────────────────────────────────────────────────────────────────────────────────

it('filters by username search', function () {
    Users::inGroups(['members'], ['username' => 'findme', 'email' => 'findme@x.test']);
    Users::inGroups(['members'], ['username' => 'otherperson', 'email' => 'other@x.test']);

    Livewire::actingAs(membersFullAdmin())->test('admin.members')
        ->set('search', 'findme')
        ->assertSee('findme')
        ->assertDontSee('otherperson');
});

it('filters by status', function () {
    Users::inGroups(['members'], ['username' => 'banneduser', 'status' => 'banned']);
    Users::inGroups(['members'], ['username' => 'happyuser', 'status' => 'active']);

    Livewire::actingAs(membersFullAdmin())->test('admin.members')
        ->set('status', 'banned')
        ->assertSee('banneduser')
        ->assertDontSee('happyuser');
});

// ── Sort allow-list (no raw orderBy) ─────────────────────────────────────────────────────────────────────

it('ignores a forged sort column passed to the action (allow-list)', function () {
    Livewire::actingAs(membersFullAdmin())->test('admin.members')
        ->call('sortBy', 'password')        // not allow-listed
        ->assertSet('sort', 'created_at');  // unchanged → never reaches orderBy()
});

it('does not error when the sort property is forged directly', function () {
    Livewire::actingAs(membersFullAdmin())->test('admin.members')
        ->set('sort', 'evil_column')        // a malicious client could set any public property…
        ->assertSet('sort', 'evil_column'); // …and the row query falls back to created_at — render did not throw
});

it('toggles sort direction on a re-click of the same column', function () {
    Livewire::actingAs(membersFullAdmin())->test('admin.members')
        ->call('sortBy', 'username')->assertSet('sort', 'username')->assertSet('dir', 'asc')
        ->call('sortBy', 'username')->assertSet('dir', 'desc');
});

// ── Hidden-group ceiling (apex-review MEDIUM, ADR-0096) ──────────────────────────────────────────────────

it('hides private group names from a restricted admin but shows public ones', function () {
    $acl = Acl::make();
    $restricted = membersRestrictedAdmin($acl);
    $acl->group('opensociety', ['name' => 'Open Society', 'is_public' => true]);
    $acl->group('secretcabal', ['name' => 'Secret Cabal', 'is_public' => false]);

    Livewire::actingAs($restricted)->test('admin.members')
        ->assertSee('Open Society')
        ->assertDontSee('Secret Cabal'); // a hidden group's name never enters the dropdown for them
});

it('shows private group names to a full admin (users.manage)', function () {
    $acl = Acl::make();
    $acl->group('secretcabal', ['name' => 'Secret Cabal', 'is_public' => false]);

    Livewire::actingAs(membersFullAdmin())->test('admin.members')->assertSee('Secret Cabal');
});

it('does not let a restricted admin probe a hidden group roster via a forged filter id', function () {
    $acl = Acl::make();
    $restricted = membersRestrictedAdmin($acl);
    $hidden = $acl->group('secretcabal', ['name' => 'Secret Cabal', 'is_public' => false]);
    $insider = Users::inGroups(['members'], ['username' => 'insideragent']);
    $insider->groups()->attach($hidden->id);

    Livewire::actingAs($restricted)->test('admin.members')
        ->set('group', (string) $hidden->id) // forge the filter to the hidden group's id
        ->assertDontSee('insideragent');     // the roster is not revealed
});
