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

    /** Re-expand every assignment of a role after its permission set changes (security §1.5). */
    public function reexpand(Role $role): void
    {
        foreach (RoleAssignment::where('role_id', $role->getKey())->get() as $assignment) {
            $this->writeEntries(
                $role,
                (string) $assignment->holder_type,
                (int) $assignment->holder_id,
                new Scope((string) $assignment->scope_type, $assignment->scope_id !== null ? (int) $assignment->scope_id : null),
            );
        }
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
}
