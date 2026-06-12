<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Account\AccountDeletionService;
use App\Forum\PostService;
use App\Forum\ReactionService;
use App\Models\Forum;
use App\Models\Post;
use App\Models\ReputationEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\Users;

/*
| The EXTENDED ADR-0025 cascade (P2-M5 ⚙ — the headline test). Deleting a user already hard-deletes their
| reactions; those reactions awarded reputation to OTHER authors. The cascade must revoke that rep and
| recompute each affected author's reputation_points AUTHORITATIVELY — dropping them by exactly the
| revoked weight, while rep from surviving reactors is untouched — and purge the deleted user's own
| received rep. Zero orphan ledger rows may survive.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    config(['novfora.reactions.types' => [
        'like' => ['label' => 'Like', 'emoji' => '👍', 'score' => 1],
        'helpful' => ['label' => 'Helpful', 'emoji' => '💡', 'score' => 2],
    ]]);
});

/** A real post through the real write path (no Post factory exists — the PostService pattern). */
function cascadeWiredPost(User $author): Post
{
    $forum = Forum::firstOrCreate(['slug' => 'rep-cascade'], ['title' => 'Rep cascade', 'type' => 'forum']);
    $topic = app(PostService::class)->createTopic($author, $forum, 'Topic '.Str::random(8), 'markdown', ['source' => 'Opening.']);

    return $topic->posts()->first();
}

it('deleting a heavy reactor zeroes their own rep AND drops each affected author by exactly the revoked weight', function () {
    $reactor = Users::inGroups(['members', 'tl1']);   // U — the account being deleted
    $bystander = Users::inGroups(['members', 'tl1']); // V — their awards must SURVIVE
    $authorA = Users::inGroups(['members', 'tl1']);
    $authorB = Users::inGroups(['members', 'tl1']);

    $postA1 = cascadeWiredPost($authorA);
    $postA2 = cascadeWiredPost($authorA);
    $postB = cascadeWiredPost($authorB);
    $postU = cascadeWiredPost($reactor);

    $reactions = app(ReactionService::class);
    $reactions->toggle($reactor, $postA1, 'like');      // U → A : +1
    $reactions->toggle($reactor, $postA2, 'helpful');   // U → A : +2
    $reactions->toggle($reactor, $postB, 'helpful');    // U → B : +2
    $reactions->toggle($bystander, $postA1, 'like');    // V → A : +1 (must survive)
    $reactions->toggle($bystander, $postU, 'helpful');  // V → U : +2 (U's received rep, dies with U)

    expect($authorA->fresh()->reputation_points)->toBe(4)
        ->and($authorB->fresh()->reputation_points)->toBe(2)
        ->and($reactor->fresh()->reputation_points)->toBe(2)
        ->and(ReputationEvent::count())->toBe(5);

    $reactorId = (int) $reactor->id;
    $this->actingAs($reactor);
    app(AccountDeletionService::class)->deleteOwnAccount($reactor);

    expect(User::find($reactorId))->toBeNull()
        // A dropped by EXACTLY the revoked 3 (1+2) — the bystander's +1 survives.
        ->and($authorA->fresh()->reputation_points)->toBe(1)
        // B dropped by exactly the revoked 2 — back to zero.
        ->and($authorB->fresh()->reputation_points)->toBe(0)
        // The ledger holds ONLY the bystander's surviving award to A: no rows for the deleted recipient,
        // no rows sourced from the deleted user's reactions.
        ->and(ReputationEvent::count())->toBe(1)
        ->and(ReputationEvent::where('user_id', $reactorId)->count())->toBe(0)
        ->and((int) ReputationEvent::sole()->user_id)->toBe((int) $authorA->id);
});

it('survives a reactor with no reputation footprint (empty capture sets)', function () {
    $reactor = Users::inGroups(['members', 'tl1']); // never reacted, never received rep
    $reactorId = (int) $reactor->id;

    $this->actingAs($reactor);
    app(AccountDeletionService::class)->deleteOwnAccount($reactor);

    expect(User::find($reactorId))->toBeNull()
        ->and(ReputationEvent::count())->toBe(0);
});
