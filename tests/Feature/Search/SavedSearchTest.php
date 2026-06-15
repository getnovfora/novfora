<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\Forum;
use App\Models\SavedSearch;
use App\Models\Tag;
use App\Models\User;
use App\Permissions\VisibleForumIds;
use App\Search\SavedSearchService;
use App\Search\SearchQueryParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| Search 6.1 — inline search OPERATORS (author:/in:/tag:/after:/before:/type:) parsed onto the existing facet
| layer, and SAVED SEARCHES (own-only, replayable).
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

// ── operators ──────────────────────────────────────────────────────────────────────────────────────────

it('parses inline operators into facet fields, leaving the residual term', function () {
    $alice = User::factory()->create(['username' => 'alice']);
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $tag = Tag::create(['name' => 'php', 'slug' => 'php', 'usage_count' => 1]);

    $parsed = SearchQueryParser::parse('hello world author:alice in:general tag:php after:2025-01-01 before:2025-12-31 type:topic');

    expect($parsed['term'])->toBe('hello world')
        ->and($parsed['authorId'])->toBe($alice->id)
        ->and($parsed['forumId'])->toBe($forum->id)
        ->and($parsed['tagIds'])->toBe([$tag->id])
        ->and($parsed['type'])->toBe('topic')
        ->and($parsed['dateFrom']?->toDateString())->toBe('2025-01-01')
        ->and($parsed['dateTo']?->toDateString())->toBe('2025-12-31');
});

it('forces an empty result for an unknown author/forum operator (id 0)', function () {
    $parsed = SearchQueryParser::parse('hi author:ghost in:nope');
    expect($parsed['authorId'])->toBe(0)->and($parsed['forumId'])->toBe(0);
});

it('bounds operator resolution to a constant number of queries (no per-token amplification)', function () {
    Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    User::factory()->create(['username' => 'alice']);

    // A pathological query: hundreds of operator tokens (keyword first so the length cap can't trim it).
    // Pre-fix this was ~1 DB query PER token.
    $raw = 'keyword '.str_repeat('tag:t author:alice in:general ', 100);

    DB::connection()->enableQueryLog();
    $parsed = SearchQueryParser::parse($raw);
    $queries = count(DB::connection()->getQueryLog());
    DB::connection()->disableQueryLog();

    // At most three lookups total (author, forum, batched tags) regardless of token count.
    expect($queries)->toBeLessThanOrEqual(3)
        ->and($parsed['term'])->toBe('keyword');
});

it('caps the number of tag operators honoured per query', function () {
    for ($i = 0; $i < 20; $i++) {
        Tag::create(['name' => "t{$i}", 'slug' => "t{$i}", 'usage_count' => 1]);
    }
    $raw = collect(range(0, 19))->map(fn ($i) => "tag:t{$i}")->implode(' ');

    // MAX_TAGS = 16: only the first 16 distinct tag operators are resolved.
    expect(count(SearchQueryParser::parse($raw)['tagIds']))->toBeLessThanOrEqual(16);
});

it('applies the author: operator end-to-end on the search page', function () {
    $forum = Forum::create(['slug' => 'g', 'title' => 'G', 'type' => 'forum']);
    $alice = Users::inGroups(['members', 'tl1'], ['username' => 'aliceq', 'email' => 'aliceq@s.test']);
    $bob = Users::inGroups(['members', 'tl1'], ['username' => 'bobq', 'email' => 'bobq@s.test']);
    $posts = app(PostService::class);
    $posts->createTopic($alice, $forum, 'AliceZonkTopic', 'tiptap_json', Content::doc('zonkberry shared body'));
    $posts->createTopic($bob, $forum, 'BobZonkTopic', 'tiptap_json', Content::doc('zonkberry shared body'));

    Cache::flush();
    VisibleForumIds::flush();

    $this->actingAs($alice)
        ->get(route('search.index', ['q' => 'zonkberry author:aliceq']))
        ->assertOk()->assertSee('AliceZonkTopic')->assertDontSee('BobZonkTopic');
});

// ── saved searches ─────────────────────────────────────────────────────────────────────────────────────

it('saves and lists a member’s own searches', function () {
    $user = User::factory()->create();
    $svc = app(SavedSearchService::class);

    $svc->save($user, 'Laravel questions', 'laravel', 'q=laravel&forum=2');

    $list = $svc->list($user);
    expect($list)->toHaveCount(1)
        ->and($list->first()->name)->toBe('Laravel questions')
        ->and($list->first()->query_string)->toBe('q=laravel&forum=2');
});

it('delete is own-only (one member cannot remove another’s saved search)', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $svc = app(SavedSearchService::class);
    $row = $svc->save($alice, 'Mine', 'x', 'q=x');

    expect($svc->delete($bob, $row->id))->toBeFalse()           // bob can't delete alice's
        ->and(SavedSearch::whereKey($row->id)->exists())->toBeTrue();

    expect($svc->delete($alice, $row->id))->toBeTrue()          // alice can
        ->and(SavedSearch::whereKey($row->id)->exists())->toBeFalse();
});

it('saves a search from the results page and shows it on the list', function () {
    $user = Users::inGroups(['members']);

    $this->actingAs($user)
        ->post(route('saved-searches.store'), ['name' => 'My saved q', 'q' => 'widgets', 'query_string' => 'q=widgets&type=topic'])
        ->assertRedirect();

    expect(SavedSearch::where('user_id', $user->id)->where('name', 'My saved q')->exists())->toBeTrue();

    $this->actingAs($user)->get(route('saved-searches.index'))->assertOk()->assertSee('My saved q');
});

it('offers a "Save this search" control to a signed-in member after a search', function () {
    $user = Users::inGroups(['members']);
    $this->actingAs($user)->get(route('search.index', ['q' => 'anything']))->assertOk()->assertSee('Save this search');
});
