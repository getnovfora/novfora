<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Admin;

use App\Models\AclEntry;
use App\Models\Group;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Permissions\RoleExpander;
use App\Permissions\Scope;
use App\Support\Audit;
use App\Support\GroupColor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ACP v2 — all member-group domain logic (the group-manager SFC is just the UI + the self-guard). Enforces
 * the binding safety rules:
 *   • SYSTEM-GROUP PROTECTION — seeded groups (Guests, Members, the trust levels, the staff roles) cannot be
 *     deleted or re-typed; only their cosmetic label/colour/description change. Their permission preset is
 *     their identity and is never reassigned here.
 *   • DELETE SAFETY — a custom group with members is deleted only by REASSIGNING those members to another
 *     group first (mirrors the structure manager); never orphans a membership.
 *   • MEMBERSHIP BOUNDARY — manual add/remove is for custom + staff groups only; trust-level groups are
 *     engine-managed (the trust recompute) and the base Guests/Members groups are assigned automatically.
 *   • PERMISSIONS THROUGH THE ENGINE — a group's permissions are set by assigning a Role preset, which the
 *     RoleExpander expands into acl_entries (the only thing the resolver reads). No second permission system,
 *     no new mask semantics. acl_entry writes auto-bump the ACL version; membership changes self-invalidate
 *     via the per-user group signature, so no manual cache busting is needed.
 */
final class GroupManager
{
    public function __construct(private readonly RoleExpander $expander) {}

    /** @param array<string,mixed> $data */
    public function create(array $data): Group
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new GroupException('A group name is required.');
        }

        $group = Group::create([
            'slug' => $this->uniqueSlug($name),
            'name' => $name,
            'color' => $this->cleanColor($data['color'] ?? null),
            'description' => $this->cleanDescription($data['description'] ?? null),
            'type' => 'custom',
            'priority' => $this->cleanPriority($data['priority'] ?? 50),
            'is_system' => false,
            'auto_promotion' => null,
        ]);

        if (! empty($data['role_id'])) {
            $this->setRole($group, Role::find((int) $data['role_id']));
        }

        Audit::log('group.created', $group, ['name' => $name, 'type' => 'custom']);

        return $group;
    }

    /** @param array<string,mixed> $data */
    public function update(Group $group, array $data): Group
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new GroupException('A group name is required.');
        }

        // Cosmetic fields are editable on EVERY group (including system); structural identity is not.
        $attributes = [
            'name' => $name,
            'color' => $this->cleanColor($data['color'] ?? null),
            'description' => $this->cleanDescription($data['description'] ?? null),
        ];

        // Priority + the permission preset are editable for CUSTOM groups only — a system group's priority
        // (rank) and role preset are its identity and feed the trust engine / permission seeds.
        if (! $group->is_system) {
            $attributes['priority'] = $this->cleanPriority($data['priority'] ?? $group->priority);
        }

        $group->update($attributes);

        if (! $group->is_system && array_key_exists('role_id', $data)) {
            $this->setRole($group, ! empty($data['role_id']) ? Role::find((int) $data['role_id']) : null);
        }

        Audit::log('group.updated', $group, ['name' => $name]);

        return $group->refresh();
    }

    /** Delete a custom group, reassigning its members first. Returns the number reassigned. */
    public function delete(Group $group, ?Group $reassignTo = null): int
    {
        if ($group->is_system) {
            throw new GroupException("“{$group->name}” is a system group and cannot be deleted.");
        }

        $memberCount = $group->users()->count();
        if ($memberCount > 0 && $reassignTo === null) {
            throw new GroupException("“{$group->name}” has {$memberCount} member(s) — choose a group to reassign them to before deleting it.");
        }
        if ($reassignTo !== null && (int) $reassignTo->getKey() === (int) $group->getKey()) {
            throw new GroupException('Members cannot be reassigned into the group being deleted.');
        }
        // The reassign target is a hand-edit of membership, so it honours the SAME boundary as addMembers():
        // never inject real users into a trust-level group (engine-managed) or the Guests/Members base groups.
        if ($reassignTo !== null && ! $this->manualMembershipAllowed($reassignTo)) {
            throw new GroupException("Members can't be reassigned into “{$reassignTo->name}” — its membership is managed automatically (a trust or base group).");
        }

        $reassigned = 0;
        DB::transaction(function () use ($group, $reassignTo, &$reassigned): void {
            if ($reassignTo !== null) {
                foreach ($group->users()->pluck('users.id') as $userId) {
                    $reassignTo->users()->syncWithoutDetaching([(int) $userId => ['is_primary' => false]]);
                    $reassigned++;
                }
            }
            $group->users()->detach();

            // Remove the group's permission footprint (role assignment + expanded acl_entries).
            AclEntry::query()->where('holder_type', 'group')->where('holder_id', $group->getKey())->delete();
            RoleAssignment::query()->where('holder_type', 'group')->where('holder_id', $group->getKey())->delete();

            $group->delete();
        });

        Audit::log('group.deleted', null, ['name' => $group->name, 'reassigned' => $reassigned, 'to' => $reassignTo?->name]);

        return $reassigned;
    }

    /**
     * Add users to a group (manual membership). Returns the number newly added.
     *
     * @param  list<int>  $userIds
     */
    public function addMembers(Group $group, array $userIds): int
    {
        $this->assertManualMembership($group);

        $ids = array_values(array_filter(
            array_unique(array_map('intval', $userIds)),
            fn (int $id): bool => $id > 0 && User::whereKey($id)->exists(),
        ));
        if ($ids === []) {
            return 0;
        }

        $before = $group->users()->count();
        $group->users()->syncWithoutDetaching(
            collect($ids)->mapWithKeys(fn (int $id): array => [$id => ['is_primary' => false]])->all(),
        );
        $added = $group->users()->count() - $before;

        if ($added > 0) {
            Audit::log('group.members.added', $group, ['count' => $added]);
        }

        return $added;
    }

    public function removeMember(Group $group, int $userId): void
    {
        $this->assertManualMembership($group);

        if ($group->users()->detach($userId) > 0) {
            Audit::log('group.members.removed', $group, ['user_id' => $userId]);
        }
    }

    /** Swap a group's permission preset: clear its current expansion, then expand the new role (if any). */
    public function setRole(Group $group, ?Role $role): void
    {
        // This rewrites the effective permissions of every member of the group, so it is audited from/to —
        // a permission change must be traceable, distinct from a cosmetic rename (security §3 / CLAUDE.md).
        $previousRoleId = RoleAssignment::query()
            ->where('holder_type', 'group')->where('holder_id', $group->getKey())->value('role_id');

        AclEntry::query()->where('holder_type', 'group')->where('holder_id', $group->getKey())->delete();
        RoleAssignment::query()->where('holder_type', 'group')->where('holder_id', $group->getKey())->delete();

        if ($role !== null) {
            $this->expander->assignToGroup($role, $group, Scope::global());
        }

        if ((int) $previousRoleId !== (int) ($role?->id)) {
            Audit::log('group.role.assigned', $group, [
                'from_role_id' => $previousRoleId !== null ? (int) $previousRoleId : null,
                'to_role_id' => $role?->id,
                'to_role' => $role?->name,
            ]);
        }
    }

    /** Manual membership is for custom + staff groups; trust groups are engine-managed; Guests/Members are base. */
    public function manualMembershipAllowed(Group $group): bool
    {
        return $group->type !== 'trust' && ! in_array($group->slug, ['guests', 'members'], true);
    }

    private function assertManualMembership(Group $group): void
    {
        if (! $this->manualMembershipAllowed($group)) {
            $why = $group->type === 'trust'
                ? 'Trust-level groups are assigned automatically by the trust engine.'
                : 'It is a base group assigned automatically.';
            throw new GroupException("“{$group->name}” membership can't be edited by hand — {$why}");
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'group';
        $slug = $base;
        $n = 2;
        while (Group::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    private function cleanColor(mixed $color): ?string
    {
        $color = is_string($color) ? trim($color) : null;

        return GroupColor::isValid($color) ? $color : null;
    }

    private function cleanDescription(mixed $description): ?string
    {
        $description = is_string($description) ? trim($description) : '';

        return $description === '' ? null : Str::limit($description, 255, '');
    }

    private function cleanPriority(mixed $priority): int
    {
        // Strictly BELOW Moderators (80) so a custom group can never out-rank staff and shield an account from
        // the actor-vs-target rank guard (phase-1.5 F-F); still above Members (10) and the trust levels (1–5).
        return max(1, min(79, (int) $priority));
    }
}
