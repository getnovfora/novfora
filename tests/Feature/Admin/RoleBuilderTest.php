<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Admin\GroupManager;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Permissions\PermissionValue as V;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Acl;
use Tests\Support\Users;

/*
| ACP v3 · v3-d — the ⚡roles builder SFC. Authorization is re-asserted in mount() AND every action; the admin-tier
| escalation fence is pre-checked here for a clean 403 (the RoleManager service is the backstop + the convergence
| oracle, covered in CustomRoleBuilderTest). Here we pin the UI surface: the gate, CRUD, assignment, and that a
| system preset is read-only.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function roleBuilderAdmin(): User
{
    return Users::withTwoFactor(Users::inGroups(['admins']));
}

/** A non-admin permissions.manager: reaches the builder gate (admin.access + permissions.manage) but is NOT staff. */
function roleBuilderPermManager(Acl $acl, array $extra = []): User
{
    $pm = $acl->group('permmgr', ['priority' => 50]);
    $acl->grant($pm, 'admin.access', $acl->global, V::Allow);
    $acl->grant($pm, 'permissions.manage', $acl->global, V::Allow);
    foreach ($extra as $key) {
        $acl->grant($pm, $key, $acl->global, V::Allow);
    }

    return $acl->user(['permmgr']);
}

// ── Route + page-load gate ───────────────────────────────────────────────────────────────────────────────

it('redirects a guest away from the roles page', function () {
    $this->get(route('admin.groups.roles'))->assertRedirect();
});

it('forbids a logged-in non-admin from the roles page', function () {
    $this->actingAs(Users::inGroups(['members']))->get(route('admin.groups.roles'))->assertForbidden();
});

it('loads the roles page for a 2FA admin', function () {
    $this->actingAs(roleBuilderAdmin())->get(route('admin.groups.roles'))->assertOk()->assertSee('Custom roles');
});

// ── Mount self-guard ─────────────────────────────────────────────────────────────────────────────────────

it('403s the SFC for a guest', function () {
    Livewire::test('admin.roles')->assertForbidden();
});

it('403s the SFC for a logged-in non-admin', function () {
    Livewire::actingAs(Users::inGroups(['members']))->test('admin.roles')->assertForbidden();
});

it('403s the SFC for an admin without confirmed 2FA', function () {
    Livewire::actingAs(Users::inGroups(['admins']))->test('admin.roles')->assertForbidden();
});

it('lets a non-admin permissions.manager reach the builder', function () {
    $acl = Acl::make();
    Livewire::actingAs(roleBuilderPermManager($acl))->test('admin.roles')->assertSee('Custom roles');
});

// ── Create ───────────────────────────────────────────────────────────────────────────────────────────────

it('creates a custom role through the builder', function () {
    Livewire::actingAs(roleBuilderAdmin())->test('admin.roles')
        ->call('newRole')
        ->set('name', 'Helpers')
        ->call('setValue', 'topic.moderate', 'yes')
        ->call('save')
        ->assertHasNoErrors();

    $role = Role::where('name', 'Helpers')->where('is_preset', false)->firstOrFail();
    expect($role->permissions()->where('permission_key', 'topic.moderate')->value('value'))->toBe(V::Allow->value);
});

// ── Escalation fence (UI) ────────────────────────────────────────────────────────────────────────────────

it('403s a non-admin who tries to set an Administration-tier key', function () {
    $acl = Acl::make();
    Livewire::actingAs(roleBuilderPermManager($acl))->test('admin.roles')
        ->call('setValue', 'admin.access', 'yes')
        ->assertStatus(403);
});

it('refuses (flash) a non-admin granting a key beyond their ceiling', function () {
    $acl = Acl::make();
    $pm = roleBuilderPermManager($acl); // does not hold topic.moderate

    Livewire::actingAs($pm)->test('admin.roles')
        ->call('newRole')
        ->set('name', 'Overreach')
        ->call('setValue', 'topic.moderate', 'yes') // not admin-tier → UI allows it
        ->call('save')
        ->assertSet('messageVariant', 'danger'); // the service ceiling throw is caught + flashed

    expect(Role::where('name', 'Overreach')->exists())->toBeFalse();
});

// ── Assign to a custom group ─────────────────────────────────────────────────────────────────────────────

it('assigns a role to a custom group through the builder', function () {
    $admin = roleBuilderAdmin();
    $crew = app(GroupManager::class)->create(['name' => 'Crew']);
    $role = Role::create(['slug' => 'helpers', 'name' => 'Helpers', 'is_preset' => false]);
    $role->permissions()->create(['permission_key' => 'topic.moderate', 'value' => V::Allow->value]);

    Livewire::actingAs($admin)->test('admin.roles')
        ->call('openAssign', $role->id)
        ->set('assignGroupId', $crew->id)
        ->call('assign')
        ->assertHasNoErrors();

    expect(RoleAssignment::where('role_id', $role->id)->where('holder_type', 'group')->where('holder_id', $crew->id)->exists())->toBeTrue();
});

// ── System preset is read-only ───────────────────────────────────────────────────────────────────────────

it('opens a system preset read-only and refuses to save it', function () {
    $preset = Role::where('slug', 'administrator')->firstOrFail();

    Livewire::actingAs(roleBuilderAdmin())->test('admin.roles')
        ->call('edit', $preset->id)
        ->assertSet('editingPreset', true)
        ->call('save')
        ->assertForbidden();
});

// ── Delete ───────────────────────────────────────────────────────────────────────────────────────────────

it('deletes a custom role through the builder', function () {
    $admin = roleBuilderAdmin();
    $role = Role::create(['slug' => 'temp', 'name' => 'Temp', 'is_preset' => false]);

    Livewire::actingAs($admin)->test('admin.roles')
        ->call('askDelete', $role->id)
        ->call('delete')
        ->assertHasNoErrors();

    expect(Role::whereKey($role->id)->exists())->toBeFalse();
});

// ── Destructive actions inherit the admin-tier fence (no non-admin removing admin-built admin-tier roles) ─

it('403s a non-admin who tries to delete a role that carries an admin-tier key', function () {
    $acl = Acl::make();
    $pm = roleBuilderPermManager($acl);
    // An admin-built role containing an Administration-tier key.
    $role = Role::create(['slug' => 'adminbundle', 'name' => 'AdminBundle', 'is_preset' => false]);
    $role->permissions()->create(['permission_key' => 'admin.access', 'value' => V::Allow->value]);

    Livewire::actingAs($pm)->test('admin.roles')
        ->call('askDelete', $role->id)
        ->call('delete')
        ->assertStatus(403);

    expect(Role::whereKey($role->id)->exists())->toBeTrue();
});

it('403s a non-admin who tries to unassign a role that carries an admin-tier key', function () {
    $acl = Acl::make();
    $pm = roleBuilderPermManager($acl);
    $crew = app(GroupManager::class)->create(['name' => 'Crew']);
    $role = Role::create(['slug' => 'adminbundle', 'name' => 'AdminBundle', 'is_preset' => false]);
    $role->permissions()->create(['permission_key' => 'admin.access', 'value' => V::Allow->value]);
    RoleAssignment::create(['role_id' => $role->id, 'holder_type' => 'group', 'holder_id' => $crew->id, 'scope_type' => 'global', 'scope_id' => null]);

    Livewire::actingAs($pm)->test('admin.roles')
        ->call('unassign', $role->id, $crew->id)
        ->assertStatus(403);

    expect(RoleAssignment::where('role_id', $role->id)->exists())->toBeTrue();
});
