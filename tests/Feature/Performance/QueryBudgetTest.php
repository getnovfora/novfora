<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Community\BadgeService;
use App\Community\FollowService;
use App\Forum\PollService;
use App\Forum\PostService;
use App\Forum\ReactionService;
use App\Models\Forum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Content;
use Tests\Support\Users;

uses(RefreshDatabase::class);

/*
| Performance budgets — query counts per page (system-architecture §7; Phase 1 exit criterion 4). These
| are the N+1 guard: a regression that adds a per-row query blows the budget and fails CI.
|
| Each test WARMS the page once (populating the fragment + resolved-permission caches the budgets assume
| at medium scale), then measures a second, steady-state request. An N+1 recurs on every request, so it
| still trips the budget; only the one-off cold-cache overhead is excluded. The pages are seeded with
| many rows precisely so an N+1 would be unmistakable.
*/

/** Count the DB queries a single request issues. */
function queriesFor(Closure $request): int
{
    $count = 0;
    DB::listen(function () use (&$count) {
        $count++;
    });
    $request();
    DB::flushQueryLog();

    return $count;
}

it('renders a busy thread within the query budget (≤30, no N+1)', function () {
    $this->seed();
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);

    // Several distinct authors so a per-post author query (N+1) would be obvious.
    $authors = collect(['tl2', 'tl3', 'tl4'])
        ->map(fn ($tl, $i) => Users::inGroups(['members', $tl], ['username' => "author{$i}", 'email' => "author{$i}@budget.test"]));

    $posts = app(PostService::class);
    $topic = $posts->createTopic($authors[0], $forum, 'A busy thread', 'markdown', ['source' => 'Opening post.']);
    for ($i = 0; $i < 16; $i++) {
        $posts->reply($authors[$i % 3], $topic, 'markdown', ['source' => "Reply number {$i}."]);
    }

    $viewer = Users::inGroups(['members', 'tl2'], ['username' => 'viewer', 'email' => 'viewer@budget.test']);

    // Reactions on the hot path (amendment #6): the viewer's own pick + a second reactor on the first page's
    // posts, so both the RH-9-cached tally read and the per-viewer highlight query are exercised. The thread
    // must still hold ≤30 — reactions add the count cache (1 GET, warm) + one batched viewer-pick query.
    $reactions = app(ReactionService::class);
    foreach ($topic->posts()->orderBy('position')->orderBy('id')->take(10)->get() as $i => $p) {
        $reactions->toggle($viewer, $p, 'like');
        $reactions->toggle($authors[$i % 3], $p, 'helpful');
    }

    // A poll on the thread too (amendment #6): it renders at the top, adding its RH-9-cached display data
    // (a warm cache GET, 0 domain queries) + the viewer's picks (1 batched query). The thread must STILL
    // hold ≤30 with reactions AND a poll present — the integrated worst case.
    $polls = app(PollService::class);
    $poll = $polls->createPoll($authors[0], $topic, 'Which is best?', ['A', 'B', 'C']);
    $polls->vote($viewer, $poll, [$poll->options->first()->id]);
    $polls->vote($authors[1], $poll, [$poll->options->last()->id]);

    // Warm the caches, then measure the steady-state request.
    $this->actingAs($viewer)->get(route('topics.show', $topic))->assertOk();
    $queries = queriesFor(fn () => $this->actingAs($viewer)->get(route('topics.show', $topic))->assertOk());

    expect($queries)->toBeLessThanOrEqual(30);
});

it('renders the forum index (now hosting the activity feed) within the query budget (≤20, no N+1)', function () {
    $this->seed();

    // A category with several forums — a per-forum query (N+1) would push well past the budget.
    $category = Forum::create(['slug' => 'cat', 'title' => 'Category', 'type' => 'category']);
    $author = Users::inGroups(['members', 'tl3'], ['username' => 'idxauthor', 'email' => 'idx@budget.test']);
    $posts = app(PostService::class);

    foreach (range(1, 6) as $n) {
        $forum = Forum::create(['slug' => "forum-{$n}", 'title' => "Forum {$n}", 'type' => 'forum', 'parent_id' => $category->id]);
        $posts->createTopic($author, $forum, "Topic in {$n}", 'markdown', ['source' => "Body {$n}."]);
    }

    $viewer = Users::inGroups(['members', 'tl2'], ['username' => 'idxviewer', 'email' => 'idxviewer@budget.test']);

    $this->actingAs($viewer)->get(route('forums.index'))->assertOk();
    $queries = queriesFor(fn () => $this->actingAs($viewer)->get(route('forums.index'))->assertOk());

    // ≤20 (was ≤15): the P2-M3 activity feed adds the permission filter (VisibleForumIds, memoised) + the
    // post-cache rehydration (batched actor/subject loads). Recorded in DECISIONS per amendment #6.
    expect($queries)->toBeLessThanOrEqual(20);
});

it('renders a faceted search results page within the query budget (≤25, no N+1)', function () {
    $this->seed();

    // A category with several forums so the visible-forum resolution + the forum-facet dropdown are exercised;
    // many matching posts so a per-result query (N+1) would be obvious.
    $category = Forum::create(['slug' => 'cat', 'title' => 'Category', 'type' => 'category']);
    $posts = app(PostService::class);
    $forums = collect(range(1, 4))->map(fn ($n) => Forum::create(['slug' => "f{$n}", 'title' => "Forum {$n}", 'type' => 'forum', 'parent_id' => $category->id]));
    $author = Users::inGroups(['members', 'tl2'], ['username' => 'searchauthor', 'email' => 'sa@budget.test']);
    foreach ($forums as $forum) {
        for ($i = 0; $i < 4; $i++) {
            $posts->createTopic($author, $forum, "Searchable {$forum->slug} {$i}", 'tiptap_json', Content::doc("budget keyword zonk {$i}"));
        }
    }

    $viewer = Users::inGroups(['members', 'tl2'], ['username' => 'searchviewer', 'email' => 'sv@budget.test']);
    $url = route('search.index', ['q' => 'zonk', 'forum' => $forums->first()->id, 'from' => now()->subWeek()->toDateString()]);

    $this->actingAs($viewer)->get($url)->assertOk();
    $queries = queriesFor(fn () => $this->actingAs($viewer)->get($url)->assertOk());

    expect($queries)->toBeLessThanOrEqual(25);
});

it('renders a member profile (follow + reputation + badges) within the query budget (≤20, no N+1)', function () {
    $this->seed();
    $forum = Forum::create(['slug' => 'profforum', 'title' => 'Profile forum', 'type' => 'forum']);
    $posts = app(PostService::class);

    // A profile owner with the full P2-M5 social surface: posts (badge triggers), received reactions
    // (reputation), followers, and earned badges — the worst-case profile render.
    $owner = Users::inGroups(['members', 'tl3'], ['username' => 'profowner', 'email' => 'po@budget.test']);
    $topic = $posts->createTopic($owner, $forum, 'Profile owner topic', 'markdown', ['source' => 'Body.']);

    $fans = collect(range(1, 3))
        ->map(fn ($n) => Users::inGroups(['members', 'tl2'], ['username' => "fan{$n}", 'email' => "fan{$n}@budget.test"]));
    $reactions = app(ReactionService::class);
    $follows = app(FollowService::class);
    foreach ($fans as $fan) {
        $reactions->toggle($fan, $topic->posts()->first(), 'helpful');
        $follows->follow($fan, $owner);
    }
    app(BadgeService::class)->evaluate($owner);

    $viewer = Users::inGroups(['members', 'tl2'], ['username' => 'profviewer', 'email' => 'pv@budget.test']);

    $this->actingAs($viewer)->get(route('profiles.show', $owner))->assertOk();
    $queries = queriesFor(fn () => $this->actingAs($viewer)->get(route('profiles.show', $owner))->assertOk());

    // ≤20: the P2-M5 surfaces add the follow panel (target + edge check + two COUNTs) and the earned-badge
    // chips (one badge query) on top of the custom-field/profile base. New documented ceiling (DECISIONS).
    expect($queries)->toBeLessThanOrEqual(20);
});

it('renders a moderator’s thread (bulk-select + merge UI) within the query budget (≤35, no N+1)', function () {
    $this->seed();
    $forum = Forum::create(['slug' => 'modforum', 'title' => 'Mod forum', 'type' => 'forum']);

    $posts = app(PostService::class);
    $authors = collect(['tl2', 'tl3', 'tl4'])
        ->map(fn ($tl, $i) => Users::inGroups(['members', $tl], ['username' => "bauthor{$i}", 'email' => "bauthor{$i}@budget.test"]));
    $topic = $posts->createTopic($authors[0], $forum, 'A thread a mod will moderate', 'tiptap_json', Content::doc('Opening post.'));
    // A few sibling topics so the merge-modal candidate query has rows to return (a per-candidate N+1 would show).
    foreach (range(1, 4) as $n) {
        $posts->createTopic($authors[$n % 3], $forum, "Sibling {$n}", 'tiptap_json', Content::doc("Sibling body {$n}."));
    }
    for ($i = 0; $i < 16; $i++) {
        $posts->reply($authors[$i % 3], $topic, 'tiptap_json', Content::doc("Reply number {$i}."));
    }

    $mod = Users::inGroups(['moderators'], ['username' => 'budgetmod', 'email' => 'budgetmod@budget.test']);

    // The moderator view renders the merge-topic modal (candidate query) + the bulk-actions bar + per-post
    // checkboxes — the bulk-select worst case. Warm, then measure the steady state.
    $this->actingAs($mod)->get(route('topics.show', $topic))->assertOk();
    $queries = queriesFor(fn () => $this->actingAs($mod)->get(route('topics.show', $topic))->assertOk());

    expect($queries)->toBeLessThanOrEqual(35);
});
