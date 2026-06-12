<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Events\Reacted;
use App\Events\ReactionRemoved;
use App\Forum\PostService;
use App\Forum\ReactionService;
use App\Models\Forum;
use App\Models\PostReactionCount;
use App\Models\Reaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\Users;

/*
| Reaction write-path integrity (P2-M1). Single-choice typed reactions: a user holds at most one reaction per
| post; the per-type tally is recomputed authoritatively from `reactions`, so it can never drift. The score
| weights went LIVE in P2-M5 (amendment-#4 light-up) — deep reputation coverage: tests/Feature/Reputation.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $this->author = Users::inGroups(['members', 'tl2'], ['username' => 'author', 'email' => 'author@react.test']);
    $this->reactor = Users::inGroups(['members', 'tl2'], ['username' => 'reactor', 'email' => 'reactor@react.test']);
    $topic = app(PostService::class)->createTopic($this->author, $forum, 'A topic', 'markdown', ['source' => 'Opening.']);
    $this->post = $topic->posts()->first();
    $this->service = app(ReactionService::class);
});

it('adds a reaction and tallies it', function () {
    expect($this->service->toggle($this->reactor, $this->post, 'like'))->toBe('like');
    expect(Reaction::where('post_id', $this->post->id)->where('user_id', $this->reactor->id)->count())->toBe(1);
    expect(PostReactionCount::where('post_id', $this->post->id)->where('type', 'like')->value('count'))->toBe(1);
});

it('toggles the same reaction off and removes its tally row', function () {
    $this->service->toggle($this->reactor, $this->post, 'like');
    expect($this->service->toggle($this->reactor, $this->post, 'like'))->toBeNull();
    expect(Reaction::where('post_id', $this->post->id)->count())->toBe(0);
    expect(PostReactionCount::where('post_id', $this->post->id)->where('type', 'like')->exists())->toBeFalse();
});

it('changes the reaction type, moving the tallies', function () {
    $this->service->toggle($this->reactor, $this->post, 'like');
    expect($this->service->toggle($this->reactor, $this->post, 'love'))->toBe('love');
    expect(Reaction::where('post_id', $this->post->id)->where('user_id', $this->reactor->id)->count())->toBe(1);
    expect(PostReactionCount::where('post_id', $this->post->id)->where('type', 'like')->exists())->toBeFalse();
    expect(PostReactionCount::where('post_id', $this->post->id)->where('type', 'love')->value('count'))->toBe(1);
});

it('enforces single-choice — a user never holds two reactions on one post', function () {
    $this->service->toggle($this->reactor, $this->post, 'like');
    $this->service->toggle($this->reactor, $this->post, 'love');
    $this->service->toggle($this->reactor, $this->post, 'helpful');
    expect(Reaction::where('post_id', $this->post->id)->where('user_id', $this->reactor->id)->count())->toBe(1);
    expect(Reaction::where('post_id', $this->post->id)->where('user_id', $this->reactor->id)->value('type'))->toBe('helpful');
});

it('tallies multiple users per type', function () {
    $other = Users::inGroups(['members', 'tl2'], ['username' => 'other', 'email' => 'other@react.test']);
    $this->service->toggle($this->reactor, $this->post, 'like');
    $this->service->toggle($other, $this->post, 'like');
    expect(PostReactionCount::where('post_id', $this->post->id)->where('type', 'like')->value('count'))->toBe(2);
});

it('rejects an unknown reaction type', function () {
    $this->service->toggle($this->reactor, $this->post, 'rocket');
})->throws(InvalidArgumentException::class);

it('dispatches Reacted on add and change, and ReactionRemoved on toggle-off', function () {
    Event::fake([Reacted::class, ReactionRemoved::class]);
    $this->service->toggle($this->reactor, $this->post, 'like'); // add
    $this->service->toggle($this->reactor, $this->post, 'love'); // change
    Event::assertDispatchedTimes(Reacted::class, 2);
    Event::assertNotDispatched(ReactionRemoved::class);
    $this->service->toggle($this->reactor, $this->post, 'love'); // toggle off → the removal event (P2-M5)
    Event::assertDispatchedTimes(Reacted::class, 2);
    Event::assertDispatchedTimes(ReactionRemoved::class, 1);
    Event::assertDispatched(Reacted::class, fn (Reacted $e) => $e->type === 'love' && $e->post->is($this->post));
    Event::assertDispatched(ReactionRemoved::class, fn (ReactionRemoved $e) => $e->post->is($this->post));
});

it('awards the author the score weight — the amendment-#4 light-up (P2-M5)', function () {
    // M1 pinned this as INERT; P2-M5 is where the weights go live (full coverage: ReactionReputationTest).
    $before = (int) $this->author->fresh()->reputation_points;
    $this->service->toggle($this->reactor, $this->post, 'helpful'); // score 2 → awarded via the ledger
    expect((int) $this->author->fresh()->reputation_points)->toBe($before + 2);
});
