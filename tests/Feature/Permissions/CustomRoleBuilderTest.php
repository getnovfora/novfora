<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\AclEntry;
use App\Models\Group;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Permissions\AclVersion;
use App\Permissions\PermissionResolver;
use App\Permissions\PermissionValue as V;
use App\Permissions\RoleException;
use App\Permissions\RoleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Acl;
use Tests\Support\Users;

/*
| ACP v3 · v3-d — the custom-role builder's CORRECTNESS CORE, asserted through the PermissionInspector oracle
| (G4): a built role expands into acl_entries; editing it CONVERGES on every assigned holder (added keys appear,
| dropped keys disappear AND their rows are gone); the escalation fence + ceiling + self-lockout hold; system
| presets are immutable; deleting a role retracts its footprint everywhere and bumps AclVersion.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    app(PermissionResolver::class)->flushMemo();
    $this->seed();
});

/** A full-admin actor (holds every preset key at global; isAdmin() true). */
function roleAdmin(): User
{
    return Users::inGroups(['admins']);
}

/** A non-admin permissions.manager: reaches the builder gate but is NOT a full admin (the escalation subject). */
function permManager(Acl $acl, array $extraGrants = []): User
{
    $pm = $acl->group('permmgr', ['priority' => 50]);
    $acl->grant($pm, 'admin.access', $acl->global, V::Allow);
    $acl->grant($pm, 'permissions.manage', $acl->global, V::Allow);
    foreach ($extraGrants as $key) {
        $acl->grant($pm, $key, $acl->global, V::Allow);
    }

    return $acl->user(['permmgr']);
}

// ── 1. Create + assign → inspector shows the granted verdict at the right scope ──────────────────────────

it('expands a built custom role onto an assigned group (verdict via the inspector)', function () {
    $acl = Acl::make();
    $crew = $acl->group('crew', ['priority' => 30]); // a custom holder group (not system)
    $user = $acl->user(['members', 'crew']);

    expect($acl->can($user, 'topic.moderate', $acl->forumScope))->toBeFalse();

    $role = app(RoleManager::class)->save(null, 'Crew', ['topic.moderate' => 'yes', 'post.edit.any' => 'yes'], roleAdmin());
    app(RoleManager::class)->assignToGroup($role, $crew, roleAdmin());

    $acl->assertDecision($user, 'topic.moderate', $acl->forumScope, true, 'group_allow');
    $acl->assertDecision($user, 'post.edit.any', $acl->forumScope, true);
});

// ── 2. Edit: ADD a key → appears on holders ──────────────────────────────────────────────────────────────

it('converges an ADDED key onto every assigned holder', function () {
    $acl = Acl::make();
    $crew = $acl->group('crew', ['priority' => 30]);
    $user = $acl->user(['members', 'crew']);

    $role = app(RoleManager::class)->save(null, 'Crew', ['topic.moderate' => 'yes'], roleAdmin());
    app(RoleManager::class)->assignToGroup($role, $crew, roleAdmin());
    expect($acl->can($user, 'post.delete.any', $acl->forumScope))->toBeFalse();

    app(RoleManager::class)->save($role->fresh(), 'Crew', ['topic.moderate' => 'yes', 'post.delete.any' => 'yes'], roleAdmin());

    $acl->assertDecision($user, 'post.delete.any', $acl->forumScope, true);
});

// ── 3. Edit: REMOVE a key → DISAPPEARS from holders, and the row is actually gone (THE convergence test) ──

it('converges a DROPPED key off every assigned holder and deletes its acl_entries row', function () {
    $acl = Acl::make();
    $crew = $acl->group('crew', ['priority' => 30]);
    $user = $acl->user(['members', 'crew']);

    $role = app(RoleManager::class)->save(null, 'Crew', ['topic.moderate' => 'yes', 'post.edit.any' => 'yes'], roleAdmin());
    app(RoleManager::class)->assignToGroup($role, $crew, roleAdmin());
    $acl->assertDecision($user, 'topic.moderate', $acl->forumScope, true);

    $versionBefore = app(AclVersion::class)->current();

    // Drop topic.moderate (omit it → 'no'); keep post.edit.any.
    app(RoleManager::class)->save($role->fresh(), 'Crew', ['post.edit.any' => 'yes'], roleAdmin());

    // The verdict is GONE (inspector oracle) — and so is the underlying row (not merely shadowed).
    $acl->assertDecision($user, 'topic.moderate', $acl->forumScope, false);
    expect(AclEntry::query()->where('holder_type', 'group')->where('holder_id', $crew->id)
        ->where('permission_key', 'topic.moderate')->exists())->toBeFalse();
    // The kept key still resolves; the version bumped so no stale verdict is served.
    $acl->assertDecision($user, 'post.edit.any', $acl->forumScope, true);
    expect(app(AclVersion::class)->current())->toBeGreaterThan($versionBefore);
});

// ── 4. Convergence is SURGICAL: a co-grant at the same holder/scope survives a different key's drop ──────

it('does not clobber a co-grant when a different key is dropped from the role', function () {
    $acl = Acl::make();
    $crew = $acl->group('crew', ['priority' => 30]);
    $user = $acl->user(['members', 'crew']);

    $role = app(RoleManager::class)->save(null, 'Crew', ['topic.moderate' => 'yes', 'post.edit.any' => 'yes'], roleAdmin());
    app(RoleManager::class)->assignToGroup($role, $crew, roleAdmin());

    // An independent grant on the SAME holder at global scope, NOT managed by the role (mirrors the card editor).
    $acl->grant($crew, 'club.manage', $acl->global, V::Allow);
    expect($acl->can($user, 'club.manage', $acl->global))->toBeTrue();

    // Drop post.edit.any from the role.
    app(RoleManager::class)->save($role->fresh(), 'Crew', ['topic.moderate' => 'yes'], roleAdmin());

    expect($acl->can($user, 'post.edit.any', $acl->forumScope))->toBeFalse(); // dropped key gone
    $acl->assertDecision($user, 'club.manage', $acl->global, true);            // the co-grant SURVIVES
    $acl->assertDecision($user, 'topic.moderate', $acl->forumScope, true);     // the kept role key survives
});

// ── 5. Swap a group's role baseline → converges (old-only keys drop, shared keep, new appear) ────────────

it('converges when a group baseline is swapped to a different role', function () {
    $acl = Acl::make();
    $crew = $acl->group('crew', ['priority' => 30]);
    $user = $acl->user(['members', 'crew']);

    $a = app(RoleManager::class)->save(null, 'Alpha', ['topic.moderate' => 'yes', 'post.edit.any' => 'yes'], roleAdmin());
    app(RoleManager::class)->assignToGroup($a, $crew, roleAdmin());
    $b = app(RoleManager::class)->save(null, 'Bravo', ['post.edit.any' => 'yes', 'post.delete.any' => 'yes'], roleAdmin());
    app(RoleManager::class)->assignToGroup($b, $crew, roleAdmin());

    expect($acl->can($user, 'topic.moderate', $acl->forumScope))->toBeFalse();  // only-in-Alpha → dropped
    $acl->assertDecision($user, 'post.edit.any', $acl->forumScope, true);       // shared → kept
    $acl->assertDecision($user, 'post.delete.any', $acl->forumScope, true);     // only-in-Bravo → added
});

// ── 6. Escalation fence: a non-admin cannot mint an Administration-cluster key ───────────────────────────

it('blocks a non-admin permissions.manager from minting an Administration-tier key (no escalation)', function () {
    $acl = Acl::make();
    $pm = permManager($acl);

    expect(fn () => app(RoleManager::class)->save(null, 'Sneaky', ['admin.access' => 'yes'], $pm))
        ->toThrow(RoleException::class);
    expect(Role::where('name', 'Sneaky')->exists())->toBeFalse(); // nothing persisted
});

// ── 7. Ceiling: a non-admin cannot ALLOW a key they do not hold; CAN allow one they do; NEVER is exempt ──

it('enforces the actor ceiling on ALLOW, exempts NEVER, and admits a held key', function () {
    $acl = Acl::make();
    $pm = permManager($acl, ['topic.create']); // holds topic.create, not topic.moderate

    // ALLOW a key beyond the ceiling → refused.
    expect(fn () => app(RoleManager::class)->save(null, 'Over', ['topic.moderate' => 'yes'], $pm))
        ->toThrow(RoleException::class);

    // NEVER (a restriction, not an escalation) on a non-admin-tier key → allowed even though unheld.
    $restrict = app(RoleManager::class)->save(null, 'Restrict', ['topic.moderate' => 'never'], $pm);
    expect($restrict->permissions()->where('permission_key', 'topic.moderate')->value('value'))->toBe(V::Never->value);

    // ALLOW a key the actor DOES hold → allowed.
    $ok = app(RoleManager::class)->save(null, 'Held', ['topic.create' => 'yes'], $pm);
    expect($ok->permissions()->where('permission_key', 'topic.create')->value('value'))->toBe(V::Allow->value);
});

// ── 8. System presets are immutable ──────────────────────────────────────────────────────────────────────

it('refuses to edit or delete a system preset', function () {
    $preset = Role::where('slug', 'administrator')->firstOrFail();

    expect(fn () => app(RoleManager::class)->save($preset, 'Hacked', ['admin.access' => 'yes'], roleAdmin()))
        ->toThrow(RoleException::class);
    expect(fn () => app(RoleManager::class)->delete($preset))->toThrow(RoleException::class);

    expect(Role::where('slug', 'administrator')->where('name', 'Hacked')->exists())->toBeFalse();
});

// ── 9. Delete an assigned custom role → footprint removed everywhere; AclVersion bumped ──────────────────

it('retracts an assigned role from every holder on delete and bumps AclVersion', function () {
    $acl = Acl::make();
    $crew = $acl->group('crew', ['priority' => 30]);
    $user = $acl->user(['members', 'crew']);

    $role = app(RoleManager::class)->save(null, 'Crew', ['topic.moderate' => 'yes'], roleAdmin());
    app(RoleManager::class)->assignToGroup($role, $crew, roleAdmin());
    $acl->assertDecision($user, 'topic.moderate', $acl->forumScope, true);

    $versionBefore = app(AclVersion::class)->current();
    app(RoleManager::class)->delete($role->fresh());

    $acl->assertDecision($user, 'topic.moderate', $acl->forumScope, false);
    expect(AclEntry::where('holder_type', 'group')->where('holder_id', $crew->id)->where('permission_key', 'topic.moderate')->exists())->toBeFalse();
    expect(RoleAssignment::where('role_id', $role->id)->exists())->toBeFalse();
    expect(Role::whereKey($role->id)->exists())->toBeFalse();
    expect(app(AclVersion::class)->current())->toBeGreaterThan($versionBefore);
});

// ── 10. Self-lockout: cannot strip the admins group's admin.access via a role it holds ───────────────────

it('refuses an edit that would strip the admins group baseline of its admin access (self-lockout)', function () {
    $admins = Group::where('slug', 'admins')->firstOrFail();

    // Hand-build the dangerous state the UI never creates: a custom role assigned to the admins group at global.
    $role = app(RoleManager::class)->save(null, 'AdminBaseline', ['admin.access' => 'yes', 'permissions.manage' => 'yes'], roleAdmin());
    RoleAssignment::create([
        'role_id' => $role->id, 'holder_type' => 'group', 'holder_id' => $admins->id,
        'scope_type' => 'global', 'scope_id' => null,
    ]);

    // Dropping admin.access from that role must be refused.
    expect(fn () => app(RoleManager::class)->save($role->fresh(), 'AdminBaseline', ['permissions.manage' => 'yes'], roleAdmin()))
        ->toThrow(RoleException::class);
    // Downgrading it to NEVER is equally refused.
    expect(fn () => app(RoleManager::class)->save($role->fresh(), 'AdminBaseline', ['admin.access' => 'never', 'permissions.manage' => 'yes'], roleAdmin()))
        ->toThrow(RoleException::class);
});

// ── 11. Assignment refuses system groups (admins called out specifically) ────────────────────────────────

it('refuses to assign a custom role baseline to a system group', function () {
    $role = app(RoleManager::class)->save(null, 'Crew', ['topic.moderate' => 'yes'], roleAdmin());

    $admins = Group::where('slug', 'admins')->firstOrFail();
    $mods = Group::where('slug', 'moderators')->firstOrFail();

    expect(fn () => app(RoleManager::class)->assignToGroup($role, $admins, roleAdmin()))->toThrow(RoleException::class);
    expect(fn () => app(RoleManager::class)->assignToGroup($role, $mods, roleAdmin()))->toThrow(RoleException::class);
});

// ── 12. Self-lockout holds on the DESTRUCTIVE paths too (delete + unassign), not just edit ───────────────

it('refuses to delete or unassign a role that is the admins group baseline (self-lockout backstop)', function () {
    $admins = Group::where('slug', 'admins')->firstOrFail();

    $role = app(RoleManager::class)->save(null, 'AdminBaseline', ['admin.access' => 'yes', 'permissions.manage' => 'yes'], roleAdmin());
    RoleAssignment::create([
        'role_id' => $role->id, 'holder_type' => 'group', 'holder_id' => $admins->id,
        'scope_type' => 'global', 'scope_id' => null,
    ]);

    expect(fn () => app(RoleManager::class)->delete($role->fresh()))->toThrow(RoleException::class);
    expect(fn () => app(RoleManager::class)->unassignFromGroup($role->fresh(), $admins))->toThrow(RoleException::class);

    // The role + assignment survive the refusal.
    expect(Role::whereKey($role->id)->exists())->toBeTrue();
    expect(RoleAssignment::where('role_id', $role->id)->exists())->toBeTrue();
});
