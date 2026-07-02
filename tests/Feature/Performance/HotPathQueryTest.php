<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Clubs\ClubService;
use App\Forum\PostService;
use App\Models\Forum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| Performance gate (P5.4): the high-traffic read surfaces must run a BOUNDED number of queries that does NOT
| grow with the number of items on the page — the N+1 signature. Each test seeds a page-full of items (distinct
| authors, so a naive per-row author lookup would balloon) and asserts the query count stays under a ceiling.
| A regression that reintroduces an N+1 (e.g. dropping an eager-load) pushes the count over the ceiling and
| fails here. Ceilings are generous (headroom for incidental joins), tuned to catch linear growth, not to
| micro-optimise a constant.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    // Pin the SchemaState bootstrap OUT of the measured path. On a host whose working tree carries the
    // storage/installed marker (a live dev box), the first request runs the one-time pending-migrations
    // probe (+2 queries) that a markerless host (forum-dev) never pays — making these cold-render budgets
    // host-dependent. Priming the cached flag here measures the same steady state on every host: the
    // probe is a once-per-deploy cost, never a per-request one.
    app(\App\Upgrade\SchemaState::class)->refresh();
});

/** Run $fn with a fresh query log and return how many queries it issued. */
function countQueries(Closure $fn): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $n = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $n;
}

/** Seed a forum with $n topics, each by a DISTINCT author with a couple of replies (distinct repliers). */
function seedBusyForum(int $n, string $slug): Forum
{
    $forum = Forum::create(['slug' => $slug, 'title' => 'Busy '.$slug, 'type' => 'forum']);
    for ($i = 0; $i < $n; $i++) {
        $author = Users::inGroups(['members', 'tl1'], ['username' => $slug.'-a'.$i]);
        $topic = app(PostService::class)->createTopic($author, $forum, "Topic {$i} in {$slug}", 'tiptap_json', Content::doc("body {$i}"));
        app(PostService::class)->reply(Users::inGroups(['members', 'tl1'], ['username' => $slug.'-r'.$i]), $topic, 'tiptap_json', Content::doc("reply {$i}"));
    }

    return $forum;
}

it('renders the board index with a bounded query count (no per-forum N+1)', function () {
    // Several forums, each with topics → the index shows each forum's last-post info.
    foreach (range(1, 8) as $i) {
        seedBusyForum(2, "bi-f{$i}");
    }

    // The board index fragment-caches its category tree (forum.index.tree, 60s) + warms the per-request
    // resolver memo/ACL cache, so STEADY STATE — what a live server serves almost every request — is the right
    // thing to gate. (A cold first build runs more; it is amortised to once per TTL.) Warm up, then measure:
    // the steady-state count must not scale with the number of forums.
    $this->get(route('forums.index'))->assertOk(); // warm the tree + ACL caches
    $q = countQueries(fn () => $this->get(route('forums.index'))->assertOk());
    // Still < 25 after F6: resolving every forum's last topic + author is a CONSTANT +3 (one IN over topics,
    // one eager-load of the last poster, one of their groups), NOT a per-forum cost — an N+1 would blow past
    // this. (Observed ~13 before, ~16 after; the ceiling proves the resolution didn't reintroduce linear growth.)
    expect($q)->toBeLessThan(25);
})->group('perf');

it('renders a forum listing with a bounded query count regardless of topic count', function () {
    $forum = seedBusyForum(15, 'fl');

    $q = countQueries(fn () => $this->get(route('forums.show', $forum))->assertOk());
    expect($q)->toBeLessThan(40);
})->group('perf');

it('renders a topic page with a bounded query count regardless of post count', function () {
    $forum = Forum::create(['slug' => 'tp', 'title' => 'TP', 'type' => 'forum']);
    $author = Users::inGroups(['members', 'tl1']);
    $topic = app(PostService::class)->createTopic($author, $forum, 'A long thread', 'tiptap_json', Content::doc('op'));
    foreach (range(1, 15) as $i) {
        app(PostService::class)->reply(Users::inGroups(['members', 'tl1'], ['username' => "tp-r{$i}"]), $topic, 'tiptap_json', Content::doc("reply {$i}"));
    }

    $q = countQueries(fn () => $this->get(route('topics.show', $topic))->assertOk());
    // ≤41 (was ≤40): v3-g eager-loads the post authors' per-forum moderator assignments (author.moderatorAssignments)
    // so the staff flair's forum_moderator check resolves from loaded data — ONE board-wide IN query, NOT a per-post
    // cost (an N+1 would still blow far past this). (The prior +1, v3-e, was the public-Groups nav EXISTS in the
    // shared layout.) This test measures a single cold-cache render (no warm-up), so it pays the one-time miss here.
    expect($q)->toBeLessThan(42);
})->group('perf');

it('renders search results with a bounded query count', function () {
    $forum = seedBusyForum(15, 'srch');
    $member = Users::inGroups(['members', 'tl1']);

    $q = countQueries(fn () => $this->actingAs($member)->get(route('search.index', ['q' => 'body']))->assertOk());
    expect($q)->toBeLessThan(45);
})->group('perf');

it('renders the clubs directory with a bounded query count (no per-club N+1)', function () {
    $member = Users::inGroups(['members', 'tl2']);
    foreach (range(1, 10) as $i) {
        app(ClubService::class)->create(Users::inGroups(['members', 'tl2'], ['username' => "club-owner{$i}"]), ['name' => "Club {$i}", 'privacy' => 'public']);
    }

    $q = countQueries(fn () => $this->actingAs($member)->get(route('clubs.index'))->assertOk());
    expect($q)->toBeLessThan(45);
})->group('perf');
