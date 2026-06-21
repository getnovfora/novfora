<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Admin;

use App\Account\AccountDeletionService;
use App\Models\AclEntry;
use App\Models\Group;
use App\Models\User;
use App\Permissions\AclVersion;
use App\Permissions\MembershipCache;
use App\Permissions\PermissionValue;
use App\Support\Audit;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Co-owners (ACP v3 · v3-a, ADR-0080) — the top admin tier. There are MULTIPLE co-owners, NO single Root and NO
 * transfer protocol: any co-owner may appoint or remove any other, bounded only by a LAST-OWNER guard so the
 * forum can never be stranded with zero owners.
 *
 * A co-owner is an `admins`-group member carrying two COUPLED facts this service keeps in lockstep:
 *   1. the `is_co_owner` pivot flag — the tier marker the last-owner guard counts (it never feeds the resolver);
 *   2. a per-user `admin.security.access` GLOBAL grant — what the resolver / Security SFCs actually gate on (the
 *      `administrator` preset deliberately withholds this key, so only co-owners hold it).
 *
 * The apex concern is the demote/remove TOCTOU: {@see assertNotSoleCoOwnerLocked} re-reads the co-owner set under
 * a row lock as the FIRST act of the revoke transaction, exactly mirroring
 * {@see AccountDeletionService::assertNotSoleAdminLocked} — two concurrent removals serialise, the
 * first commits, the second sees the lone owner and aborts.
 */
final class AdminCoOwnerService
{
    /** The per-user grant that admits a co-owner to the Security section (the resolver's input). */
    public const SECURITY_KEY = 'admin.security.access';

    public function __construct(private readonly AclVersion $version) {}

    /** Whether $user is a co-owner: an admins-group member with is_co_owner set (DB truth — no in-memory relation). */
    public function isCoOwner(User $user): bool
    {
        return $this->coOwnerQuery()->where('group_user.user_id', $user->getKey())->exists();
    }

    /** The live set of co-owner user ids (admins-group members flagged is_co_owner). @return list<int> */
    public function coOwnerIds(): array
    {
        return $this->coOwnerQuery()
            ->pluck('group_user.user_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
    }

    /**
     * Appoint $target a co-owner. ADDITIVE — no last-owner guard needed. $target must already be a full admin (an
     * admins-group member): co-ownership is a tier ON TOP of admin, never a back-door to admin. Idempotent:
     * re-appointing an existing co-owner is a no-op (no spurious AclVersion bump). The actor must themselves be a
     * co-owner — the actor-independent backstop to the SFC's mount() gate (Livewire actions skip route middleware).
     */
    public function grant(User $actor, User $target): void
    {
        $this->assertActorIsCoOwner($actor);

        if (! $target->isAdmin()) {
            throw new AdminCoOwnerException('Only an administrator can be made a co-owner.');
        }
        if ($this->isCoOwner($target)) {
            return; // already a co-owner — idempotent no-op
        }

        DB::transaction(function () use ($actor, $target): void {
            $this->setCoOwnerFlag($target, true);

            // The security grant is what the resolver reads; the model write bumps AclVersion via AclEntry::booted().
            AclEntry::updateOrCreate(
                [
                    'permission_key' => self::SECURITY_KEY,
                    'holder_type' => 'user',
                    'holder_id' => (int) $target->getKey(),
                    'scope_type' => 'global',
                    'scope_id' => null,
                ],
                ['value' => PermissionValue::Allow->value],
            );

            Audit::log('admin.co_owner.granted', $target, ['by' => (int) $actor->getKey()]);
        });

        MembershipCache::flushFor($target); // reload the target's groups + flush the per-request resolver memos
    }

    /**
     * Remove $target's co-owner status. The LAST-OWNER GUARD (apex) runs as the FIRST act inside the transaction,
     * under a row lock, so concurrent removals serialise and the forum is never stranded with zero owners. The
     * pivot flag and the security grant are cleared TOGETHER. Idempotent: revoking a non-co-owner is a no-op. The
     * actor must be a co-owner (backstop). A co-owner may step themselves down — unless they are the last one.
     */
    public function revoke(User $actor, User $target): void
    {
        $this->assertActorIsCoOwner($actor);

        if (! $this->isCoOwner($target)) {
            return; // not a co-owner — nothing to revoke
        }

        DB::transaction(function () use ($actor, $target): void {
            // (a0) TOCTOU close: re-assert the last-owner invariant under a ROW LOCK as the FIRST act, BEFORE any
            //      mutation. The non-locking isCoOwner() pre-check above is only a fast filter; THIS is the authority.
            $this->assertNotSoleCoOwnerLocked((int) $target->getKey());

            $this->setCoOwnerFlag($target, false);
            $this->deleteSecurityGrant((int) $target->getKey());

            Audit::log('admin.co_owner.revoked', $target, ['by' => (int) $actor->getKey()]);
        });

        MembershipCache::flushFor($target);

        // v3-f (ADR-0087): honour "a delegation never outlives its delegator's current mask". Losing co-ownership
        // only strips admin.security.access (not a delegable key) and leaves admins membership intact, so this is
        // a defensive no-op in practice — but it guarantees the invariant if the demoted user can no longer hold a
        // key they delegated. The actual delegable-mask reduction is admins-group removal (GroupManager).
        app(DelegationService::class)->cascadeForActor($target);
    }

    /**
     * Tear down $target's co-ownership because their ADMINS MEMBERSHIP is being removed by another path (the
     * Groups member panel — {@see GroupManager::removeMember}). This is the SECOND door onto the
     * last-owner invariant: it runs the SAME locked guard as revoke() so a sole co-owner can never be stranded,
     * and deletes the now-orphaned security grant. The CALLER removes the pivot row (which clears the flag) and
     * MUST invoke this inside its own transaction, so the guard's row lock and the membership delete commit
     * atomically. A no-op for an ordinary (non-co-owner) member removal.
     *
     * @throws AdminCoOwnerException if $target is the sole co-owner (the removal is refused)
     */
    public function tearDownForAdminsRemoval(User $target): void
    {
        if (! $this->isCoOwner($target)) {
            return;
        }

        $this->assertNotSoleCoOwnerLocked((int) $target->getKey());
        $this->deleteSecurityGrant((int) $target->getKey());
    }

    /** Delete a user's per-user admin.security.access grant and bump AclVersion (query-builder delete skips the
     *  AclEntry `deleted` event, G9, so the bump is explicit). */
    private function deleteSecurityGrant(int $userId): void
    {
        AclEntry::query()
            ->where('permission_key', self::SECURITY_KEY)
            ->where('holder_type', 'user')
            ->where('holder_id', $userId)
            ->where('scope_type', 'global')
            ->whereNull('scope_id')
            ->delete();
        $this->version->bump();
    }

    /**
     * The AUTHORITATIVE last-co-owner guard (apex): a LOCKING current read of the admins-group co-owner set, run
     * inside the revoke transaction so it serialises against any concurrent removal. Throws — rolling the
     * transaction back, committing nothing — when removing $userId would leave the forum with zero co-owners.
     * Co-ownership is re-derived from the locked pivot rows (DB truth), never a stale in-memory model. On drivers
     * without row locks (SQLite) the FOR UPDATE is a no-op, but the in-transaction re-read against live state is
     * still correct; the lock only matters for true MySQL/Postgres concurrency. Mirrors
     * {@see AccountDeletionService::assertNotSoleAdminLocked}.
     *
     * @throws AdminCoOwnerException when $userId is the sole remaining co-owner
     */
    private function assertNotSoleCoOwnerLocked(int $userId): void
    {
        $coOwnerIds = DB::table('group_user')
            ->join('groups', 'groups.id', '=', 'group_user.group_id')
            ->where('groups.slug', 'admins')
            ->where('group_user.is_co_owner', true)
            ->lockForUpdate()
            ->pluck('group_user.user_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->all();

        if (in_array($userId, $coOwnerIds, true) && count($coOwnerIds) <= 1) {
            throw new AdminCoOwnerException('The last co-owner cannot be removed — appoint another co-owner first.');
        }
    }

    private function assertActorIsCoOwner(User $actor): void
    {
        if (! $this->isCoOwner($actor)) {
            throw new AdminCoOwnerException('Only a co-owner can appoint or remove co-owners.');
        }
    }

    /** Set the is_co_owner pivot flag on $target's admins-group membership (fires no model event — by design). */
    private function setCoOwnerFlag(User $target, bool $value): void
    {
        $adminsId = Group::query()->where('slug', 'admins')->value('id');
        if ($adminsId !== null) {
            $target->groups()->updateExistingPivot((int) $adminsId, ['is_co_owner' => $value]);
        }
    }

    /** Base query: admins-group memberships flagged is_co_owner. */
    private function coOwnerQuery(): Builder
    {
        return DB::table('group_user')
            ->join('groups', 'groups.id', '=', 'group_user.group_id')
            ->where('groups.slug', 'admins')
            ->where('group_user.is_co_owner', true);
    }
}
