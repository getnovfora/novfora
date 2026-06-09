<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Forum\PrefixManager;
use App\Models\Forum;
use App\Models\Prefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function listingForum(): Forum
{
    return Forum::firstOrCreate(['slug' => 'board'], ['title' => 'Board', 'type' => 'forum']);
}

it('topic with a prefix shows the prefix badge on the board', function () {
    $forum = listingForum();
    $prefix = app(PrefixManager::class)->create(['label' => 'Guide']);
    $author = Users::inGroups(['members', 'tl2']);
    app(PostService::class)->createTopic($author, $forum, 'My Guide', 'markdown', ['source' => 'Body.'], $prefix->id);

    $this->actingAs($author)->get(route('forums.show', $forum))
        ->assertOk()
        ->assertSee('Guide');
});

it('prefix filter hides topics that do not have that prefix', function () {
    $forum = listingForum();
    $prefix = app(PrefixManager::class)->create(['label' => 'Solved']);
    $author = Users::inGroups(['members', 'tl2']);

    // Topic WITH the prefix.
    app(PostService::class)->createTopic($author, $forum, 'Solved Topic', 'markdown', ['source' => 'Body.'], $prefix->id);
    // Topic WITHOUT any prefix.
    app(PostService::class)->createTopic($author, $forum, 'Untagged Topic', 'markdown', ['source' => 'Body.']);

    $this->actingAs($author)
        ->get(route('forums.show', $forum).'?prefix='.$prefix->id)
        ->assertOk()
        ->assertSee('Solved Topic')
        ->assertDontSee('Untagged Topic');
});

it('prefix-less topics are hidden when a prefix filter is active', function () {
    $forum = listingForum();
    $prefix = app(PrefixManager::class)->create(['label' => 'FAQ']);
    $author = Users::inGroups(['members', 'tl2']);

    app(PostService::class)->createTopic($author, $forum, 'Has prefix', 'markdown', ['source' => 'Body.'], $prefix->id);
    app(PostService::class)->createTopic($author, $forum, 'No prefix here', 'markdown', ['source' => 'Body.']);

    $this->actingAs($author)
        ->get(route('forums.show', $forum).'?prefix='.$prefix->id)
        ->assertOk()
        ->assertDontSee('No prefix here');
});

it('keeps the prefix-filtered board within budget and free of N+1', function () {
    $forum = listingForum();
    $prefix = app(PrefixManager::class)->create(['label' => 'Budget']);
    $author = Users::inGroups(['members', 'tl2']);

    // MANY prefixed topics so an unbatched per-topic query (the prefix N+1) would be unmistakable.
    for ($i = 1; $i <= 15; $i++) {
        app(PostService::class)->createTopic($author, $forum, "Topic {$i}", 'markdown', ['source' => 'Body.'], $prefix->id);
    }

    $url = route('forums.show', $forum).'?prefix='.$prefix->id;

    // Warm the per-request caches (resolved permissions, settings), then measure the steady state — an N+1
    // recurs on every request so it still trips the budget; only one-off cold-cache cost is excluded.
    $this->actingAs($author)->get($url)->assertOk();

    $count = 0;
    DB::listen(function () use (&$count) {
        $count++;
    });
    $this->actingAs($author)->get($url)->assertOk();

    // The prefix relation is eager-loaded, so 15 topics cost the same as 1 — bounded, no N+1. (A per-topic
    // prefix lookup would add ~15 queries and blow this.)
    expect($count)->toBeLessThanOrEqual(25);
});
