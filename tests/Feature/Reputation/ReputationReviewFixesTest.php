<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Community\ReputationService;
use App\Forum\PostService;
use App\Forum\ReactionService;
use App\Models\Forum;
use App\Models\Reaction;
use App\Models\ReputationEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

/*
| Pins for the P2-M5 adversarial-review fixes (⚙): the award source-existence guard (the orphan-ledger
| TOCTOU root), the soft-delete carve-out, and the recompute drift-only write.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
});

it('refuses to award when the source row no longer exists (the orphan-ledger guard)', function () {
    $recipient = Users::inGroups(['members', 'tl1']);
    $reactor = Users::inGroups(['members', 'tl1']);
    $forum = Forum::create(['slug' => 'guard', 'title' => 'Guard', 'type' => 'forum']);
    $topic = app(PostService::class)->createTopic($recipient, $forum, 'Guarded', 'markdown', ['source' => 'b']);
    $post = $topic->posts()->first();

    // A real reaction whose row is deleted before the (late) award job lands — the in-memory model still
    // carries the id, exactly what a queued listener would hold.
    app(ReactionService::class)->toggle($reactor, $post, 'like');
    $reaction = Reaction::where('post_id', $post->id)->where('user_id', $reactor->id)->firstOrFail();
    ReputationEvent::query()->delete(); // wipe the inline (sync-queue) award — we replay the job manually
    User::whereKey($recipient->id)->update(['reputation_points' => 0]);
    Reaction::whereKey($reaction->id)->delete(); // the unreact/cascade delete committed first

    expect(app(ReputationService::class)->award($recipient, $reaction, 1))->toBeFalse()
        ->and(ReputationEvent::count())->toBe(0)            // no orphan ledger row
        ->and($recipient->fresh()->reputation_points)->toBe(0);
});

it('still awards for a SOFT-deleted source (reversible moderation keeps rep)', function () {
    $recipient = Users::inGroups(['members', 'tl1']);
    $forum = Forum::create(['slug' => 'soft', 'title' => 'Soft', 'type' => 'forum']);
    $topic = app(PostService::class)->createTopic($recipient, $forum, 'Soft topic', 'markdown', ['source' => 'b']);
    $post = $topic->posts()->first();
    $post->delete(); // soft delete — the row exists, trashed

    expect(app(ReputationService::class)->award($recipient, $post, 2))->toBeTrue()
        ->and($recipient->fresh()->reputation_points)->toBe(2);
});

it('recomputeFor leaves an already-correct denorm untouched (drift-only writes)', function () {
    $a = Users::inGroups(['members', 'tl1']);
    $reactor = Users::inGroups(['members', 'tl1']);
    $forum = Forum::create(['slug' => 'drift', 'title' => 'Drift', 'type' => 'forum']);
    $topic = app(PostService::class)->createTopic($a, $forum, 'Drift topic', 'markdown', ['source' => 'b']);
    app(ReactionService::class)->toggle($reactor, $topic->posts()->first(), 'like'); // +1 banked inline

    $before = $a->fresh()->updated_at;
    $this->travel(5)->minutes();

    app(ReputationService::class)->recomputeFor([(int) $a->id]);

    expect($a->fresh()->reputation_points)->toBe(1)
        ->and($a->fresh()->updated_at?->toIso8601String())->toBe($before?->toIso8601String()); // no rewrite when aligned
});
