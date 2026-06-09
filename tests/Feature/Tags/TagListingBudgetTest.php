<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Forum\TagService;
use App\Models\Forum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

/**
 * N+1 budget tests for tags (P2-M1).
 *
 * tags are eager-loaded via ->with('tags') on the ForumController + TagController queries.
 * A per-topic tag query would add ~N extra queries and blow the tight budget.
 */
it('board view with tagged topics stays within query budget (no N+1 on tags)', function () {
    $forum = Forum::firstOrCreate(['slug' => 'budget-board'], ['title' => 'Budget', 'type' => 'forum']);
    $author = Users::inGroups(['members', 'tl2']);
    $svc = app(TagService::class);
    $tag = $svc->create('popular');
    $pService = app(PostService::class);

    for ($i = 1; $i <= 15; $i++) {
        $topic = $pService->createTopic($author, $forum, "Topic {$i}", 'markdown', ['source' => 'Body.']);
        $svc->syncTopicTags($topic, [$tag->id]);
    }

    $url = route('forums.show', $forum);

    // Warm caches (permission, settings, fragment).
    $this->actingAs($author)->get($url)->assertOk();

    $count = 0;
    DB::listen(function () use (&$count) {
        $count++;
    });
    $this->actingAs($author)->get($url)->assertOk();

    // Same budget as the prefix listing test: ≤ 25 queries for a warmed 15-topic page.
    expect($count)->toBeLessThanOrEqual(25);
});

it('tags.show with many topics stays within query budget (no N+1)', function () {
    $forum = Forum::firstOrCreate(['slug' => 'budget-board2'], ['title' => 'Budget2', 'type' => 'forum']);
    $author = Users::inGroups(['members', 'tl2']);
    $svc = app(TagService::class);
    $tag = $svc->create('listing-budget');
    $pService = app(PostService::class);

    for ($i = 1; $i <= 15; $i++) {
        $topic = $pService->createTopic($author, $forum, "Listing Topic {$i}", 'markdown', ['source' => 'Body.']);
        $svc->syncTopicTags($topic, [$tag->id]);
    }

    $url = route('tags.show', $tag);

    // Warm caches.
    $this->actingAs($author)->get($url)->assertOk();

    $count = 0;
    DB::listen(function () use (&$count) {
        $count++;
    });
    $this->actingAs($author)->get($url)->assertOk();

    // The tags.show page filters topics by forum.view (per-unique-forum ACL check) — these resolve through the
    // permission cache but add a few extra queries versus the simple board view. The key invariant is that
    // this stays BOUNDED (does not grow linearly with topic count): 15 topics in one forum costs the same
    // as 1 topic. Allowing a slightly wider budget than the simple board test to accommodate the extra
    // forum-permission resolution overhead.
    expect($count)->toBeLessThanOrEqual(45);
});
