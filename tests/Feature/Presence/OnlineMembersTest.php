<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Broadcasting\ChannelAuthorizer;
use App\Clubs\ClubService;
use App\Models\ClubMembership;
use App\Models\User;
use App\Presence\OnlineMembers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| Phase 4 · M4.3 — presence / "who's online". Opt-in (show_online_status, default false = security-by-default)
| enforced in ONE place (OnlineMembers), and presence-channel authorization that never leaks a private club's
| online roster. Baseline polls the service; the enhanced tier adds a presence channel (not validated against
| a real Reverb — ADR-0062).
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function onlineUser(string $email, bool $optIn = true, ?string $lastActive = 'now'): User
{
    return Users::inGroups(['members', 'tl1'], [
        'email' => $email,
        'show_online_status' => $optIn,
        'last_active_at' => $lastActive === 'now' ? now() : ($lastActive === null ? null : now()->parse($lastActive)),
    ]);
}

// ── The opt-in privacy rule (the single source of truth) ─────────────────────────────────────────────────

it('lists only opted-in, recently-active members', function () {
    $shown = onlineUser('shown@on.test', true, 'now');
    $optedOut = onlineUser('optout@on.test', false, 'now');
    $stale = Users::inGroups(['members', 'tl1'], ['email' => 'stale@on.test', 'show_online_status' => true, 'last_active_at' => now()->subHours(2)]);

    $ids = app(OnlineMembers::class)->recent()->pluck('id');

    expect($ids)->toContain($shown->id);
    expect($ids)->not->toContain($optedOut->id);   // opted out → invisible
    expect($ids)->not->toContain($stale->id);      // outside the recent window
    expect(app(OnlineMembers::class)->count())->toBeGreaterThanOrEqual(1);
});

it('intersects club presence with the active roster and the opt-in', function () {
    $owner = Users::inGroups(['members', 'tl3'], ['email' => 'club-own@on.test', 'show_online_status' => true, 'last_active_at' => now()]);
    $club = app(ClubService::class)->create($owner, ['name' => 'Stargazers', 'privacy' => 'public']);

    $memberIn = onlineUser('m-in@on.test', true, 'now');
    ClubMembership::create(['club_id' => $club->id, 'user_id' => $memberIn->id, 'role' => 'member', 'status' => 'active', 'joined_at' => now()]);
    $memberOut = onlineUser('m-out@on.test', false, 'now');
    ClubMembership::create(['club_id' => $club->id, 'user_id' => $memberOut->id, 'role' => 'member', 'status' => 'active', 'joined_at' => now()]);
    $outsider = onlineUser('m-outsider@on.test', true, 'now'); // online + opted-in but NOT a member

    $ids = app(OnlineMembers::class)->inClub($club->fresh())->pluck('id');

    expect($ids)->toContain($owner->id);
    expect($ids)->toContain($memberIn->id);
    expect($ids)->not->toContain($memberOut->id);   // opted out
    expect($ids)->not->toContain($outsider->id);    // not a member → never enumerated
});

// ── Presence channel authorization (no-leak) ─────────────────────────────────────────────────────────────

it('authorizes the global presence channel only for opted-in members', function () {
    $optIn = onlineUser('p-in@on.test', true, null);
    $optOut = onlineUser('p-out@on.test', false, null);
    $auth = app(ChannelAuthorizer::class);

    expect($auth->onlinePresenceInfo($optIn))->toMatchArray(['id' => (int) $optIn->id]);
    expect($auth->onlinePresenceInfo($optOut))->toBeNull();
});

it('authorizes club presence only for active opted-in members and never a non-member', function () {
    $owner = Users::inGroups(['members', 'tl3'], ['email' => 'cp-own@on.test', 'show_online_status' => true]);
    $club = app(ClubService::class)->create($owner, ['name' => 'Cloaked', 'privacy' => 'private', 'is_listed' => false]);

    $member = onlineUser('cp-mem@on.test', true, null);
    ClubMembership::create(['club_id' => $club->id, 'user_id' => $member->id, 'role' => 'member', 'status' => 'active', 'joined_at' => now()]);
    $outsider = onlineUser('cp-out@on.test', true, null);

    $auth = app(ChannelAuthorizer::class);

    expect($auth->clubPresenceInfo($member->fresh(), (int) $club->id))->toMatchArray(['id' => (int) $member->id]);
    expect($auth->clubPresenceInfo($owner->fresh(), (int) $club->id))->toMatchArray(['id' => (int) $owner->id]);
    expect($auth->clubPresenceInfo($outsider, (int) $club->id))->toBeNull(); // non-member → no socket, no leak
});

// ── The live widget + the opt-in toggle ──────────────────────────────────────────────────────────────────

it('the live widget lists only opted-in online members', function () {
    $shown = Users::inGroups(['members', 'tl1'], ['email' => 'w-shown@on.test', 'username' => 'ZorbVisibleOne', 'show_online_status' => true, 'last_active_at' => now()]);
    $hidden = Users::inGroups(['members', 'tl1'], ['email' => 'w-hidden@on.test', 'username' => 'QuixHiddenTwo', 'show_online_status' => false, 'last_active_at' => now()]);

    $html = Livewire::test('online-members')->html();

    expect($html)->toContain($shown->username);
    expect($html)->not->toContain($hidden->username);
});

it('persists the online-status opt-in from the appearance form', function () {
    $user = Users::inGroups(['members', 'tl1'], ['email' => 'toggle@on.test', 'show_online_status' => false]);

    $this->actingAs($user)->post(route('settings.appearance.save'), ['show_online_status' => '1'])->assertRedirect();
    expect($user->fresh()->show_online_status)->toBeTrue();

    $this->actingAs($user)->post(route('settings.appearance.save'), ['show_online_status' => '0'])->assertRedirect();
    expect($user->fresh()->show_online_status)->toBeFalse();
});
