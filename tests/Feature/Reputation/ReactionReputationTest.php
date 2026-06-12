<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Events\Reacted;
use App\Forum\PostService;
use App\Forum\ReactionService;
use App\Models\Forum;
use App\Models\Post;
use App\Models\Reaction;
use App\Models\ReputationEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| The amendment-#4 light-up (P2-M5 ⚙): a received reaction awards the post author its config score weight
| via the idempotent ledger — wired as QUEUED listeners off the react hot path. Covers add/change/unreact,
| the self-reaction guard, the double-fired-event no-op, and the ≤15 react-action query budget.
| (QUEUE_CONNECTION=sync in tests runs the listeners inline at dispatch, post-commit.)
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    config(['novfora.reactions.types' => [
        'like' => ['label' => 'Like', 'emoji' => '👍', 'score' => 1],
        'helpful' => ['label' => 'Helpful', 'emoji' => '💡', 'score' => 2],
        'funny' => ['label' => 'Funny', 'emoji' => '😄', 'score' => 0],
        'disagree' => ['label' => 'Disagree', 'emoji' => '👎', 'score' => -1],
    ]]);
});

/** A real post through the real write path (no Post factory exists — the PostService pattern). */
function reputationWiredPost(User $author): Post
{
    $forum = Forum::firstOrCreate(['slug' => 'rep-wire'], ['title' => 'Rep wire', 'type' => 'forum']);
    $topic = app(PostService::class)->createTopic($author, $forum, 'Topic '.Str::random(8), 'markdown', ['source' => 'Opening.']);

    return $topic->posts()->first();
}

it('awards the author the type weight on react, and adjusts on a type change', function () {
    $reactor = Users::inGroups(['members', 'tl1']);
    $author = Users::inGroups(['members', 'tl1']);
    $post = reputationWiredPost($author);
    $service = app(ReactionService::class);

    $service->toggle($reactor, $post, 'like');
    expect($author->fresh()->reputation_points)->toBe(1)
        ->and(ReputationEvent::count())->toBe(1);

    $service->toggle($reactor, $post, 'helpful'); // change: same reaction row, the UNIQUE slot re-points
    expect($author->fresh()->reputation_points)->toBe(2)
        ->and(ReputationEvent::count())->toBe(1);

    $service->toggle($reactor, $post, 'disagree'); // negative weight
    expect($author->fresh()->reputation_points)->toBe(-1);

    $service->toggle($reactor, $post, 'funny'); // zero weight clears the slot
    expect($author->fresh()->reputation_points)->toBe(0)
        ->and(ReputationEvent::count())->toBe(0);
});

it('revokes the award on unreact', function () {
    $reactor = Users::inGroups(['members', 'tl1']);
    $author = Users::inGroups(['members', 'tl1']);
    $post = reputationWiredPost($author);
    $service = app(ReactionService::class);

    $service->toggle($reactor, $post, 'helpful');
    expect($author->fresh()->reputation_points)->toBe(2);

    $service->toggle($reactor, $post, 'helpful'); // toggle off
    expect($author->fresh()->reputation_points)->toBe(0)
        ->and(ReputationEvent::count())->toBe(0);
});

it('awards nothing for a self-reaction', function () {
    $author = Users::inGroups(['members', 'tl1']);
    $post = reputationWiredPost($author);

    app(ReactionService::class)->toggle($author, $post, 'helpful');

    expect($author->fresh()->reputation_points)->toBe(0)
        ->and(ReputationEvent::count())->toBe(0);
});

it('leaves the count unchanged when the Reacted event is double-fired', function () {
    $reactor = Users::inGroups(['members', 'tl1']);
    $author = Users::inGroups(['members', 'tl1']);
    $post = reputationWiredPost($author);
    $service = app(ReactionService::class);

    $service->toggle($reactor, $post, 'helpful');
    expect($author->fresh()->reputation_points)->toBe(2);

    Reacted::dispatch($reactor, $post->fresh(), 'helpful'); // the replayed/duplicated event

    expect($author->fresh()->reputation_points)->toBe(2) // no double count
        ->and(ReputationEvent::count())->toBe(1);
});

it('skips the award when the reaction vanished before the queue drained', function () {
    $reactor = Users::inGroups(['members', 'tl1']);
    $author = Users::inGroups(['members', 'tl1']);
    $post = reputationWiredPost($author);

    // A Reacted event whose reaction row no longer exists (unreacted before the cron drained the queue).
    Reacted::dispatch($reactor, $post, 'helpful');

    expect($author->fresh()->reputation_points)->toBe(0)
        ->and(ReputationEvent::count())->toBe(0);
});

it('holds the react action to the ≤15 query budget with the award queued off the hot path', function () {
    config(['queue.default' => 'database']); // production shape: listeners stage a jobs row, never run inline
    $reactor = Users::inGroups(['members', 'tl1']);
    $post = reputationWiredPost(Users::inGroups(['members', 'tl1']));

    $this->actingAs($reactor);
    $component = Livewire::test('forum.post-reactions', [
        'postId' => (int) $post->id,
        'topicId' => (int) $post->topic_id,
        'canReact' => true,
    ]);

    // Steady-state measurement (the QueryBudgetTest convention): the first action warms the permission
    // resolver + ACL caches; the SECOND action — a type change, the heavier recount path — is measured.
    $component->call('react', 'like');

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $component->call('react', 'helpful');

    expect($queries)->toBeLessThanOrEqual(15)
        ->and(Reaction::where('post_id', $post->id)->where('user_id', $reactor->id)->value('type'))->toBe('helpful')
        // The award did NOT run inline — it is parked on the queue for the cron drain.
        ->and(ReputationEvent::count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBeGreaterThan(0);
});
