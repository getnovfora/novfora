<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Clubs\ClubMembershipException;
use App\Clubs\ClubMembershipService;
use App\Clubs\ClubService;
use App\Models\AclEntry;
use App\Models\Club;
use App\Models\ClubMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

/** @return array{0: User, 1: Club} */
function ownerAndClub(string $email, string $privacy = 'public'): array
{
    $owner = Users::inGroups(['members', 'tl2'], ['email' => $email]);
    $club = app(ClubService::class)->create($owner, ['name' => 'Club '.uniqid(), 'privacy' => $privacy]);

    return [$owner, $club];
}

function clubScopeGrants(int $clubId, int $userId): int
{
    return AclEntry::where('holder_type', 'user')->where('holder_id', $userId)
        ->where('scope_type', 'club')->where('scope_id', $clubId)->count();
}

// ── Joining ──────────────────────────────────────────────────────────────────────────────────────────────

it('lets a member join a public club', function () {
    [, $club] = ownerAndClub('owner-pub@m.test', 'public');
    $joiner = Users::inGroups(['members', 'tl1'], ['email' => 'joiner@m.test']);

    app(ClubMembershipService::class)->join($club, $joiner);

    expect($club->fresh()->roleOf($joiner))->toBe('member');
    expect((int) $club->fresh()->member_count)->toBe(2);
});

it('refuses to join a closed club directly, but accepts a request', function () {
    [, $club] = ownerAndClub('owner-closed@m.test', 'closed');
    $joiner = Users::inGroups(['members', 'tl1'], ['email' => 'req@m.test']);

    expect(fn () => app(ClubMembershipService::class)->join($club, $joiner))
        ->toThrow(ClubMembershipException::class);

    $m = app(ClubMembershipService::class)->requestToJoin($club, $joiner);
    expect($m->status)->toBe('pending');
    expect($club->fresh()->isActiveMember($joiner))->toBeFalse();
    expect((int) $club->fresh()->member_count)->toBe(1); // pending does not count
});

it('refuses to join a private club without an invite', function () {
    [, $club] = ownerAndClub('owner-priv@m.test', 'private');
    $joiner = Users::inGroups(['members', 'tl1'], ['email' => 'nope@m.test']);

    expect(fn () => app(ClubMembershipService::class)->join($club, $joiner))->toThrow(ClubMembershipException::class);
    expect(fn () => app(ClubMembershipService::class)->requestToJoin($club, $joiner))->toThrow(ClubMembershipException::class);
});

// ── Approve / reject ─────────────────────────────────────────────────────────────────────────────────────

it('approves a pending request into active membership', function () {
    [$owner, $club] = ownerAndClub('owner-appr@m.test', 'closed');
    $joiner = Users::inGroups(['members', 'tl1'], ['email' => 'pending@m.test']);
    $m = app(ClubMembershipService::class)->requestToJoin($club, $joiner);

    app(ClubMembershipService::class)->approve($club, $m, $owner);

    expect($m->fresh()->status)->toBe('active');
    expect((int) $club->fresh()->member_count)->toBe(2);
});

it('lets a manager reject a pending request', function () {
    [$owner, $club] = ownerAndClub('owner-rej@m.test', 'closed');
    $joiner = Users::inGroups(['members', 'tl1'], ['email' => 'rejectme@m.test']);
    $m = app(ClubMembershipService::class)->requestToJoin($club, $joiner);

    app(ClubMembershipService::class)->reject($club, $m, $owner);

    expect(ClubMembership::find($m->id))->toBeNull();
});

it('refuses approval by a non-manager', function () {
    [, $club] = ownerAndClub('owner-na@m.test', 'closed');
    $joiner = Users::inGroups(['members', 'tl1'], ['email' => 'pa@m.test']);
    $m = app(ClubMembershipService::class)->requestToJoin($club, $joiner);
    $stranger = Users::inGroups(['members', 'tl2'], ['email' => 'stranger-mgr@m.test']);

    expect(fn () => app(ClubMembershipService::class)->approve($club, $m, $stranger))
        ->toThrow(ClubMembershipException::class);
});

// ── Invitations ──────────────────────────────────────────────────────────────────────────────────────────

it('mints and accepts a single-use invitation', function () {
    [$owner, $club] = ownerAndClub('owner-inv@m.test', 'private');
    $invitee = Users::inGroups(['members', 'tl1'], ['email' => 'invitee@m.test']);

    $invite = app(ClubMembershipService::class)->invite($club, $owner);
    app(ClubMembershipService::class)->acceptInvite($invite, $invitee);

    expect($club->fresh()->roleOf($invitee))->toBe('member');
    // Single-use: a second accept fails.
    expect(fn () => app(ClubMembershipService::class)->acceptInvite($invite->fresh(), Users::inGroups(['members'], ['email' => 'second@m.test'])))
        ->toThrow(ClubMembershipException::class);
});

it('rejects an expired invitation', function () {
    [$owner, $club] = ownerAndClub('owner-exp@m.test', 'private');
    $invite = app(ClubMembershipService::class)->invite($club, $owner);
    $invite->update(['expires_at' => now()->subDay()]);
    $invitee = Users::inGroups(['members'], ['email' => 'late@m.test']);

    expect(fn () => app(ClubMembershipService::class)->acceptInvite($invite->fresh(), $invitee))
        ->toThrow(ClubMembershipException::class);
});

it('enforces the email binding on an invitation', function () {
    [$owner, $club] = ownerAndClub('owner-bind@m.test', 'private');
    $invite = app(ClubMembershipService::class)->invite($club, $owner, 'expected@m.test');
    $wrong = Users::inGroups(['members'], ['email' => 'wrong@m.test']);
    $right = Users::inGroups(['members'], ['email' => 'expected@m.test']);

    expect(fn () => app(ClubMembershipService::class)->acceptInvite($invite, $wrong))->toThrow(ClubMembershipException::class);
    app(ClubMembershipService::class)->acceptInvite($invite->fresh(), $right);
    expect($club->fresh()->isActiveMember($right))->toBeTrue();
});

// ── Leave / sole-owner guard ─────────────────────────────────────────────────────────────────────────────

it('lets a member leave and clears their club-scope grants', function () {
    [$owner, $club] = ownerAndClub('owner-leave2@m.test', 'public');
    $member = Users::inGroups(['members', 'tl1'], ['email' => 'leaver@m.test']);
    app(ClubMembershipService::class)->join($club, $member);
    expect((int) $club->fresh()->member_count)->toBe(2);

    app(ClubMembershipService::class)->leave($club, $member);

    expect($club->fresh()->isActiveMember($member))->toBeFalse();
    expect((int) $club->fresh()->member_count)->toBe(1);
    expect(clubScopeGrants((int) $club->id, (int) $member->id))->toBe(0);
});

it('refuses to let the sole owner leave', function () {
    [$owner, $club] = ownerAndClub('sole-owner@m.test', 'public');

    expect(fn () => app(ClubMembershipService::class)->leave($club, $owner))
        ->toThrow(ClubMembershipException::class);
});

it('refuses to remove or demote the only owner', function () {
    [$owner, $club] = ownerAndClub('only-owner@m.test', 'public');
    $ownerM = $club->membershipOf($owner);

    expect(fn () => app(ClubMembershipService::class)->removeMember($club, $ownerM, $owner))->toThrow(ClubMembershipException::class);
    expect(fn () => app(ClubMembershipService::class)->changeRole($club, $ownerM, 'member', $owner))->toThrow(ClubMembershipException::class);
});

// ── Roles + ownership transfer ───────────────────────────────────────────────────────────────────────────

it('promotes a member to moderator and grants club-scope moderation', function () {
    [$owner, $club] = ownerAndClub('owner-promo@m.test', 'public');
    $member = Users::inGroups(['members', 'tl1'], ['email' => 'promote-me@m.test']);
    app(ClubMembershipService::class)->join($club, $member);
    $m = $club->membershipOf($member);

    app(ClubMembershipService::class)->changeRole($club, $m, 'moderator', $owner);

    expect($m->fresh()->role)->toBe('moderator');
    expect(AclEntry::where('holder_type', 'user')->where('holder_id', $member->id)
        ->where('scope_type', 'club')->where('scope_id', $club->id)
        ->where('permission_key', 'topic.moderate')->exists())->toBeTrue();
});

it('transfers ownership and then lets the original owner leave', function () {
    [$owner, $club] = ownerAndClub('owner-xfer@m.test', 'public');
    $heir = Users::inGroups(['members', 'tl1'], ['email' => 'heir@m.test']);
    app(ClubMembershipService::class)->join($club, $heir);
    $heirM = $club->membershipOf($heir);

    app(ClubMembershipService::class)->transferOwnership($club, $heirM, $owner);
    expect($heirM->fresh()->role)->toBe('owner');

    // Now a second owner exists, so the original owner may leave.
    app(ClubMembershipService::class)->leave($club, $owner);
    expect($club->fresh()->isActiveMember($owner))->toBeFalse();
});

// ── Rank ceiling: a club owner can never act on global staff ─────────────────────────────────────────────

it('forbids a club owner from removing or demoting global staff in the club', function () {
    [$owner, $club] = ownerAndClub('owner-rank@m.test', 'public');
    $admin = Users::inGroups(['admins'], ['email' => 'admin-in-club@m.test']);
    $adminM = ClubMembership::create(['club_id' => $club->id, 'user_id' => $admin->id, 'role' => 'member', 'status' => 'active', 'joined_at' => now()]);

    expect(fn () => app(ClubMembershipService::class)->removeMember($club, $adminM, $owner))->toThrow(ClubMembershipException::class);
    expect(fn () => app(ClubMembershipService::class)->changeRole($club, $adminM, 'moderator', $owner))->toThrow(ClubMembershipException::class);
});

it('lets a club owner remove a regular member of equal global rank', function () {
    [$owner, $club] = ownerAndClub('owner-remove@m.test', 'public');
    $member = Users::inGroups(['members', 'tl1'], ['email' => 'removable@m.test']);
    app(ClubMembershipService::class)->join($club, $member);
    $m = $club->membershipOf($member);

    app(ClubMembershipService::class)->removeMember($club, $m, $owner);

    expect($club->fresh()->isActiveMember($member))->toBeFalse();
});

// ── UI: join button + roster + invite acceptance route ───────────────────────────────────────────────────

it('joins a public club through the join-button SFC', function () {
    [, $club] = ownerAndClub('owner-sfc@m.test', 'public');
    $joiner = Users::inGroups(['members', 'tl1'], ['email' => 'sfc-join@m.test']);

    Livewire::actingAs($joiner)->test('clubs.join-button', ['club' => $club])
        ->call('join')
        ->assertHasNoErrors();

    expect($club->fresh()->isActiveMember($joiner))->toBeTrue();
});

it('404s the roster of a private club for a non-member', function () {
    [, $club] = ownerAndClub('owner-roster@m.test', 'private');
    $outsider = Users::inGroups(['members', 'tl1'], ['email' => 'roster-out@m.test']);

    $this->actingAs($outsider)->get(route('clubs.members', $club))->assertNotFound();
});

it('accepts an invitation through the confirm route', function () {
    [$owner, $club] = ownerAndClub('owner-route-inv@m.test', 'private');
    $invite = app(ClubMembershipService::class)->invite($club, $owner);
    $invitee = Users::inGroups(['members', 'tl1'], ['email' => 'route-invitee@m.test']);

    $this->actingAs($invitee)
        ->post(route('clubs.invite.accept', ['club' => $club, 'invitation' => $invite->token]))
        ->assertRedirect(route('clubs.show', $club));

    expect($club->fresh()->isActiveMember($invitee))->toBeTrue();
});
