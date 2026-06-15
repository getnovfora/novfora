<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Clubs\ClubMembershipService;
use App\Clubs\ClubRoleProjector;
use App\Clubs\ClubService;
use App\Forum\PostService;
use App\Models\Club;
use App\Models\ClubMembership;
use App\Models\Forum;
use App\Models\User;
use App\Permissions\PermissionResolver;
use App\Permissions\Scope;
use App\Permissions\ScopeChain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Support\Content;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function clubFlush(): void
{
    app(PermissionResolver::class)->flushMemo();
    Cache::flush();
}

/** @return array{0: User, 1: Club, 2: Forum} */
function clubWithForum(string $email, string $privacy = 'public'): array
{
    $owner = Users::inGroups(['members', 'tl2'], ['email' => $email]);
    $club = app(ClubService::class)->create($owner, ['name' => 'Disc '.uniqid(), 'privacy' => $privacy]);
    clubFlush();

    return [$owner, $club, $club->forum];
}

// ── Auto-created discussion forum ────────────────────────────────────────────────────────────────────────

it('creates a discussion forum tagged with the club on club creation', function () {
    [, $club, $forum] = clubWithForum('disc-owner@d.test');

    expect($forum)->toBeInstanceOf(Forum::class);
    expect((int) $forum->club_id)->toBe((int) $club->id);
    expect($forum->type)->toBe('forum');
    expect((int) $club->forum_id)->toBe((int) $forum->id);
});

it('keeps club discussion forums out of the main board index', function () {
    [, , $forum] = clubWithForum('disc-hidden@d.test');

    $this->get(route('forums.index'))->assertOk()->assertDontSee($forum->title);
});

// ── Scope chain wiring ───────────────────────────────────────────────────────────────────────────────────

it('injects the club scope into a club forum scope chain', function () {
    [, $club, $forum] = clubWithForum('disc-chain@d.test');

    $chain = ScopeChain::for($forum->permissionScope());
    $types = array_map(fn (Scope $s) => $s->type, $chain);

    expect($types)->toContain('club');
    expect(collect($chain)->firstWhere('type', 'club')?->id)->toBe((int) $club->id);
});

it('lets a club moderator moderate a topic in the club forum but not a plain member', function () {
    [$owner, $club, $forum] = clubWithForum('disc-mod@d.test');
    $topic = app(PostService::class)->createTopic($owner, $forum, 'Hello club', 'markdown', ['source' => 'First post']);

    $mod = Users::inGroups(['members', 'tl1'], ['email' => 'disc-clubmod@d.test']);
    $mm = ClubMembership::create(['club_id' => $club->id, 'user_id' => $mod->id, 'role' => 'moderator', 'status' => 'active', 'joined_at' => now()]);
    app(ClubRoleProjector::class)->project($mm);

    $member = Users::inGroups(['members', 'tl1'], ['email' => 'disc-plain@d.test']);
    app(ClubMembershipService::class)->join($club, $member);
    clubFlush();

    expect($mod->fresh()->canDo('topic.moderate', Scope::thread((int) $topic->id)))->toBeTrue();
    expect($member->fresh()->canDo('topic.moderate', Scope::thread((int) $topic->id)))->toBeFalse();
});

// ── Read gate: private club forum/topic ──────────────────────────────────────────────────────────────────

it('404s a private club forum for guests and non-members, 200 for members and staff', function () {
    [$owner, $club, $forum] = clubWithForum('disc-priv@d.test', 'private');

    $this->get(route('forums.show', $forum))->assertNotFound(); // guest

    $outsider = Users::inGroups(['members', 'tl1'], ['email' => 'disc-out@d.test']);
    $this->actingAs($outsider)->get(route('forums.show', $forum))->assertNotFound();

    $member = Users::inGroups(['members', 'tl1'], ['email' => 'disc-in@d.test']);
    ClubMembership::create(['club_id' => $club->id, 'user_id' => $member->id, 'role' => 'member', 'status' => 'active', 'joined_at' => now()]);
    clubFlush();
    $this->actingAs($member->fresh())->get(route('forums.show', $forum))->assertOk();

    $admin = Users::inGroups(['admins'], ['email' => 'disc-admin@d.test']);
    $this->actingAs($admin)->get(route('forums.show', $forum))->assertOk();
});

it('404s a topic in a private club forum for a non-member', function () {
    [$owner, $club, $forum] = clubWithForum('disc-topic@d.test', 'private');
    $topic = app(PostService::class)->createTopic($owner, $forum, 'Secret topic', 'markdown', ['source' => 'hush']);

    $outsider = Users::inGroups(['members', 'tl1'], ['email' => 'disc-topic-out@d.test']);
    $this->actingAs($outsider)->get(route('topics.show', $topic))->assertNotFound();

    $this->actingAs($owner->fresh())->get(route('topics.show', $topic))->assertOk();
});

// ── Participation gate ───────────────────────────────────────────────────────────────────────────────────

it('blocks a non-member from starting a topic in a public club forum', function () {
    [, $club, $forum] = clubWithForum('disc-part@d.test', 'public');
    $outsider = Users::inGroups(['members', 'tl2'], ['email' => 'disc-part-out@d.test']);

    Livewire::actingAs($outsider)
        ->test('forum.create-topic', ['forumId' => $forum->id])
        ->assertStatus(403);
});

it('lets a club member start a topic in the club forum', function () {
    [, $club, $forum] = clubWithForum('disc-part2@d.test', 'public');
    $member = Users::inGroups(['members', 'tl2'], ['email' => 'disc-part-in@d.test']);
    app(ClubMembershipService::class)->join($club, $member);
    clubFlush();

    Livewire::actingAs($member->fresh())
        ->test('forum.create-topic', ['forumId' => $forum->id])
        ->set('title', 'My first club topic')
        ->set('canonicalJson', Content::doc('Hello everyone'))
        ->call('save')
        ->assertHasNoErrors();
});

it('blocks a non-member from replying in a club forum', function () {
    [$owner, $club, $forum] = clubWithForum('disc-reply@d.test', 'public');
    $topic = app(PostService::class)->createTopic($owner, $forum, 'A thread', 'markdown', ['source' => 'start']);
    $outsider = Users::inGroups(['members', 'tl2'], ['email' => 'disc-reply-out@d.test']);

    Livewire::actingAs($outsider)
        ->test('forum.reply-composer', ['topicId' => $topic->id])
        ->assertStatus(403);
});
