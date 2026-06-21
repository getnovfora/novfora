<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Permissions\PermissionValue as V;
use App\Permissions\RoleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Acl;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function rcm(): RoleManager
{
    return app(RoleManager::class);
}

function rcloneAdmin(): User
{
    return Users::withTwoFactor(Users::inGroups(['admins']));
}

/** Build a custom role with a known three-state value map via the manager (actor = full admin). */
function rcMakeRole(string $name, array $values): Role
{
    return rcm()->save(null, $name, $values, rcloneAdmin());
}

/** A non-admin who reaches the builder gate (admin.access + permissions.manage) but is NOT staff. */
function rcPermManager(Acl $acl): User
{
    $pm = $acl->group('permmgr', ['priority' => 50]);
    $acl->grant($pm, 'admin.access', $acl->global, V::Allow);
    $acl->grant($pm, 'permissions.manage', $acl->global, V::Allow);

    return $acl->user(['permmgr']);
}

it('clones a custom role: copies its permission rows exactly into a new editable, unassigned role', function () {
    $source = rcMakeRole('Greeter', ['forum.view' => 'yes', 'post.create' => 'yes', 'pm.send' => 'never']);
    $clone = rcm()->clone($source);

    expect($clone->id)->not->toBe($source->id)
        ->and($clone->name)->toBe('Greeter (copy)')
        ->and($clone->is_preset)->toBeFalse()
        ->and($clone->slug)->not->toBe($source->slug);

    // Permission rows copied exactly — same keys, same three-state values (ALLOW/NEVER preserved).
    expect(rcm()->valueMap($clone))->toEqual(rcm()->valueMap($source));

    // Unassigned: it grants nothing until an operator applies it as a group baseline.
    expect(RoleAssignment::where('role_id', $clone->id)->exists())->toBeFalse();
});

it('clones a read-only system preset into an editable custom role (is_preset = false)', function () {
    $preset = Role::where('is_preset', true)->where('slug', 'moderator')->firstOrFail();
    $clone = rcm()->clone($preset);

    expect($clone->is_preset)->toBeFalse()
        ->and(rcm()->valueMap($clone))->toEqual(rcm()->valueMap($preset))
        ->and(RoleAssignment::where('role_id', $clone->id)->exists())->toBeFalse();
});

it('keeps the clone independent — editing it does not change the source', function () {
    $source = rcMakeRole('Base', ['forum.view' => 'yes', 'post.create' => 'yes']);
    $clone = rcm()->clone($source);

    rcm()->save($clone, $clone->name, ['forum.view' => 'yes'], rcloneAdmin()); // drop post.create on the clone only

    expect(rcm()->valueMap($source))->toHaveKey('post.create')
        ->and(rcm()->valueMap($clone->fresh()))->not->toHaveKey('post.create');
});

it('blocks a non-admin permissions.manager from cloning an admin-tier role (no escalation path)', function () {
    $acl = Acl::make();
    $manager = rcPermManager($acl);
    $adminPreset = Role::where('is_preset', true)->where('slug', 'administrator')->firstOrFail();

    Livewire::actingAs($manager->fresh())->test('admin.roles')
        ->call('cloneRole', $adminPreset->id)
        ->assertStatus(403);

    expect(Role::where('name', $adminPreset->name.' (copy)')->exists())->toBeFalse();
});

it('lets a non-admin permissions.manager clone a non-admin-tier role', function () {
    $acl = Acl::make();
    $manager = rcPermManager($acl);
    $custom = rcMakeRole('Helpers', ['forum.view' => 'yes']);

    Livewire::actingAs($manager->fresh())->test('admin.roles')
        ->call('cloneRole', $custom->id)
        ->assertHasNoErrors();

    expect(Role::where('name', 'Helpers (copy)')->exists())->toBeTrue();
});
