<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Admin;

use App\Groups\GroupAutoPromoter;
use App\Groups\GroupDirectory;
use App\Models\AclEntry;
use App\Models\Group;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Permissions\AclVersion;
use App\Permissions\MembershipCache;
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
    public function __construct(
        private readonly RoleExpander $expander,
        private readonly GroupAutoPromoter $autoPromoter,
    ) {}

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
            'auto_promotion' => $this->cleanAutoPromotion($data['auto_promotion'] ?? null),
            'membership_model' => $this->cleanMembershipModel($data['membership_model'] ?? null),
            'is_public' => (bool) ($data['is_public'] ?? false),
        ]);

        if (! empty($data['role_id'])) {
            $this->setRole($group, Role::find((int) $data['role_id']));
        }

        GroupDirectory::forgetEnabled(); // is_public may now make the public directory non-empty
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

        // Priority, the permission preset, and the v3-e membership/auto-promotion config are editable for
        // CUSTOM groups only — a system group's priority (rank) and role preset are its identity and feed the
        // trust engine / permission seeds, and its membership is engine-managed (so no human join model / no
        // auto-promotion config applies).
        if (! $group->is_system) {
            $attributes['priority'] = $this->cleanPriority($data['priority'] ?? $group->priority);
            if (array_key_exists('membership_model', $data)) {
                $attributes['membership_model'] = $this->cleanMembershipModel($data['membership_model']);
            }
            if (array_key_exists('is_public', $data)) {
                $attributes['is_public'] = (bool) $data['is_public'];
            }
            if (array_key_exists('auto_promotion', $data)) {
                $attributes['auto_promotion'] = $this->cleanAutoPromotion($data['auto_promotion']);
            }
        }

        $group->update($attributes);

        if (! $group->is_system && array_key_exists('role_id', $data)) {
            $this->setRole($group, ! empty($data['role_id']) ? Role::find((int) $data['role_id']) : null);
        }

        GroupDirectory::forgetEnabled(); // is_public may have toggled the public directory's emptiness
        Audit::log('group.updated', $group, ['name' => $name]);

        return $group->refresh();
    }

    /**
     * Clone a CUSTOM group into a new group whose effective permissions are IDENTICAL to the source's, with ZERO
     * members — in ONE transaction. The copy is provably exact:
     *   1. a fresh groups row copies the source's cosmetic + structural config (NOT its membership/flags);
     *   2. each of the source's role_assignments is re-expanded onto the clone (so role_assignments AND their
     *      expanded acl_entries stay in sync — the role-derived rows are written by the expander, not hand-copied);
     *   3. then EVERY one of the source's own acl_entries rows is copied verbatim (value + expires_at) across all
     *      scopes. Copying the physical rows AFTER the role expansion reproduces the source's last-writer-wins
     *      state exactly (a card-editor override that differs from the role wins, just as on the source) — and
     *      acl_entries carries no role_id to discriminate provenance, so the physical-row copy IS the exact rule.
     * ALLOW stays ALLOW, NEVER stays NEVER, a 'no' (no row) is simply not copied (→ inherit). System/trust groups
     * are not cloneable (their preset is their seeded identity). Membership, is_co_owner, and is_primary are never
     * copied. The actor-facing escalation guard (rank + admin-tier + manage-permissions) is enforced by the SFC;
     * this throws as the actor-independent backstop for the custom-only invariant.
     */
    public function clone(Group $source): Group
    {
        if ($source->type !== 'custom' || $source->is_system) {
            throw new GroupException("“{$source->name}” is a system group and cannot be cloned — only custom groups can be cloned.");
        }

        return DB::transaction(function () use ($source): Group {
            $name = $source->name.' (copy)';
            $clone = Group::create([
                'slug' => $this->uniqueSlug($name),
                'name' => $name,
                'color' => $source->color,
                'description' => $source->description,
                'type' => 'custom',
                'priority' => (int) $source->priority,
                'is_system' => false,
                'auto_promotion' => $source->auto_promotion,
                'membership_model' => $source->membershipModel(),
                'is_public' => (bool) $source->is_public,
                'show_on_staff_page' => (bool) $source->show_on_staff_page,
                'show_staff_icon' => (bool) $source->show_staff_icon,
                'staff_title' => $source->staff_title,
            ]);

            // 2. Role baseline(s) — re-expand each assigned scope onto the clone (keeps role_assignments ↔ acl_entries in sync).
            foreach (RoleAssignment::query()->where('holder_type', 'group')->where('holder_id', $source->getKey())->get() as $assignment) {
                $role = Role::find($assignment->role_id);
                if (! $role instanceof Role) {
                    continue;
                }
                $scope = new Scope(
                    (string) $assignment->scope_type,
                    $assignment->scope_id !== null ? (int) $assignment->scope_id : null,
                );
                $this->expander->assign($role, 'group', (int) $clone->getKey(), $scope);
            }

            // 3. The source's OWN acl_entries — copy EVERY row verbatim (after step 2 so a manual override wins, exactly as on the source).
            foreach (AclEntry::query()->where('holder_type', 'group')->where('holder_id', $source->getKey())->get() as $entry) {
                AclEntry::updateOrCreate(
                    [
                        'permission_key' => $entry->permission_key,
                        'holder_type' => 'group',
                        'holder_id' => (int) $clone->getKey(),
                        'scope_type' => $entry->scope_type,
                        'scope_id' => $entry->scope_id,
                    ],
                    ['value' => (int) $entry->value, 'expires_at' => $entry->expires_at],
                );
            }

            // 4. Bump the ACL version explicitly (G9). The Eloquent writes above bump via model events, but bump
            //    once more unconditionally so the guarantee holds even for a zero-entry source — a resolve right
            //    after clone must reflect the copied grants, never a stale cached verdict.
            app(AclVersion::class)->bump();

            // 5. Cache + audit (one entry).
            if ($clone->is_public) {
                GroupDirectory::forgetEnabled();
            }
            Audit::log('group.cloned', $clone, [
                'source_id' => (int) $source->getKey(),
                'source' => $source->name,
                'name' => $clone->name,
            ]);

            return $clone;
        });
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

        // The reassign (syncWithoutDetaching) + detach are pivot writes with no model events; the reassigned
        // and detached users' group-sets changed, so drop any verdict memoised this request (v3-e seam). This is
        // a REDUCTION (members lose the deleted group), so bump the version too — a detached user can land back
        // on a previously-cached signature. Cross-request caches otherwise self-heal via the changed signature.
        MembershipCache::flushRequestScopedMemos(bumpVersion: true);
        GroupDirectory::forgetEnabled(); // a deleted public group may have emptied the directory

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
            // The pivot write fires no model events — invalidate each newly-grouped user's resolver caches
            // explicitly (ACP v3 · v3-e seam, ADR-0083). admin-assign is a holder change with no acl_entries write.
            foreach ($ids as $id) {
                if (($u = User::find($id)) instanceof User) {
                    MembershipCache::flushFor($u);
                }
            }
            Audit::log('group.members.added', $group, ['count' => $added]);
        }

        return $added;
    }

    public function removeMember(Group $group, int $userId): void
    {
        $this->assertManualMembership($group);

        DB::transaction(function () use ($group, $userId): void {
            // The admins membership carries co-ownership (the is_co_owner pivot flag + the per-user
            // admin.security.access grant), so removing it is a SECOND door onto the last-owner invariant
            // AdminCoOwnerService::revoke() guards (ADR-0080). Unguarded it could strand the forum at zero
            // co-owners and orphan the security grant. Tear co-ownership down — the locked last-owner guard
            // refuses the sole co-owner — BEFORE the detach drops the pivot flag, atomically in this transaction.
            if ($group->slug === 'admins' && ($target = User::find($userId)) instanceof User) {
                try {
                    app(AdminCoOwnerService::class)->tearDownForAdminsRemoval($target);
                } catch (AdminCoOwnerException $e) {
                    throw new GroupException($e->getMessage(), previous: $e);
                }
            }

            if ($group->users()->detach($userId) > 0) {
                // Holder change, no acl_entries write → invalidate this user's resolver caches explicitly (v3-e seam).
                // Reduction: a removal can return the user to a previously-cached group signature → bump.
                if (($u = User::find($userId)) instanceof User) {
                    MembershipCache::flushFor($u, bumpVersion: true);
                }
                Audit::log('group.members.removed', $group, ['user_id' => $userId]);
            }
        });

        // v3-f (ADR-0087): a group removal can drop the user below a capability they delegated as a co-owner —
        // this is the path that actually reduces a delegator's delegable mask (those keys flow from the admins
        // group). Re-check post-commit so canDo() reads the detached state; revoke any delegation that now exceeds
        // the reduced mask. A no-op when the user granted no delegations or still holds the keys via another source.
        if (($removed = User::find($userId)) instanceof User) {
            app(DelegationService::class)->cascadeForActor($removed);
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

    private function cleanMembershipModel(mixed $model): string
    {
        $model = is_string($model) ? $model : Group::MEMBERSHIP_ADMIN;

        return in_array($model, Group::MEMBERSHIP_MODELS, true) ? $model : Group::MEMBERSHIP_ADMIN;
    }

    /**
     * Sanitise a submitted auto-promotion rule tree through the engine's normaliser — only recognised
     * leaves/nodes survive, so a malformed or half-built tree can never persist as something that evaluates
     * true. An empty/blank config stores NULL (no auto-promotion).
     *
     * @return array{op:string,rules:list<array<string,mixed>>}|null
     */
    private function cleanAutoPromotion(mixed $tree): ?array
    {
        return is_array($tree) ? $this->autoPromoter->normalize($tree) : null;
    }

    private function cleanPriority(mixed $priority): int
    {
        // Strictly BELOW Moderators (80) so a custom group can never out-rank staff and shield an account from
        // the actor-vs-target rank guard (phase-1.5 F-F); still above Members (10) and the trust levels (1–5).
        return max(1, min(79, (int) $priority));
    }
}
