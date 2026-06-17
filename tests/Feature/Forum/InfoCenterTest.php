<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\InfoCenter;
use App\Models\Forum;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Support\Users;

/*
| ADR-0077 — the classic board-index "Info Center": a Statistics panel + an opt-in Who's-Online panel above
| the recent-activity feed. The read-model (App\Forum\InfoCenter) caches PRIMITIVES ONLY (RH-9) and rehydrates
| the newest member after the cache boundary; every figure is an aggregate count (no post content), so there
| is no hidden-forum leak, and who's-online stays opt-in via App\Presence\OnlineMembers.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();   // groups / permissions / trust gates (no users, no posts) — a clean count baseline
    Cache::flush();  // the read-model caches its primitives 60s; start every test from a cold key
});

/** A throwaway approved topic in its own forum (no author → keeps the active-member count controlled). */
function infoCenterTopic(): Topic
{
    $forum = Forum::create(['slug' => Str::random(12), 'title' => 'Info Forum', 'type' => 'forum']);

    return Topic::create([
        'slug' => Str::random(12),
        'title' => 'Info Topic',
        'forum_id' => $forum->id,
        'approved_state' => 'approved',
        'last_posted_at' => now(),
    ]);
}

/** An approved post (with its own active author) at $position, optionally backdated to $createdAt. */
function infoCenterPost(Topic $topic, int $position, ?Carbon $createdAt = null): Post
{
    $post = Post::create([
        'topic_id' => $topic->id,
        'user_id' => User::factory()->create()->id,
        'body_format' => 'tiptap_json',
        'body_canonical' => [],
        'body_text' => 'an info-center fixture post',
        'position' => $position,
        'approved_state' => 'approved',
    ]);

    if ($createdAt !== null) {
        // created_at is guarded; forceFill + saveQuietly persists the backdate without touching it via fill().
        $post->forceFill(['created_at' => $createdAt])->saveQuietly();
    }

    return $post;
}

it('renders the Info Center block on the board index', function () {
    $topic = infoCenterTopic();
    infoCenterPost($topic, 1);
    infoCenterPost($topic, 2);

    // An opted-in, currently-active member (also the newest, being created last → shows in both panels).
    Users::inGroups(['members', 'tl1'], ['username' => 'indexonlineuser', 'show_online_status' => true, 'last_active_at' => now()]);

    $this->get('/')
        ->assertOk()
        ->assertSee('Info Center')
        ->assertSee('Statistics')
        ->assertSee("Who's Online")
        ->assertSee('Total posts')
        ->assertSee('Newest member')
        ->assertSee('indexonlineuser');
});

it('computes board-wide posts / topics / members counts', function () {
    $t1 = infoCenterTopic();
    infoCenterPost($t1, 1);
    infoCenterPost($t1, 2);
    $t2 = infoCenterTopic();
    infoCenterPost($t2, 1);

    $stats = app(InfoCenter::class)->statistics();

    // Matches the canonical model counts (same shape as ForumStatsWidget) ...
    expect($stats['posts'])->toBe(Post::query()->count());
    expect($stats['topics'])->toBe(Topic::query()->count());
    expect($stats['members'])->toBe(User::query()->where('status', 'active')->count());
    // ... and the known absolutes for this fixture.
    expect($stats['topics'])->toBe(2);
    expect($stats['posts'])->toBe(3);
});

it('picks the most-recently-registered active member as newest (banned/inactive excluded)', function () {
    Users::inGroups(['members'], ['username' => 'olderactivemember']);
    $newest = Users::inGroups(['members'], ['username' => 'newestactivemember']);
    // A LATER registration (higher id) that is banned — must not win "newest member".
    $banned = Users::inGroups(['members'], ['username' => 'bannedlatecomer']);
    $banned->forceFill(['status' => 'banned'])->saveQuietly();

    $stats = app(InfoCenter::class)->statistics();

    expect($stats['newestMember'])->not->toBeNull();
    expect($stats['newestMember']->id)->toBe($newest->id);

    // and it renders on the index while the later-but-banned member does not.
    $this->get('/')->assertOk()->assertSee('newestactivemember')->assertDontSee('bannedlatecomer');
});

it('does not surface a member banned after the stats were cached as newest', function () {
    $member = Users::inGroups(['members'], ['username' => 'soontobebanned']);

    $service = app(InfoCenter::class);

    // Warm the 60s cache: newestMemberId is now pinned to $member.
    expect($service->statistics()['newestMember']?->id)->toBe($member->id);

    // Banned mid-window — the cached id is stale, but rehydration must re-check status and drop them
    // (the members directory + who's-online already hide banned members).
    $member->forceFill(['status' => 'banned'])->saveQuietly();

    expect($service->statistics()['newestMember'])->toBeNull();
});

it('counts only posts created today, on the app-timezone day boundary', function () {
    $topic = infoCenterTopic();
    infoCenterPost($topic, 1, Carbon::today()->addHours(6)); // this morning (app tz)      → counts
    infoCenterPost($topic, 2, Carbon::today()->subSecond());  // 23:59:59 yesterday (app tz) → excluded
    infoCenterPost($topic, 3, Carbon::today()->subDays(3));    // three days ago             → excluded

    expect(app(InfoCenter::class)->statistics()['postsToday'])->toBe(1);
});

it("who's-online lists only opted-in, recently-active members (opt-in + window)", function () {
    $shown = Users::inGroups(['members', 'tl1'], ['username' => 'panelvisibleone', 'show_online_status' => true, 'last_active_at' => now()]);
    $optedOut = Users::inGroups(['members', 'tl1'], ['username' => 'paneloptedouttwo', 'show_online_status' => false, 'last_active_at' => now()]);
    $stale = Users::inGroups(['members', 'tl1'], ['username' => 'panelstalethree', 'show_online_status' => true, 'last_active_at' => now()->subHours(2)]);

    $usernames = app(InfoCenter::class)->whosOnline()['members']->pluck('username');

    expect($usernames)->toContain($shown->username);
    expect($usernames)->not->toContain($optedOut->username);  // opted out → invisible
    expect($usernames)->not->toContain($stale->username);     // outside the recent window
});

it('caches primitives only and rehydrates the newest member after the cache boundary', function () {
    $member = Users::inGroups(['members'], ['username' => 'cachednewestmember']);

    $stats = app(InfoCenter::class)->statistics();

    // The public read-model returns a real, correct model — rehydrated, not cached.
    expect($stats['newestMember'])->toBeInstanceOf(User::class);
    expect($stats['newestMember']->id)->toBe($member->id);

    // The cache itself holds SCALARS ONLY (RH-9): the id, never the model.
    $cached = Cache::get('novfora:infocenter:stats');
    expect($cached)->toBeArray();
    expect($cached)->not->toHaveKey('newestMember');
    expect($cached['newestMemberId'])->toBe($member->id);
    foreach (['posts', 'topics', 'members', 'postsToday', 'newestMemberId'] as $key) {
        expect($cached[$key])->toBeInt();
    }
});
