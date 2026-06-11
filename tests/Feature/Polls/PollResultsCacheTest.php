<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PollService;
use App\Forum\PostService;
use App\Models\Forum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\Users;

/*
| RH-9 — poll display data must survive a cache HIT through a serialising store and re-query nothing on a hit.
| Cached as a primitives-only array per (poll, version), version-bumped on every vote/close. The effective
| closed state is computed AFTER the boundary (raw is_closed + closes_at are cached, not a frozen verdict).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['cache.default' => 'database']);
    $this->seed();
    $this->service = app(PollService::class);
    $author = Users::inGroups(['members', 'tl2'], ['username' => 'author', 'email' => 'author@pollcache.test']);
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $topic = app(PostService::class)->createTopic($author, $forum, 'Poll', 'markdown', ['source' => 'Body.']);
    $this->poll = $this->service->createPoll($author, $topic, 'Q?', ['A', 'B', 'C']);
    $this->voter = Users::inGroups(['members', 'tl2'], ['username' => 'voter', 'email' => 'voter@pollcache.test']);
    $this->service->vote($this->voter, $this->poll, [$this->poll->options->first()->id]);
});

it('caches poll display data as a primitive array (RH-9)', function () {
    $this->service->displayData($this->poll->fresh());

    $version = (int) Cache::get("novfora.poll.ver.p{$this->poll->id}", 0);
    $cached = Cache::store('database')->get("novfora.poll.display.p{$this->poll->id}.v{$version}");

    expect($cached)->toBeArray()
        ->and($cached['question'])->toBeString()
        ->and($cached['options'])->toBeArray()
        ->and($cached['options'][0]['count'])->toBe(1)
        ->and($cached['total_voters'])->toBe(1);
});

it('serves a cache HIT through a serialising store with zero re-query (RH-9)', function () {
    $this->service->displayData($this->poll->fresh()); // MISS → populate

    $domainQueries = 0;
    DB::listen(function ($q) use (&$domainQueries) {
        if (str_contains($q->sql, 'poll_options') || str_contains($q->sql, 'poll_votes')) {
            $domainQueries++;
        }
    });

    $data = $this->service->displayData($this->poll->fresh());
    expect($data['options'][0]['count'])->toBe(1)->and($domainQueries)->toBe(0);
});

it('bumps the version on a new vote so the tally is never stale (RH-9)', function () {
    $this->service->displayData($this->poll->fresh()); // populate

    $voter2 = Users::inGroups(['members', 'tl2'], ['username' => 'voter2', 'email' => 'voter2@pollcache.test']);
    $this->service->vote($voter2, $this->poll, [$this->poll->options->first()->id]); // bumps version

    $data = $this->service->displayData($this->poll->fresh());
    expect($data['options'][0]['count'])->toBe(2)->and($data['total_voters'])->toBe(2);
});
