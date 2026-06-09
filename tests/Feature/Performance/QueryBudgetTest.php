<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Forum\ReactionService;
use App\Models\Forum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    // Warm the caches, then measure the steady-state request.
    $this->actingAs($viewer)->get(route('topics.show', $topic))->assertOk();
    $queries = queriesFor(fn () => $this->actingAs($viewer)->get(route('topics.show', $topic))->assertOk());

    expect($queries)->toBeLessThanOrEqual(30);
});

it('renders the forum index within the query budget (≤15, no N+1)', function () {
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

    expect($queries)->toBeLessThanOrEqual(15);
});
