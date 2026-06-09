<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Forum\ReactionService;
use App\Models\Forum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\Users;

/*
| RH-9 — the thread-page reaction tally must survive a cache HIT through a SERIALISING store and re-query
| nothing on a hit. config/cache.php hardens the cache with serializable_classes => false; an OBJECT cached
| here would deserialize to __PHP_Incomplete_Class on a real (database/file/redis) store. So the tally is
| cached as a primitives-only nested array, ONE entry per (topic, version) — a single GET serves the whole
| page (never a per-post N+1 against the cache table), version-bumped on any reaction change.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['cache.default' => 'database']); // the live-host serialising store (array store would mask the bug)
    $this->seed();
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $author = Users::inGroups(['members', 'tl2'], ['username' => 'author', 'email' => 'author@cache.test']);
    $this->topic = app(PostService::class)->createTopic($author, $forum, 'A topic', 'markdown', ['source' => 'Opening.']);
    $this->post = $this->topic->posts()->first();
    $this->service = app(ReactionService::class);
    $reactor = Users::inGroups(['members', 'tl2'], ['username' => 'reactor', 'email' => 'reactor@cache.test']);
    $this->service->toggle($reactor, $this->post, 'like');
});

it('caches the tally as a primitive array — never a model or Collection (RH-9)', function () {
    $this->service->countsForTopic($this->topic->id, [$this->post->id]);

    $version = (int) Cache::get("hearth.reactions.ver.t{$this->topic->id}", 0);
    $cached = Cache::store('database')->get("hearth.reactions.counts.t{$this->topic->id}.v{$version}");

    expect($cached)->toBeArray()
        ->and($cached[$this->post->id])->toBeArray()
        ->and($cached[$this->post->id]['like'])->toBe(1);
});

it('serves a cache HIT through a serialising store with zero re-query against the tally table (RH-9)', function () {
    $first = $this->service->countsForTopic($this->topic->id, [$this->post->id]);
    expect($first[$this->post->id]['like'])->toBe(1);

    $domainQueries = 0;
    DB::listen(function ($q) use (&$domainQueries) {
        if (str_contains($q->sql, 'post_reaction_counts')) {
            $domainQueries++;
        }
    });

    $second = $this->service->countsForTopic($this->topic->id, [$this->post->id]);
    expect($second[$this->post->id]['like'])->toBe(1)
        ->and($domainQueries)->toBe(0);
});

it('bumps the version on a new reaction so a stale entry is never read (RH-9)', function () {
    $this->service->countsForTopic($this->topic->id, [$this->post->id]); // populate at the current version

    $reactor2 = Users::inGroups(['members', 'tl2'], ['username' => 'reactor2', 'email' => 'reactor2@cache.test']);
    $this->service->toggle($reactor2, $this->post, 'like'); // mutate → version bumps

    $fresh = $this->service->countsForTopic($this->topic->id, [$this->post->id]);
    expect($fresh[$this->post->id]['like'])->toBe(2); // the new count, not the stale cached 1
});
