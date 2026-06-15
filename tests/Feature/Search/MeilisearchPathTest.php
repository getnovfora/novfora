<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Clubs\ClubService;
use App\Forum\PostService;
use App\Models\Forum;
use App\Models\Post;
use App\Models\User;
use App\Search\SearchQuery;
use App\Search\SearchService;
use App\Services\Tier\ServiceTier;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Engine;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| Phase 4 · M4.1 — the enhanced Meilisearch execution path of the faceted search page. Validated WITHOUT a
| real Meilisearch by faking the Scout engine: relevance pass-through, the defense-in-depth visibility
| re-gate (the index is never trusted alone), facet gating, and graceful degradation to the database tier.
| NOT validated against a real Meilisearch instance — see ADR-0060.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

/** Force the Search capability to read as Enhanced (meilisearch) without running real service probes. */
function enhanceSearch(): void
{
    app()->instance(ServiceTier::class, new ServiceTier([]));
    config(['scout.driver' => 'meilisearch']);
}

/** Swap the Meilisearch Scout engine for a fake whose ->get() yields exactly $models (Eloquent collection). */
function fakeMeiliReturning(array $models): void
{
    $engine = Mockery::mock(Engine::class)->makePartial();
    $engine->shouldReceive('search')->andReturn(['hits' => []]);
    $engine->shouldReceive('map')->andReturn(new EloquentCollection($models));
    $engine->shouldReceive('getTotalCount')->andReturn(count($models));
    app(EngineManager::class)->extend('meilisearch', fn () => $engine);
}

function plainSearchForum(): Forum
{
    return Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
}

// ── The privacy fence: the index is never the sole gate ──────────────────────────────────────────────────

it('runs the enhanced engine but re-gates a hidden-club hit out of the results (no leak)', function () {
    // A public post the engine should surface …
    $publicTopic = app(PostService::class)->createTopic(Users::inGroups(['members', 'tl2']), plainSearchForum(), 'Public', 'tiptap_json', Content::doc('alpha public widget'));
    $publicPost = Post::where('topic_id', $publicTopic->id)->firstOrFail();

    // … and a HIDDEN-CLUB post that a stale/poisoned index ALSO returns but the viewer must never see.
    $owner = Users::inGroups(['members', 'tl3'], ['email' => 'owner@meili.test']);
    $club = app(ClubService::class)->create($owner, ['name' => 'Secret', 'privacy' => 'private', 'is_listed' => false]);
    $clubForum = Forum::where('club_id', $club->id)->firstOrFail();
    $clubTopic = app(PostService::class)->createTopic($owner, $clubForum, 'Secret', 'tiptap_json', Content::doc('alpha secret club'));
    $clubPost = Post::where('topic_id', $clubTopic->id)->firstOrFail();

    enhanceSearch();
    fakeMeiliReturning([$publicPost, $clubPost]); // the index leaks the club post …

    // … a guest searches; the engine path must drop the club hit at the re-gate.
    $results = app(SearchService::class)->search(new SearchQuery(viewer: User::guest(), term: 'alpha'));
    $bodies = $results->pluck('body_text')->implode(' ');

    expect($bodies)->toContain('alpha public widget');
    expect($bodies)->not->toContain('alpha secret club');
});

it('lets an active club member see their own club hit on the enhanced path', function () {
    $owner = Users::inGroups(['members', 'tl3'], ['email' => 'mem-owner@meili.test']);
    $club = app(ClubService::class)->create($owner, ['name' => 'Inner', 'privacy' => 'private', 'is_listed' => false]);
    $clubForum = Forum::where('club_id', $club->id)->firstOrFail();
    $clubTopic = app(PostService::class)->createTopic($owner, $clubForum, 'Inner', 'tiptap_json', Content::doc('omega members only'));
    $clubPost = Post::where('topic_id', $clubTopic->id)->firstOrFail();

    enhanceSearch();
    fakeMeiliReturning([$clubPost]);

    $results = app(SearchService::class)->search(new SearchQuery(viewer: $owner->fresh(), term: 'omega'));

    expect($results->pluck('body_text')->implode(' '))->toContain('omega members only');
});

// ── Graceful degradation: the baseline DB tier is always correct ─────────────────────────────────────────

it('degrades the faceted path to the database when the enhanced engine errors', function () {
    app(PostService::class)->createTopic(Users::inGroups(['members', 'tl2']), plainSearchForum(), 'T', 'tiptap_json', Content::doc('please frobnicate the gimbals'));

    enhanceSearch();
    $engine = Mockery::mock(Engine::class)->makePartial();
    $engine->shouldReceive('search')->andThrow(new RuntimeException('meili down'));
    app(EngineManager::class)->extend('meilisearch', fn () => $engine);

    $results = app(SearchService::class)->search(new SearchQuery(viewer: Users::inGroups(['members', 'tl2'], ['email' => 'frob@meili.test']), term: 'frobnicate'));

    expect($results->pluck('body_text')->implode(' '))->toContain('frobnicate');
});

// ── Facet gating: tag/type facets are not engine-expressible → they stay on the DB engine ───────────────

it('keeps a type-faceted query on the database engine (the enhanced engine is not consulted)', function () {
    app(PostService::class)->createTopic(Users::inGroups(['members', 'tl2']), plainSearchForum(), 'T', 'tiptap_json', Content::doc('beta gadget content'));

    enhanceSearch();
    fakeMeiliReturning([]); // if the engine WERE consulted, the result would be empty

    $results = app(SearchService::class)->search(new SearchQuery(viewer: Users::inGroups(['members', 'tl2'], ['email' => 'beta@meili.test']), term: 'beta', type: 'topic'));

    expect($results)->not->toBeEmpty();
    expect($results->pluck('body_text')->implode(' '))->toContain('beta gadget');
});

// ── Baseline driver never reaches the enhanced engine ────────────────────────────────────────────────────

it('never consults the enhanced engine on the baseline database driver', function () {
    app(PostService::class)->createTopic(Users::inGroups(['members', 'tl2']), plainSearchForum(), 'T', 'tiptap_json', Content::doc('gamma sprocket assembly'));

    // driver stays 'database' (test default). Poison the meili engine — it must never be reached.
    fakeMeiliReturning([]);

    $results = app(SearchService::class)->search(new SearchQuery(viewer: Users::inGroups(['members', 'tl2'], ['email' => 'gamma@meili.test']), term: 'gamma'));

    expect($results->pluck('body_text')->implode(' '))->toContain('gamma sprocket');
});
