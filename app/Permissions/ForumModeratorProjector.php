<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Permissions;

use App\Clubs\ClubRoleProjector;
use App\Models\AclEntry;
use App\Models\ModeratorAssignment;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Support\ActorRank;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Projects a per-forum moderator assignment (ACP v3 · v3-b, ADR-0085) into FORUM-scope acl_entries — so the
 * SAME PermissionResolver that gates the whole board also resolves a forum moderator's scoped powers (G1: no
 * parallel evaluation path). Mirrors {@see ClubRoleProjector} in spirit, but expands through
 * {@see RoleExpander} (so a custom role's later edit re-expands onto its forum moderators) and is strictly
 * KEY-SCOPED on delete (G10: forum scope is co-managed by the v3-c card editor — this projector touches ONLY
 * the assigned role's own keys at the (holder, forum) cell, never the forum's whole acl set).
 *
 * APEX FENCES (run before any write, so a rejected assign leaves zero state):
 *   • ADMIN-TIER REFUSAL — an Administration-cluster key (admin.access, permissions.manage, …) may NEVER be a
 *     forum-moderator capability, regardless of the actor. Stricter than the ceiling fence below.
 *   • CEILING — reusing {@see RoleManager::assertWithinCeiling()} at FORUM scope: the actor may only grant keys
 *     they themselves can exercise on this forum (no granting beyond your own reach here).
 *   • RANK — {@see ActorRank}: a non-admin actor cannot make a same-or-higher-ranked USER a moderator.
 */
final class ForumModeratorProjector
{
    public function __construct(
        private readonly RoleExpander $expander,
        private readonly RoleManager $roles,
        private readonly AclVersion $version,
    ) {}

    /**
     * Assign $holder (user|group) as a moderator of $forumId with $role's capability set. Idempotent per
     * (holder, forum): re-assigning key-scope-clears the prior set, then expands the new one. Returns the
     * source-of-truth row. Throws {@see RoleException} if any fence rejects it.
     */
    public function assign(User $actor, string $holderType, int $holderId, int $forumId, Role $role): ModeratorAssignment
    {
        $scope = Scope::forum($forumId);
        $map = $this->roles->valueMap($role); // [permission_key => 1|-1]

        // Fence 1: a forum-moderator role only ever GRANTS capabilities — it is never a denial surface.
        $adminTier = $this->roles->adminTierKeys();
        foreach ($map as $key => $value) {
            // Reject NEVER (and any non-ALLOW): a NEVER would mint a forum-scope hard-deny (e.g. forum.view:NEVER
            // on a group) that the ceiling fence below cannot catch (NEVER is ceiling-exempt by design) and that
            // re-expands onto live holders on a later role edit. Bundles are ALLOW-only; custom roles must be too here.
            if ($value !== PermissionValue::Allow->value) {
                throw new RoleException("“{$key}” is set to Never — a forum-moderator role may only grant capabilities, not deny them.");
            }
            // No Administration-tier key may be delegated as a forum-mod power (stricter than the ceiling).
            if (in_array($key, $adminTier, true)) {
                throw new RoleException("“{$key}” is an administration capability and cannot be granted as a forum-moderator power.");
            }
        }

        // Fence 2: ceiling (+ admin-tier-for-non-admin), evaluated AT FORUM SCOPE — the reused engine fence.
        $this->roles->assertWithinCeiling($map, $actor, $scope);

        // Fence 3: rank guard (user holders only) — a non-admin may not elevate a same-or-higher-ranked user.
        if ($holderType === 'user') {
            $target = User::find($holderId);
            if ($target instanceof User && ! ActorRank::canActOn($actor, $target)) {
                throw new RoleException('You cannot assign a moderator who ranks at or above you.');
            }
        }

        return DB::transaction(function () use ($holderType, $holderId, $forumId, $scope, $role, $map): ModeratorAssignment {
            // Key-scope-clear any PRIOR assignment's footprint for this (holder, forum) before expanding (G10).
            $existing = $this->findAssignment($holderType, $holderId, $forumId);
            if ($existing !== null) {
                $this->clearFootprint($existing, $scope);
            }

            // Expand the role's keys at forum scope (RoleExpander writes acl_entries + the RoleAssignment).
            $this->expander->assign($role, $holderType, $holderId, $scope);

            // Source-of-truth row: a preset bundle is recorded by slug (role_id null), a custom role by id.
            $assignment = ModeratorAssignment::updateOrCreate(
                ['holder_type' => $holderType, 'holder_id' => $holderId, 'forum_id' => $forumId],
                $role->is_preset
                    ? ['role_id' => null, 'bundle' => (string) $role->slug]
                    : ['role_id' => (int) $role->getKey(), 'bundle' => null],
            );

            // G9: clearFootprint's query-builder deletes skip the AclEntry model event — bump once for the op.
            $this->version->bump();

            Audit::log('moderator.assigned', $assignment, [
                'forum_id' => $forumId,
                'holder' => $holderType.'#'.$holderId,
                'role' => (string) $role->slug,
                'keys' => array_keys($map),
            ]);

            return $assignment;
        });
    }

    /** Revoke $holder's moderator assignment on $forumId: key-scoped delete of its footprint + drop the row. */
    public function revoke(string $holderType, int $holderId, int $forumId): void
    {
        $assignment = $this->findAssignment($holderType, $holderId, $forumId);
        if ($assignment === null) {
            return;
        }

        DB::transaction(function () use ($assignment, $holderType, $holderId, $forumId): void {
            $this->clearFootprint($assignment, Scope::forum($forumId));
            $assignment->delete();
            $this->version->bump();

            Audit::log('moderator.revoked', null, [
                'forum_id' => $forumId,
                'holder' => $holderType.'#'.$holderId,
            ]);
        });
    }

    private function findAssignment(string $holderType, int $holderId, int $forumId): ?ModeratorAssignment
    {
        return ModeratorAssignment::query()
            ->where('holder_type', $holderType)
            ->where('holder_id', $holderId)
            ->where('forum_id', $forumId)
            ->first();
    }

    /**
     * Remove ONE assignment's expanded footprint at its forum scope: delete only the assigned role's OWN keys
     * (key-scoped, G10 — never the whole forum acl set), and drop the role's forum-scope RoleAssignment so a
     * later role edit (reexpand) can never re-grant a revoked holder. Does NOT bump AclVersion — the caller
     * bumps once inside its own transaction.
     */
    private function clearFootprint(ModeratorAssignment $assignment, Scope $scope): void
    {
        $role = $this->resolveRole($assignment);
        if ($role === null) {
            return;
        }

        $keys = $role->permissions()->pluck('permission_key')->all();

        if ($keys !== []) {
            AclEntry::query()
                ->where('holder_type', $assignment->holder_type)
                ->where('holder_id', $assignment->holder_id)
                ->where('scope_type', $scope->type)
                ->where('scope_id', $scope->id)
                ->whereIn('permission_key', $keys)
                ->delete();
        }

        RoleAssignment::query()
            ->where('role_id', $role->getKey())
            ->where('holder_type', $assignment->holder_type)
            ->where('holder_id', $assignment->holder_id)
            ->where('scope_type', $scope->type)
            ->where('scope_id', $scope->id)
            ->delete();
    }

    /** The concrete Role an assignment names — a custom role (role_id) or a seeded preset bundle (bundle slug). */
    private function resolveRole(ModeratorAssignment $assignment): ?Role
    {
        if ($assignment->role_id !== null) {
            return Role::find($assignment->role_id);
        }
        if ($assignment->bundle !== null) {
            return Role::where('slug', $assignment->bundle)->first();
        }

        return null;
    }
}
