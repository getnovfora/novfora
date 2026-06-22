<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Admin\GroupException;
use App\Admin\GroupManager;
use App\Models\AclEntry;
use App\Models\AuditLog;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Permissions\AclVersion;
use App\Permissions\GroupPermissionEditor;
use App\Permissions\PermissionResolver;
use App\Permissions\PermissionValue as V;
use App\Permissions\RoleManager;
use App\Permissions\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Acl;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function gcm(): GroupManager
{
    return app(GroupManager::class);
}

/** A non-admin who reaches the ACP gate (admin.access + permissions.manage) but is NOT staff, at a given rank. */
function gcPermManager(Acl $acl, int $priority = 50): User
{
    $pm = $acl->group('permmgr', ['priority' => $priority]);
    $acl->grant($pm, 'admin.access', $acl->global, V::Allow);
    $acl->grant($pm, 'permissions.manage', $acl->global, V::Allow);

    return $acl->user(['permmgr']);
}

// ── The apex pin: exact effective-permission reproduction ─────────────────────────────────────────────────

it('clones a custom group to IDENTICAL effective permissions — ALLOW/NEVER preserved across global + forum', function () {
    $acl = Acl::make();
    $source = $acl->group('vips', ['priority' => 60, 'type' => 'custom', 'is_system' => false]);

    // A deliberate mix across two scopes: ALLOW + NEVER at global, ALLOW + NEVER at a forum scope.
    $acl->grant($source, 'forum.view', $acl->global, V::Allow);
    $acl->grant($source, 'pm.send', $acl->global, V::Never);
    $acl->grant($source, 'post.create', $acl->forumScope, V::Allow);
    $acl->grant($source, 'topic.create', $acl->forumScope, V::Never);

    $clone = gcm()->clone($source->fresh());

    // A member placed in the clone must resolve IDENTICALLY (via the inspector oracle) to a member of the source.
    $srcMember = $acl->user(['vips']);
    $cloneMember = User::factory()->create();
    $cloneMember->groups()->attach($clone->id, ['is_primary' => true]);
    $cloneMember = $cloneMember->fresh();

    $cases = [
        ['forum.view', $acl->global],
        ['pm.send', $acl->global],
        ['post.create', $acl->forumScope],
        ['topic.create', $acl->forumScope],
    ];
    foreach ($cases as [$key, $scope]) {
        $expected = $acl->can($srcMember, $key, $scope);              // the source's effective verdict
        $acl->assertDecision($cloneMember, $key, $scope, $expected);  // clone ≡ source, cache agrees with the oracle
    }

    // The load-bearing invariant spelled out: the NEVER stays NEVER on the clone.
    expect($acl->can($cloneMember, 'pm.send', $acl->global))->toBeFalse()
        ->and($acl->can($cloneMember, 'topic.create', $acl->forumScope))->toBeFalse();
});

it('clones a group\'s role baseline so the clone resolves the role keys and stays role-managed', function () {
    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));
    $moderator = Role::where('slug', 'moderator')->firstOrFail();
    $source = gcm()->create(['name' => 'VIP Mods', 'priority' => 60, 'role_id' => $moderator->id]);

    $clone = gcm()->clone($source);

    // role_assignment copied (so future role edits converge onto the clone too).
    expect(RoleAssignment::where('holder_type', 'group')->where('holder_id', $clone->id)->where('role_id', $moderator->id)->exists())->toBeTrue();

    // A clone member resolves topic.moderate — the moderator role expanded onto the clone's acl_entries.
    $member = Users::inGroups(['members']);
    gcm()->addMembers($clone, [$member->id]);
    app(PermissionResolver::class)->flushMemo();
    expect($member->fresh()->canDo('topic.moderate', Scope::global()))->toBeTrue();
});

it('does NOT resurrect a role key the source had stripped to "no" (no silent widening)', function () {
    // Regression for ADR-0090 / adversarial review: re-expanding the role on clone would resurrect a key the
    // source deleted via the card editor. The clone must mirror the source's PHYSICAL state, not the role.
    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));
    $moderator = Role::where('slug', 'moderator')->firstOrFail();
    $source = gcm()->create(['name' => 'Stripped', 'priority' => 60, 'role_id' => $moderator->id]);

    // Strip one role-granted key on the source via the card-editor primitive (DELETE the physical acl_entry).
    app(GroupPermissionEditor::class)->set($source, 'topic.moderate', Scope::global(), 'no');

    // Sanity: the source resolves topic.moderate as NOT granted now (the row is gone, assignment remains).
    $srcMember = Users::inGroups(['members']);
    gcm()->addMembers($source, [$srcMember->id]);
    app(PermissionResolver::class)->flushMemo();
    expect($srcMember->fresh()->canDo('topic.moderate', Scope::global()))->toBeFalse();

    $clone = gcm()->clone($source->fresh());

    // The clone must NOT have resurrected topic.moderate — neither as a resolved grant nor a physical row.
    $cloneMember = Users::inGroups(['members']);
    gcm()->addMembers($clone, [$cloneMember->id]);
    app(PermissionResolver::class)->flushMemo();
    expect($cloneMember->fresh()->canDo('topic.moderate', Scope::global()))->toBeFalse()
        ->and(AclEntry::where('holder_type', 'group')->where('holder_id', $clone->id)->where('permission_key', 'topic.moderate')->exists())->toBeFalse();
});

it('a manual override that differs from the role wins on the clone, exactly as on the source', function () {
    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));
    $moderator = Role::where('slug', 'moderator')->firstOrFail();
    $source = gcm()->create(['name' => 'Quirky', 'priority' => 60, 'role_id' => $moderator->id]);

    // Override one role-granted key to NEVER directly on the source group (card-editor style).
    AclEntry::updateOrCreate(
        ['permission_key' => 'topic.moderate', 'holder_type' => 'group', 'holder_id' => (int) $source->id, 'scope_type' => 'global', 'scope_id' => null],
        ['value' => V::Never->value],
    );

    $clone = gcm()->clone($source->fresh());

    $member = Users::inGroups(['members']);
    gcm()->addMembers($clone, [$member->id]);
    app(PermissionResolver::class)->flushMemo();
    // The override (NEVER) must win on the clone, not the role's ALLOW.
    expect($member->fresh()->canDo('topic.moderate', Scope::global()))->toBeFalse();
});

// ── AclVersion / cache freshness ─────────────────────────────────────────────────────────────────────────

it('bumps the ACL version so a resolve right after clone is never stale', function () {
    $acl = Acl::make();
    $source = $acl->group('vips', ['priority' => 60, 'type' => 'custom', 'is_system' => false]);
    $acl->grant($source, 'forum.view', $acl->global, V::Allow);

    $before = app(AclVersion::class)->current();
    gcm()->clone($source->fresh());
    expect(app(AclVersion::class)->current())->toBeGreaterThan($before);
});

// ── Membership / flags ───────────────────────────────────────────────────────────────────────────────────

it('starts the clone with ZERO members (no membership/co-owner/primary carried over)', function () {
    $acl = Acl::make();
    $source = $acl->group('vips', ['priority' => 60, 'type' => 'custom', 'is_system' => false]);
    $acl->grant($source, 'forum.view', $acl->global, V::Allow);
    $acl->user(['vips']); // give the source a member

    expect($source->fresh()->users()->count())->toBe(1);

    $clone = gcm()->clone($source->fresh());
    expect($clone->users()->count())->toBe(0);
});

// ── System/trust exclusion + single audit ────────────────────────────────────────────────────────────────

it('refuses to clone a system or trust group', function () {
    foreach (['admins', 'moderators', 'members', 'guests', 'tl0'] as $slug) {
        $g = Group::where('slug', $slug)->firstOrFail();
        expect(fn () => gcm()->clone($g))->toThrow(GroupException::class);
        expect(Group::where('name', $g->name.' (copy)')->exists())->toBeFalse();
    }
});

it('writes exactly one audit entry for the clone', function () {
    $acl = Acl::make();
    $source = $acl->group('vips', ['priority' => 60, 'type' => 'custom', 'is_system' => false]);
    $acl->grant($source, 'forum.view', $acl->global, V::Allow);

    gcm()->clone($source->fresh());
    expect(AuditLog::where('action', 'group.cloned')->count())->toBe(1);
});

// ── No escalation: rank guard + admin-tier fence at the SFC ───────────────────────────────────────────────

it('blocks a non-admin from cloning a group that holds an admin-tier key', function () {
    $acl = Acl::make();
    $source = $acl->group('mini-admin', ['priority' => 40, 'type' => 'custom', 'is_system' => false]);
    $adminKey = (string) Permission::where('group', 'Administration')->value('key');
    $acl->grant($source, $adminKey, $acl->global, V::Allow);

    $manager = gcPermManager($acl, 50); // outranks 40, but the group bears an admin-tier grant

    Livewire::actingAs($manager->fresh())->test('admin.groups')
        ->call('clone', $source->id)
        ->assertStatus(403);

    expect(Group::where('name', 'Mini-admin (copy)')->exists())->toBeFalse();
});

it('blocks a non-admin from cloning a group assigned an admin-tier ROLE (even with the acl_entry stripped)', function () {
    // Admin builds: a custom role bearing an admin-tier key, assigned to a low-rank custom group, then strips
    // the expanded admin-tier acl_entry via the card editor — the role ASSIGNMENT survives. A non-admin who
    // outranks the group must STILL be refused (cloning would copy the assignment; a re-expansion mints the key).
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));
    $this->actingAs($admin);

    $adminKey = (string) Permission::where('group', 'Administration')->value('key');
    $role = app(RoleManager::class)->save(null, 'Sneaky', [$adminKey => 'yes'], $admin);
    $group = gcm()->create(['name' => 'Trojan', 'priority' => 40]);
    app(RoleManager::class)->assignToGroup($role, $group, $admin);
    app(GroupPermissionEditor::class)->set($group->fresh(), $adminKey, Scope::global(), 'no');
    expect(AclEntry::where('holder_type', 'group')->where('holder_id', $group->id)->where('permission_key', $adminKey)->exists())->toBeFalse();

    $acl = Acl::make();
    $manager = gcPermManager($acl, 50); // outranks the priority-40 group, but the role carries the admin key

    Livewire::actingAs($manager->fresh())->test('admin.groups')
        ->call('clone', $group->id)
        ->assertStatus(403);
});

it('blocks cloning a group ranked at or above the actor (rank guard)', function () {
    $acl = Acl::make();
    $source = $acl->group('bigs', ['priority' => 70, 'type' => 'custom', 'is_system' => false]);
    $acl->grant($source, 'forum.view', $acl->global, V::Allow);

    $manager = gcPermManager($acl, 50); // 50 < 70 → cannot edit/clone it

    Livewire::actingAs($manager->fresh())->test('admin.groups')
        ->call('clone', $source->id)
        ->assertStatus(403);
});

it('lets a non-admin permissions.manager clone a normal custom group they outrank', function () {
    $acl = Acl::make();
    $source = $acl->group('helpers', ['priority' => 30, 'type' => 'custom', 'is_system' => false]);
    $acl->grant($source, 'forum.view', $acl->global, V::Allow);

    $manager = gcPermManager($acl, 50); // outranks 30, no admin-tier key

    Livewire::actingAs($manager->fresh())->test('admin.groups')
        ->call('clone', $source->id)
        ->assertHasNoErrors();

    expect(Group::where('name', 'Helpers (copy)')->exists())->toBeTrue();
});
