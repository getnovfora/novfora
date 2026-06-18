<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Groups;

use App\Admin\GroupException;
use App\Models\Group;
use App\Models\GroupJoinRequest;
use App\Models\User;
use App\Permissions\MembershipCache;
use App\Permissions\Scope;
use App\Support\Audit;

/**
 * The membership-model join flows (ACP v3 · v3-e, ADR-0083) — the group analogue of ClubMembershipService:
 *
 *   • open    → joinOpen(): a public Join button seats the user immediately (anti-spam/trust-gated).
 *   • request → requestToJoin() creates a pending GroupJoinRequest; approve()/deny() resolves the queue.
 *   • admin   → unchanged; membership is set only through GroupManager (no self-service path here).
 *
 * INVARIANTS (defence-in-depth, beside the SFC/route gates):
 *   • Every self-service join passes GroupJoinGate (a banned/suspended/unverified account can't slip in).
 *   • System + trust groups are never self-joinable (Group::allowsSelfService()).
 *   • Every membership mutation is a pivot write with no model events, so it calls MembershipCache::flushFor()
 *     — the v3-e cache seam (G9's sibling): a group is a permission holder, so the change alters effective
 *     permissions without touching acl_entries and must invalidate the resolver explicitly.
 */
class GroupMembershipService
{
    // ── Open join ────────────────────────────────────────────────────────────────────────────────────────

    /** Join an OPEN-model group immediately. Throws GroupException if the group isn't open or the user is gated. */
    public function joinOpen(Group $group, User $user): void
    {
        if (! $group->allowsOpenJoin()) {
            throw new GroupException('This group is not open to join.');
        }
        $this->assertCanJoin($user);
        if ($this->isMember($group, $user)) {
            throw new GroupException('You are already a member of this group.');
        }

        $this->attach($group, $user);
        Audit::log('group.joined', $group, ['user_id' => (int) $user->getKey(), 'via' => 'open']);
    }

    // ── Request + approval ───────────────────────────────────────────────────────────────────────────────

    /** Request to join a REQUEST-model group → a pending row (re-used on re-request after a denial). */
    public function requestToJoin(Group $group, User $user): GroupJoinRequest
    {
        if (! $group->acceptsJoinRequests()) {
            throw new GroupException('This group does not accept join requests.');
        }
        $this->assertCanJoin($user);
        if ($this->isMember($group, $user)) {
            throw new GroupException('You are already a member of this group.');
        }

        /** @var GroupJoinRequest $request */
        $request = GroupJoinRequest::updateOrCreate(
            ['user_id' => $user->getKey(), 'group_id' => $group->getKey()],
            ['status' => GroupJoinRequest::STATUS_PENDING, 'decided_by' => null, 'decided_at' => null],
        );

        Audit::log('group.join.requested', $group, ['user_id' => (int) $user->getKey()]);

        return $request;
    }

    /** Approve a pending request → seat the user as a member. */
    public function approve(GroupJoinRequest $request, User $actor): GroupJoinRequest
    {
        $this->assertManager($actor);
        if (! $request->isPending()) {
            throw new GroupException('That request is not pending.');
        }

        $group = $request->group;
        $user = $request->user;
        if (! $group instanceof Group || ! $user instanceof User) {
            throw new GroupException('That request is no longer valid.');
        }

        if (! $this->isMember($group, $user)) {
            $this->attach($group, $user);
        }
        $request->update([
            'status' => GroupJoinRequest::STATUS_APPROVED,
            'decided_by' => $actor->getKey(),
            'decided_at' => now(),
        ]);

        Audit::log('group.join.approved', $group, ['user_id' => (int) $user->getKey(), 'by' => (int) $actor->getKey()]);

        return $request;
    }

    /** Deny a pending request. No membership change → no cache invalidation needed. */
    public function deny(GroupJoinRequest $request, User $actor): GroupJoinRequest
    {
        $this->assertManager($actor);
        if (! $request->isPending()) {
            throw new GroupException('That request is not pending.');
        }

        $request->update([
            'status' => GroupJoinRequest::STATUS_DENIED,
            'decided_by' => $actor->getKey(),
            'decided_at' => now(),
        ]);

        Audit::log('group.join.denied', $request->group, ['user_id' => (int) $request->user_id, 'by' => (int) $actor->getKey()]);

        return $request;
    }

    // ── Leaving ──────────────────────────────────────────────────────────────────────────────────────────

    /** Leave a self-service (open/request) group the user belongs to. Admin/system/trust groups can't be left here. */
    public function leave(Group $group, User $user): void
    {
        if (! $group->allowsSelfService() || $group->membershipModel() === Group::MEMBERSHIP_ADMIN) {
            throw new GroupException('You cannot leave this group.');
        }
        if (! $this->isMember($group, $user)) {
            throw new GroupException('You are not a member of this group.');
        }

        $group->users()->detach($user->getKey());
        // Reduction: leaving can return the user to a previously-cached group signature → bump (defence-in-depth).
        MembershipCache::flushFor($user, bumpVersion: true);
        Audit::log('group.left', $group, ['user_id' => (int) $user->getKey()]);
    }

    // ── Internals ────────────────────────────────────────────────────────────────────────────────────────

    private function isMember(Group $group, User $user): bool
    {
        return $group->users()->whereKey($user->getKey())->exists();
    }

    /** Seat the user (idempotent pivot write) + invalidate their resolver caches (v3-e seam). */
    private function attach(Group $group, User $user): void
    {
        $group->users()->syncWithoutDetaching([$user->getKey() => ['is_primary' => false]]);
        MembershipCache::flushFor($user);
    }

    private function assertCanJoin(User $user): void
    {
        if (($reason = GroupJoinGate::reasonBlocked($user)) !== null) {
            throw new GroupException($reason);
        }
    }

    /** Approving/denying requests is gated on Groups-section (admin) access — defence-in-depth beside the SFC. */
    private function assertManager(User $actor): void
    {
        if (! $actor->canDo('admin.access', Scope::global())) {
            throw new GroupException('You do not have permission to manage join requests.');
        }
    }
}
