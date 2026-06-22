<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Moderation;

use App\Account\AccountDeletionService;
use App\Admin\AdminCoOwnerService;
use App\Models\User;
use App\Permissions\BanChecker;
use Illuminate\Support\Facades\DB;

/**
 * The OWNER-STRAND GUARD (apex, ADR-0100) — the actor-independent backstop that stops any effective ban/suspend
 * (or owner removal) from stranding the owner tier. A banned account is blocked by
 * {@see BanChecker} BEFORE ACL resolution, so a banned owner can never reach the panel to lift
 * their own ban: leaving the forum with zero REACHABLE administrators or co-owners is an unrecoverable, fatal
 * lockout. This guard refuses it.
 *
 * It is the BAN-tier door onto the last-owner invariant (ADR-0086); the DELETE door
 * ({@see AccountDeletionService}) and the DEMOTE door ({@see AdminCoOwnerService})
 * delegate their locked counts here too, so all three reason about owners IDENTICALLY.
 *
 * Two corrections an adversarial review forced into the design (ADR-0100):
 *
 *   1. COUNT REACHABLE OWNERS, NOT MEMBERSHIP ROWS. A ban mutates only users.status / the bans table — it never
 *      touches group_user. A predicate that merely counts admins-group rows is therefore BLIND to the very
 *      mutation it guards: ban the co-owners one at a time and each ban sees the already-banned peers as still
 *      "present", so the last one slips through (the same hole under true concurrency — the FOR UPDATE serialises
 *      the txns but the row count is unchanged by a ban). The fix: an owner already blocked by BanChecker
 *      (status='banned' OR a live global user-ban row, temp bans included) is NOT a viable remaining owner, so it
 *      is excluded from the reachable count. The sibling DELETE/DEMOTE guards are naturally safe only because
 *      their mutation IS a group_user change — but they shared the same blind count for the "ban one owner, then
 *      remove the healthy peer" strand, which is why they now delegate here.
 *
 *   2. FILTER is_co_owner IN SQL, NEVER IN PHP. PDO returns a boolean column as a driver-specific scalar — int 1
 *      on MySQL/SQLite but the text "t" on PostgreSQL (a supported tier) — so a PHP-side `(int) $flag === 1`
 *      silently drops every co-owner on Postgres. The co-owner set is derived with a SQL `where is_co_owner =
 *      true`, exactly like the siblings ({@see AccountDeletionService::coOwnerIds},
 *      {@see AdminCoOwnerService}), so the database does the boolean comparison driver-correctly.
 *
 * The locking discipline: every read here is a FOR UPDATE locking read, and every owner-mutating guard (ban,
 * delete, demote) takes the SAME group_user admins-row lock FIRST. That single lock serialises them all; the
 * subsequent locked users/bans reads then see the latest COMMITTED state (a just-committed ban by a prior,
 * serialised transaction), regardless of this transaction's REPEATABLE-READ snapshot. The lock order is always
 * group_user → users → bans, so it cannot deadlock. On SQLite the FOR UPDATE is a no-op, but the in-transaction
 * re-reads against live state remain correct; the lock only matters for real MySQL/Postgres concurrency.
 */
final class OwnerStrandGuard
{
    /**
     * Throw if banning $target would strand the owner tier. The throwing entry point for the DIRECT ban surfaces
     * (the bans page, the spam cleaner), which catch {@see OwnerStrandException} and surface it as a blocking
     * message. MUST be called as the first act inside the caller's DB transaction.
     *
     * @throws OwnerStrandException when $target is the last reachable administrator or the last reachable co-owner
     */
    public function assertBanWontStrandOwnerTier(User $target): void
    {
        if ($this->wouldStrandOwnerTierLocked($target)) {
            throw new OwnerStrandException(
                'The last administrator / co-owner cannot be banned — appoint another owner first.'
            );
        }
    }

    /**
     * Would banning/removing $target leave the forum with zero reachable owners in EITHER tier (last admin or
     * last co-owner)? The non-throwing form for the ban paths — used directly by the warning auto-consequence
     * path to SUPPRESS (not abort) a threshold-crossing ban while still recording the warning. Call inside a
     * transaction so the FOR UPDATE binds.
     */
    public function wouldStrandOwnerTierLocked(User $target): bool
    {
        $userId = (int) $target->getKey();

        return $this->wouldStrandAdminTierLocked($userId) || $this->wouldStrandCoOwnerTierLocked($userId);
    }

    /** Would acting on $userId leave zero reachable administrators (admins-group members not effectively banned)? */
    public function wouldStrandAdminTierLocked(int $userId): bool
    {
        return $this->wouldStrandTier($userId, $this->lockedAdminIds());
    }

    /** Would acting on $userId leave zero reachable co-owners (is_co_owner members not effectively banned)? */
    public function wouldStrandCoOwnerTierLocked(int $userId): bool
    {
        return $this->wouldStrandTier($userId, $this->lockedCoOwnerIds());
    }

    /**
     * The shared predicate: $userId is in this owner tier AND, once it is removed/banned, NO other REACHABLE
     * owner remains (every other member of the tier is itself effectively banned). Acting on a member who is not
     * in the tier — or while another reachable owner survives — is permitted.
     *
     * @param  list<int>  $ownerIds  the locked membership of one tier
     */
    private function wouldStrandTier(int $userId, array $ownerIds): bool
    {
        if (! in_array($userId, $ownerIds, true)) {
            return false; // not in this tier — acting on them cannot strand it
        }

        $reachableOthers = array_values(array_diff($ownerIds, $this->effectivelyBannedIds($ownerIds), [$userId]));

        return $reachableOthers === [];
    }

    /** Locking read of the admins-group membership (the last-admin tier). @return list<int> */
    private function lockedAdminIds(): array
    {
        return DB::table('group_user')
            ->join('groups', 'groups.id', '=', 'group_user.group_id')
            ->where('groups.slug', 'admins')
            ->lockForUpdate()
            ->pluck('group_user.user_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** Locking read of the co-owner subset (is_co_owner filtered IN SQL for driver-correctness). @return list<int> */
    private function lockedCoOwnerIds(): array
    {
        return DB::table('group_user')
            ->join('groups', 'groups.id', '=', 'group_user.group_id')
            ->where('groups.slug', 'admins')
            ->where('group_user.is_co_owner', true)
            ->lockForUpdate()
            ->pluck('group_user.user_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Which of $ownerIds are EFFECTIVELY BANNED — already blocked by {@see BanChecker} and so
     * not a viable remaining owner: a 'banned' account status (an absolute global lockout) OR a live, unexpired
     * GLOBAL user-ban row (covers a temp ban for its duration). Locking reads, so a ban committed by a prior,
     * group_user-serialised transaction is visible here irrespective of this transaction's snapshot.
     *
     * @param  list<int>  $ownerIds
     * @return list<int>
     */
    private function effectivelyBannedIds(array $ownerIds): array
    {
        if ($ownerIds === []) {
            return [];
        }

        $bannedStatus = DB::table('users')
            ->whereIn('id', $ownerIds)
            ->where('status', 'banned')
            ->lockForUpdate()
            ->pluck('id');

        $liveGlobalBan = DB::table('bans')
            ->whereIn('user_id', $ownerIds)
            ->where('type', 'user')
            ->where('scope_type', 'global')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->lockForUpdate()
            ->pluck('user_id');

        return $bannedStatus
            ->merge($liveGlobalBan)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
