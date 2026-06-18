<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Permissions;

use App\Models\AclEntry;
use App\Models\Group;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;

/**
 * Roles are reusable bundles of three-state values. They are NOT a separate evaluation layer — on
 * assignment they EXPAND into acl_entries (security §1.1), which is all the resolver ever reads.
 *
 * Expansion is idempotent (updateOrCreate keyed by holder + permission + scope), so re-seeding or
 * re-expanding after a role's permission set changes (§1.5) converges rather than duplicating.
 *
 * CONVERGENCE (ACP v3 · v3-d): a bare re-expand only ADDS/updates rows — a key DROPPED from the role would
 * linger on every holder as a stale grant. {@see reexpand()} therefore also deletes a caller-supplied set of
 * dropped keys, and {@see retract()} removes a role's whole footprint on delete. Both are KEY-SCOPED: they touch
 * ONLY the keys named, so a grant on a DIFFERENT key at the same (holder, scope) — a card-editor override on
 * another permission — is never collaterally removed. Provenance caveat: acl_entries has no `role_id`, so a key
 * that is BOTH in the role AND independently set by the card editor on the same (holder, scope) is one physical
 * row; removing the role removes it (last-writer-wins — a group is managed by a role baseline OR the card editor
 * on a given key, not both; ADR-0084). Query-builder deletes skip the AclEntry `deleted` event (G9), so both
 * return the row count deleted and the POLICY caller (RoleManager) bumps AclVersion when anything went.
 */
final class RoleExpander
{
    /** Assign a role to a holder at a scope and expand its permissions into acl_entries. */
    public function assign(Role $role, string $holderType, int $holderId, ?Scope $scope = null): void
    {
        $scope ??= Scope::global();

        $role->assignments()->updateOrCreate([
            'holder_type' => $holderType,
            'holder_id' => $holderId,
            'scope_type' => $scope->type,
            'scope_id' => $scope->id,
        ]);

        $this->writeEntries($role, $holderType, $holderId, $scope);
    }

    public function assignToGroup(Role $role, Group $group, ?Scope $scope = null): void
    {
        $this->assign($role, 'group', (int) $group->id, $scope);
    }

    /**
     * Re-expand every assignment of a role after its permission set changes (security §1.5). The role's CURRENT
     * keys are upserted onto each holder; every key in $droppedKeys (those the edit removed from the role) is
     * deleted from each holder at that assignment's scope — the convergence the blunt upsert alone cannot do.
     *
     * @param  list<string>  $droppedKeys  keys removed from the role since it was last expanded
     * @return int acl_entries rows deleted across all holders (so the policy caller bumps AclVersion once)
     */
    public function reexpand(Role $role, array $droppedKeys = []): int
    {
        $deleted = 0;

        foreach (RoleAssignment::where('role_id', $role->getKey())->get() as $assignment) {
            $scope = new Scope(
                (string) $assignment->scope_type,
                $assignment->scope_id !== null ? (int) $assignment->scope_id : null,
            );
            $holderType = (string) $assignment->holder_type;
            $holderId = (int) $assignment->holder_id;

            $this->writeEntries($role, $holderType, $holderId, $scope);

            if ($droppedKeys !== []) {
                $deleted += $this->deleteKeys($holderType, $holderId, $scope, $droppedKeys);
            }
        }

        return $deleted;
    }

    /**
     * Remove a role's expanded footprint from every holder (the inverse of assign) — used when a custom role is
     * deleted. Deletes only the role's OWN keys at each assignment's scope, so unrelated grants survive.
     *
     * @return int acl_entries rows deleted (so the policy caller bumps AclVersion once)
     */
    public function retract(Role $role): int
    {
        $keys = RolePermission::where('role_id', $role->getKey())->pluck('permission_key')->all();
        if ($keys === []) {
            return 0;
        }

        $deleted = 0;
        foreach (RoleAssignment::where('role_id', $role->getKey())->get() as $assignment) {
            $scope = new Scope(
                (string) $assignment->scope_type,
                $assignment->scope_id !== null ? (int) $assignment->scope_id : null,
            );
            $deleted += $this->deleteKeys((string) $assignment->holder_type, (int) $assignment->holder_id, $scope, $keys);
        }

        return $deleted;
    }

    private function writeEntries(Role $role, string $holderType, int $holderId, Scope $scope): void
    {
        foreach (RolePermission::where('role_id', $role->getKey())->get() as $permission) {
            AclEntry::updateOrCreate(
                [
                    'permission_key' => $permission->permission_key,
                    'holder_type' => $holderType,
                    'holder_id' => $holderId,
                    'scope_type' => $scope->type,
                    'scope_id' => $scope->id,
                ],
                ['value' => $permission->value],
            );
        }
    }

    /**
     * Delete the named keys' acl_entries for ONE holder at ONE scope (the convergence primitive). A query-builder
     * delete (it bypasses the per-row model event by design — the caller bumps AclVersion); returns rows removed.
     *
     * @param  list<string>  $keys
     */
    private function deleteKeys(string $holderType, int $holderId, Scope $scope, array $keys): int
    {
        return AclEntry::query()
            ->where('holder_type', $holderType)
            ->where('holder_id', $holderId)
            ->where('scope_type', $scope->type)
            ->when($scope->id === null, fn ($q) => $q->whereNull('scope_id'), fn ($q) => $q->where('scope_id', $scope->id))
            ->whereIn('permission_key', $keys)
            ->delete();
    }
}
