<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Clubs;

use App\Models\Club;
use App\Models\ClubInvitation;
use App\Models\ClubMembership;
use App\Models\User;
use App\Support\ActorRank;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Club membership flows (Phase 4 · M1.3): join / request→approve / invite / leave / role changes /
 * removal / ownership transfer. The `club_user` roster is the source of truth; every mutation re-projects
 * club-scope acl_entries (ClubRoleProjector) and keeps `clubs.member_count` exact.
 *
 * INVARIANTS this service enforces (defence-in-depth, on top of the controller/SFC gates):
 *  • A club always has ≥ 1 active OWNER (sole-owner guard) — leave/demote/remove of the last owner is refused.
 *  • A club owner can NEVER act on global STAFF who out-rank them (ActorRank ceiling) — so a club owner cannot
 *    remove/demote a global admin/moderator who happens to be in the club. Non-staff targets follow the club
 *    role hierarchy (owner > moderator > member).
 *  • Invitations are single-use + expiring; the token is the secret; an email-bound invite only the matching
 *    account can accept.
 */
class ClubMembershipService
{
    public const INVITE_TTL_DAYS = 14;

    public function __construct(private readonly ClubRoleProjector $projector) {}

    // ── Joining ──────────────────────────────────────────────────────────────────────────────────────────

    /** Join a PUBLIC club immediately as an active member. */
    public function join(Club $club, User $user): ClubMembership
    {
        if ($club->joinPolicy() !== 'open') {
            throw new ClubMembershipException('This club is not open to join.');
        }

        return $this->seat($club, $user, 'member', 'active');
    }

    /** Request to join a CLOSED club — creates a pending request awaiting approval. */
    public function requestToJoin(Club $club, User $user): ClubMembership
    {
        if ($club->joinPolicy() !== 'request') {
            throw new ClubMembershipException('This club does not accept join requests.');
        }

        $existing = $club->membershipOf($user);
        if ($existing && $existing->status === 'active') {
            throw new ClubMembershipException('You are already a member.');
        }

        return $this->upsertMembership($club, $user, 'member', 'pending');
    }

    /** Owner/moderator approves a pending request → active member. */
    public function approve(Club $club, ClubMembership $membership, User $actor): ClubMembership
    {
        $this->assertManager($club, $actor);
        if ($membership->status !== 'pending') {
            throw new ClubMembershipException('That request is not pending.');
        }

        $membership->update(['status' => 'active', 'role' => 'member', 'joined_at' => now()]);
        $this->afterRosterChange($club, $membership);

        return $membership;
    }

    /** Owner/moderator rejects a pending request → removes the row. */
    public function reject(Club $club, ClubMembership $membership, User $actor): void
    {
        $this->assertManager($club, $actor);
        if ($membership->status !== 'pending') {
            throw new ClubMembershipException('That request is not pending.');
        }

        $userId = (int) $membership->user_id;
        $membership->delete();
        $this->projector->clear((int) $club->id, $userId);
    }

    // ── Invitations ──────────────────────────────────────────────────────────────────────────────────────

    /** Owner/moderator mints a single-use, expiring invitation. Optionally bound to one email. */
    public function invite(Club $club, User $actor, ?string $email = null): ClubInvitation
    {
        $this->assertManager($club, $actor);

        return ClubInvitation::create([
            'club_id' => $club->id,
            'token' => Str::random(48),
            'email' => $email !== null && trim($email) !== '' ? mb_strtolower(trim($email)) : null,
            'invited_by' => $actor->getKey(),
            'expires_at' => now()->addDays(self::INVITE_TTL_DAYS),
        ]);
    }

    /** Accept an invitation as $user → active member. Validates single-use, expiry, and email binding. */
    public function acceptInvite(ClubInvitation $invite, User $user): ClubMembership
    {
        if (! $invite->isPending()) {
            throw new ClubMembershipException('This invitation is no longer valid.');
        }
        if ($invite->email !== null && $invite->email !== mb_strtolower((string) $user->email)) {
            throw new ClubMembershipException('This invitation was issued to a different email address.');
        }

        $club = $invite->club;
        if (! $club instanceof Club) {
            throw new ClubMembershipException('This club no longer exists.');
        }

        return DB::transaction(function () use ($invite, $club, $user): ClubMembership {
            // Re-lock the invite row to enforce single-use under concurrency.
            $locked = ClubInvitation::whereKey($invite->getKey())->lockForUpdate()->first();
            if (! $locked instanceof ClubInvitation || ! $locked->isPending()) {
                throw new ClubMembershipException('This invitation is no longer valid.');
            }

            $membership = $this->seat($club, $user, 'member', 'active');
            $locked->update(['accepted_at' => now(), 'accepted_by' => $user->getKey()]);

            return $membership;
        });
    }

    // ── Leaving / removal / roles ──────────────────────────────────────────────────────────────────────

    /** Leave a club (self). The last active owner cannot leave without transferring ownership first. */
    public function leave(Club $club, User $user): void
    {
        $membership = $club->membershipOf($user);
        if (! $membership || $membership->status !== 'active') {
            throw new ClubMembershipException('You are not a member of this club.');
        }
        if ($membership->role === 'owner' && $this->activeOwnerCount($club) <= 1) {
            throw new ClubMembershipException('Transfer ownership before leaving — a club must always have an owner.');
        }

        $userId = (int) $user->getKey();
        $membership->delete();
        $this->projector->clear((int) $club->id, $userId);
        $this->recountMembers($club);
    }

    /** Owner/admin removes a member. ActorRank ceiling on staff; cannot remove the last owner. */
    public function removeMember(Club $club, ClubMembership $membership, User $actor): void
    {
        $this->assertManager($club, $actor);
        $target = $membership->user;
        if ($target instanceof User) {
            $this->assertRankCeiling($actor, $target);
        }
        if ($membership->role === 'owner' && $this->activeOwnerCount($club) <= 1) {
            throw new ClubMembershipException('Cannot remove the only owner — transfer ownership first.');
        }

        $userId = (int) $membership->user_id;
        $membership->delete();
        $this->projector->clear((int) $club->id, $userId);
        $this->recountMembers($club);
    }

    /** Owner/admin changes a member's role. ActorRank ceiling; cannot demote the last owner. */
    public function changeRole(Club $club, ClubMembership $membership, string $newRole, User $actor): void
    {
        $this->assertManager($club, $actor);
        if (! in_array($newRole, Club::ROLES, true)) {
            throw new ClubMembershipException('Unknown club role.');
        }
        if ($membership->status !== 'active') {
            throw new ClubMembershipException('Only active members have a role.');
        }
        $target = $membership->user;
        if ($target instanceof User) {
            $this->assertRankCeiling($actor, $target);
        }
        if ($membership->role === 'owner' && $newRole !== 'owner' && $this->activeOwnerCount($club) <= 1) {
            throw new ClubMembershipException('Promote another owner before stepping the last owner down.');
        }

        $membership->update(['role' => $newRole]);
        $this->afterRosterChange($club, $membership);
    }

    /** Transfer ownership: promote an existing active member to owner. The actor may then step down separately. */
    public function transferOwnership(Club $club, ClubMembership $target, User $actor): void
    {
        $this->assertManager($club, $actor);
        if ($target->status !== 'active') {
            throw new ClubMembershipException('You can only transfer ownership to an active member.');
        }

        $target->update(['role' => 'owner']);
        $this->afterRosterChange($club, $target);
    }

    // ── Internals ──────────────────────────────────────────────────────────────────────────────────────

    /** Owner/admin gate (defence-in-depth; controllers/SFCs also gate). */
    private function assertManager(Club $club, User $actor): void
    {
        if (! $club->isManageableBy($actor)) {
            throw new ClubMembershipException('You do not manage this club.');
        }
    }

    /**
     * The global-staff rank ceiling: a club action may never land on global staff who out-rank the actor.
     * Non-staff targets are governed by the club role hierarchy, so they pass. Reuses ActorRank verbatim.
     */
    private function assertRankCeiling(User $actor, User $target): void
    {
        if ($target->isStaff() && ! ActorRank::canActOn($actor, $target)) {
            throw new ClubMembershipException('You cannot act on a staff member who out-ranks you.');
        }
    }

    private function activeOwnerCount(Club $club): int
    {
        return $club->memberships()->where('status', 'active')->where('role', 'owner')->count();
    }

    /** Create-or-reactivate a membership at the given role/status, then sync grants + count. */
    private function seat(Club $club, User $user, string $role, string $status): ClubMembership
    {
        $existing = $club->membershipOf($user);
        if ($existing && $existing->status === 'active') {
            throw new ClubMembershipException('You are already a member.');
        }

        $membership = $this->upsertMembership($club, $user, $role, $status, joined: $status === 'active');
        $this->afterRosterChange($club, $membership);

        return $membership;
    }

    private function upsertMembership(Club $club, User $user, string $role, string $status, bool $joined = false): ClubMembership
    {
        /** @var ClubMembership $membership */
        $membership = ClubMembership::updateOrCreate(
            ['club_id' => $club->id, 'user_id' => $user->getKey()],
            ['role' => $role, 'status' => $status, 'joined_at' => $joined ? now() : null],
        );

        return $membership;
    }

    /** After any role/status change: re-project club-scope grants and recount active members. */
    private function afterRosterChange(Club $club, ClubMembership $membership): void
    {
        $this->projector->project($membership);
        $this->recountMembers($club);
    }

    private function recountMembers(Club $club): void
    {
        $club->forceFill(['member_count' => $club->memberships()->where('status', 'active')->count()])->save();
    }
}
