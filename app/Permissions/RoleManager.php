<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Permissions;

use App\Models\AclEntry;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ACP v3 · v3-d — the custom-role builder's domain logic (the ⚡roles SFC is just the UI + the self-guard).
 *
 * A custom role (`is_preset = false`) is a reusable bundle of three-state values over the permission catalog.
 * It is not a second evaluation layer: assigning it to a group EXPANDS it into that group's acl_entries via
 * {@see RoleExpander} (the only thing the resolver reads). System presets (`is_preset = true`, seeded by
 * RoleSeeder) are READ-ONLY here — their permission set is their identity and the engine's seed.
 *
 * The apex correctness lives in three guards, enforced HERE (the SFC pre-checks for a clean 403; this throws as
 * the actor-independent backstop, exactly like GroupPermissionEditor):
 *   • ESCALATION FENCE — only a full admin may put an Administration-cluster key in a role (the v3-c-class HIGH);
 *     and no actor may ALLOW a key beyond their OWN effective ceiling.
 *   • SELF-LOCKOUT — no edit/assignment may strip the administrators group's own admin access.
 *   • CONVERGENCE — editing a role's set deletes keys dropped from it off EVERY assigned holder, and deleting a
 *     role retracts its whole footprint; both bump AclVersion (G9 — query-builder deletes skip model events).
 */
final class RoleManager
{
    /** Stripped from the system admins group at global scope, these would brick ACP recovery for everyone. */
    public const ADMIN_RECOVERY_KEYS = ['admin.access', 'permissions.manage'];

    public function __construct(
        private readonly RoleExpander $expander,
        private readonly AclVersion $version,
    ) {}

    /**
     * The Administration-cluster keys — the escalation-sensitive set only a full admin may grant or deny. Driven
     * by the permission catalog's `group` field (the single source of truth, shared with the v3-c card editor).
     *
     * @return list<string>
     */
    public function adminTierKeys(): array
    {
        return Permission::query()->where('group', 'Administration')->pluck('key')->all();
    }

    /** Custom (non-preset) roles, for the builder list. @return \Illuminate\Support\Collection<int,Role> */
    public function customRoles()
    {
        return Role::query()->where('is_preset', false)->orderBy('name')->get();
    }

    /**
     * The role's value map: [permission_key => engine value (1|-1)]. A 'no' (inherit) is simply absent.
     *
     * @return array<string,int>
     */
    public function valueMap(Role $role): array
    {
        return $role->permissions()->pluck('value', 'permission_key')
            ->map(fn ($v): int => (int) $v)->all();
    }

    /** Group ids this role is assigned to (for the builder's "assigned to" display). @return list<int> */
    public function assignedGroupIds(Role $role): array
    {
        return RoleAssignment::query()
            ->where('role_id', $role->getKey())
            ->where('holder_type', 'group')
            ->pluck('holder_id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * Create or update a custom role from a three-state value map. $values maps catalog keys to 'yes'|'no'|'never'
     * (only 'yes'/'never' are stored — 'no' means the key is absent from the role). Converges every assigned
     * holder. ALL guards run before any write, so a rejected edit leaves no partial state.
     *
     * @param  array<string,string>  $values
     */
    public function save(?Role $role, string $name, array $values, User $actor, ?string $description = null): Role
    {
        $name = trim($name);
        if ($name === '') {
            throw new RoleException('A role name is required.');
        }
        if ($role !== null && $role->is_preset) {
            throw new RoleException("“{$role->name}” is a system preset and cannot be edited.");
        }

        $newMap = $this->sanitizeValues($values);          // [key => 1|-1], catalog-filtered
        $this->assertWithinCeiling($newMap, $actor);        // escalation fence (admin-tier + ALLOW ceiling)

        $oldKeys = $role !== null ? array_keys($this->valueMap($role)) : [];
        $dropped = array_values(array_diff($oldKeys, array_keys($newMap)));

        // Self-lockout: an existing role assigned to the admins group must keep its recovery keys as ALLOW —
        // a drop OR a downgrade to NEVER would lock everyone out (defence-in-depth; the UI never assigns a custom
        // role to a system group, so this only fires for a hand-built dangerous state).
        if ($role !== null && $this->assignedToAdminsGlobal($role)) {
            $this->assertRecoveryPreserved($newMap);
        }

        return DB::transaction(function () use ($role, $name, $description, $newMap, $dropped): Role {
            $role = $this->upsertRole($role, $name, $description);

            // Sync the role's permission rows to the new map: drop the rows that left, upsert the rest. Each
            // RolePermission save/delete bumps AclVersion via its model event (so caches refresh on its own).
            RolePermission::query()
                ->where('role_id', $role->getKey())
                ->whereNotIn('permission_key', array_keys($newMap) ?: [''])
                ->get()->each->delete();
            foreach ($newMap as $key => $value) {
                $role->permissions()->updateOrCreate(['permission_key' => $key], ['value' => $value]);
            }

            // Converge every assigned holder: upsert the current keys, delete the dropped ones. Query-builder
            // deletes skip the AclEntry event, so bump explicitly (G9) — once, regardless of how many rows went.
            $this->expander->reexpand($role, $dropped);
            $this->version->bump();

            Audit::log($role->wasRecentlyCreated ? 'role.created' : 'role.updated', $role, [
                'name' => $name,
                'keys' => count($newMap),
                'dropped' => $dropped,
            ]);

            return $role;
        });
    }

    /**
     * Assign a custom role to a group as its permission baseline → expands into the group's acl_entries. Re-checks
     * the escalation fence against the role's CURRENT keys (a non-admin must not assign an admin-built admin-tier
     * role), and converges if the group already had a role (the swapped-out role's dropped keys are removed).
     */
    public function assignToGroup(Role $role, Group $group, User $actor): void
    {
        $map = $this->valueMap($role);
        $this->assertWithinCeiling($map, $actor); // assigning grants the role's keys → actor must be able to grant

        // Self-lockout + system-group protection: a role baseline is for CUSTOM groups only (a system group's
        // permissions are its seeded identity). The admins case is called out first so the message is specific —
        // assigning a role that doesn't grant admin access to the admins group would lock everyone out.
        if ($group->slug === 'admins') {
            throw new RoleException("The administrators group's permissions are fixed — reassigning its role could lock everyone out of the admin panel.");
        }
        if ($group->is_system) {
            throw new RoleException("“{$group->name}” is a system group — its permissions are managed by the engine and cannot be replaced with a role.");
        }

        $previous = $this->groupRoleId($group);
        $dropped = [];
        if ($previous !== null && $previous !== (int) $role->getKey()) {
            $prevKeys = RolePermission::where('role_id', $previous)->pluck('permission_key')->all();
            $dropped = array_values(array_diff($prevKeys, array_keys($map)));
        }

        DB::transaction(function () use ($role, $group, $previous, $dropped): void {
            // One role baseline per group: clear a different previous assignment (its expanded keys converge below).
            if ($previous !== null && $previous !== (int) $role->getKey()) {
                RoleAssignment::query()->where('holder_type', 'group')->where('holder_id', $group->getKey())
                    ->where('scope_type', 'global')->delete();
            }

            $this->expander->assignToGroup($role, $group, Scope::global()); // upserts the role's keys (+ assignment)

            // Delete the swapped-out role's now-orphaned keys from this group at global scope (convergence).
            if ($dropped !== []) {
                AclEntry::query()->where('holder_type', 'group')->where('holder_id', $group->getKey())
                    ->where('scope_type', 'global')->whereNull('scope_id')->whereIn('permission_key', $dropped)->delete();
            }
            $this->version->bump();

            Audit::log('role.assigned', $role, [
                'group_id' => (int) $group->getKey(),
                'group' => $group->name,
                'from_role_id' => $previous,
            ]);
        });
    }

    /** Remove a role's baseline from a group (clears its expanded keys at global scope). */
    public function unassignFromGroup(Role $role, Group $group): void
    {
        $keys = RolePermission::where('role_id', $role->getKey())->pluck('permission_key')->all();
        $this->assertNotStrippingAdminsRecovery($group, $keys); // self-lockout backstop (destructive path)

        DB::transaction(function () use ($role, $group, $keys): void {
            $removed = RoleAssignment::query()->where('role_id', $role->getKey())
                ->where('holder_type', 'group')->where('holder_id', $group->getKey())->delete();
            if ($removed === 0) {
                return;
            }
            if ($keys !== []) {
                AclEntry::query()->where('holder_type', 'group')->where('holder_id', $group->getKey())
                    ->where('scope_type', 'global')->whereNull('scope_id')->whereIn('permission_key', $keys)->delete();
            }
            $this->version->bump();
            Audit::log('role.unassigned', $role, ['group_id' => (int) $group->getKey(), 'group' => $group->name]);
        });
    }

    /**
     * Delete a custom role: retract its expanded footprint from EVERY holder, drop its assignments, then the role
     * (cascading its permission rows), and bump AclVersion. No orphaned grants are left behind. Presets cannot be
     * deleted (their permission set seeds the engine).
     */
    public function delete(Role $role): void
    {
        if ($role->is_preset) {
            throw new RoleException("“{$role->name}” is a system preset and cannot be deleted.");
        }

        // Self-lockout backstop (actor-independent, mirrors GroupPermissionEditor::protectsAdminRecovery): deleting
        // a role retracts its keys from EVERY holder, so a role that is the admins group's baseline must never be
        // deleted out from under a recovery key — that would lock everyone out of the panel.
        $keys = RolePermission::where('role_id', $role->getKey())->pluck('permission_key')->all();
        if ($this->assignedToAdminsGlobal($role) && array_intersect(self::ADMIN_RECOVERY_KEYS, $keys) !== []) {
            throw new RoleException('Refusing to delete the administrators group’s role baseline — it would lock everyone out of the admin panel.');
        }

        DB::transaction(function () use ($role): void {
            $this->expander->retract($role);                                        // remove acl_entries everywhere
            RoleAssignment::where('role_id', $role->getKey())->delete();            // drop the assignments
            $name = (string) $role->name;
            $role->permissions()->delete();                                         // bulk delete (skips events) before role
            $role->delete();
            $this->version->bump();                                                 // covers the event-skipping deletes (G9)
            Audit::log('role.deleted', null, ['name' => $name]);
        });
    }

    // ── guards / helpers ──────────────────────────────────────────────────────────────────────────────────

    /**
     * The escalation fence (the v3-c-class HIGH). Two rules over the keys being SET (ALLOW or NEVER) in $map:
     *   1. Administration-tier keys may only be touched by a full admin — a non-admin permissions.manage holder
     *      must never mint admin.access (etc.) into a role.
     *   2. Ceiling: an ALLOW may only name a key the actor THEMSELVES hold at global scope (no granting beyond
     *      your own ceiling). NEVER is a restriction, not an escalation, so it is exempt from the ceiling.
     *
     * @param  array<string,int>  $map
     * @param  ?Scope  $scope  the scope the grant targets — the ALLOW ceiling is checked HERE; defaults to global.
     *                         The v3-b {@see ForumModeratorProjector} passes Scope::forum() so a delegated
     *                         forum-mod grant is ceiling-checked against the actor's reach ON THAT FORUM, not
     *                         globally. The admin-tier rule is scope-independent.
     */
    public function assertWithinCeiling(array $map, User $actor, ?Scope $scope = null): void
    {
        $adminTier = $this->adminTierKeys();
        $isAdmin = $actor->isAdmin();

        foreach ($map as $key => $value) {
            if (in_array($key, $adminTier, true) && ! $isAdmin) {
                throw new RoleException("Only a full administrator may put the “{$key}” administration capability in a role.");
            }
            if ($value === PermissionValue::Allow->value && ! $actor->canDo($key, $scope ?? Scope::global())) {
                throw new RoleException("You cannot grant “{$key}” — it exceeds your own permissions.");
            }
        }
    }

    /**
     * Self-lockout backstop for the DESTRUCTIVE paths (delete / unassign): never remove a recovery key from the
     * admins group at global scope. Actor-independent, exactly like GroupPermissionEditor::protectsAdminRecovery.
     *
     * @param  list<string>  $keys  the keys the operation would remove from the group
     */
    private function assertNotStrippingAdminsRecovery(Group $group, array $keys): void
    {
        if ($group->slug === 'admins' && array_intersect(self::ADMIN_RECOVERY_KEYS, $keys) !== []) {
            throw new RoleException('Refusing to remove the administrators group’s admin access — it would lock everyone out of the admin panel.');
        }
    }

    /** @param array<string,int> $map */
    private function assertRecoveryPreserved(array $map): void
    {
        foreach (self::ADMIN_RECOVERY_KEYS as $key) {
            if (($map[$key] ?? null) !== PermissionValue::Allow->value) {
                throw new RoleException("This role is the administrators group's baseline — it must keep “{$key}” allowed or everyone is locked out.");
            }
        }
    }

    /**
     * Filter a submitted state map down to the storable engine values: only catalog keys, only 'yes'/'never'
     * survive (an unknown or half-built entry can never persist as a grant). 'no' / absent = not in the role.
     *
     * @param  array<string,string>  $values
     * @return array<string,int>
     */
    private function sanitizeValues(array $values): array
    {
        $catalog = Permission::query()->pluck('key')->all();
        $map = [];
        foreach ($values as $key => $state) {
            if (! in_array($key, $catalog, true)) {
                continue;
            }
            if ($state === 'yes') {
                $map[$key] = PermissionValue::Allow->value;
            } elseif ($state === 'never') {
                $map[$key] = PermissionValue::Never->value;
            }
        }

        return $map;
    }

    private function upsertRole(?Role $role, string $name, ?string $description): Role
    {
        $description = $description !== null && trim($description) !== '' ? trim($description) : null;

        if ($role === null) {
            return Role::create([
                'slug' => $this->uniqueSlug($name),
                'name' => $name,
                'is_preset' => false,
                'description' => $description,
            ]);
        }

        $role->update(['name' => $name, 'description' => $description]); // slug stays stable across renames
        $role->wasRecentlyCreated = false;

        return $role;
    }

    private function assignedToAdminsGlobal(Role $role): bool
    {
        $admins = Group::where('slug', 'admins')->first();
        if (! $admins instanceof Group) {
            return false;
        }

        return RoleAssignment::query()
            ->where('role_id', $role->getKey())
            ->where('holder_type', 'group')->where('holder_id', $admins->getKey())
            ->where('scope_type', 'global')->exists();
    }

    private function groupRoleId(Group $group): ?int
    {
        $id = RoleAssignment::query()
            ->where('holder_type', 'group')->where('holder_id', $group->getKey())
            ->where('scope_type', 'global')->value('role_id');

        return $id !== null ? (int) $id : null;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'role';
        $slug = $base;
        $n = 2;
        while (Role::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
