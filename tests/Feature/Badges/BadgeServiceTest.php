<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Community\BadgeService;
use App\Forum\PostService;
use App\Models\Badge;
use App\Models\Forum;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Users;

/*
| BadgeService (P2-M5 ⚙): the closed-set criteria engine. Awards are idempotent (insertOrIgnore on
| UNIQUE(user_id, badge_id)) and PERMANENT — a lapsed criterion never revokes. An unknown criteria type
| matches nothing (closed set — no expression evaluation, ever).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    Badge::query()->delete(); // isolate from the seeded starter set — each test builds its own catalog
    $this->service = app(BadgeService::class);
});

function badgeCountFor(User $user): int
{
    return (int) DB::table('user_badges')->where('user_id', $user->getKey())->count();
}

it('awards idempotently — re-evaluation and replays never duplicate', function () {
    $badge = Badge::factory()->criteria(['type' => 'join'])->create();
    $user = Users::inGroups(['members', 'tl1']);

    expect($this->service->evaluate($user))->toBe(1)
        ->and($this->service->evaluate($user))->toBe(0)        // already held → nothing new
        ->and($this->service->award($user, $badge))->toBeFalse() // direct replay → UNIQUE no-op
        ->and(badgeCountFor($user))->toBe(1);
});

it('awards reputation-threshold badges at or above the threshold only', function () {
    Badge::factory()->criteria(['type' => 'reputation', 'threshold' => 10])->create();
    $below = Users::inGroups(['members', 'tl1'], ['reputation_points' => 9]);
    $at = Users::inGroups(['members', 'tl1'], ['reputation_points' => 10]);

    expect($this->service->evaluate($below))->toBe(0)
        ->and($this->service->evaluate($at))->toBe(1);
});

it('counts live posts for post_count criteria (the users.post_count column is an unmaintained seam)', function () {
    Badge::factory()->criteria(['type' => 'post_count', 'threshold' => 1])->create();
    $author = Users::inGroups(['members', 'tl1']);

    expect($this->service->evaluate($author))->toBe(0)  // no posts yet
        ->and(badgeCountFor($author))->toBe(0);

    $forum = Forum::create(['slug' => 'badge-svc', 'title' => 'Badges', 'type' => 'forum']);
    app(PostService::class)->createTopic($author, $forum, 'First!', 'markdown', ['source' => 'hello']);

    // The TopicCreated wiring already awarded it inline (sync queue) — and a re-evaluation adds nothing.
    expect(badgeCountFor($author))->toBe(1)
        ->and($this->service->evaluate($author))->toBe(0);
});

it('keeps a badge when the criterion later lapses (awards are permanent)', function () {
    Badge::factory()->criteria(['type' => 'reputation', 'threshold' => 10])->create();
    $user = Users::inGroups(['members', 'tl1'], ['reputation_points' => 15]);

    $this->service->evaluate($user);
    expect(badgeCountFor($user))->toBe(1);

    User::whereKey($user->id)->update(['reputation_points' => 0]); // reputation collapses
    $this->service->evaluate($user->fresh());

    expect(badgeCountFor($user))->toBe(1); // never revoked
});

it('never awards an inactive badge or an unknown criteria type (closed set)', function () {
    Badge::factory()->inactive()->criteria(['type' => 'join'])->create();
    $rogue = Badge::factory()->criteria(['type' => 'join'])->create();
    // Simulate a future/corrupt criteria document written around the validator.
    Badge::whereKey($rogue->id)->update(['criteria' => json_encode(['type' => 'arbitrary_eval', 'expr' => '1==1'])]);

    $user = Users::inGroups(['members', 'tl1']);

    expect($this->service->evaluate($user))->toBe(0)
        ->and(badgeCountFor($user))->toBe(0);
});

it('scopes evaluation to the trigger type', function () {
    Badge::factory()->criteria(['type' => 'join'])->create();
    Badge::factory()->criteria(['type' => 'reputation', 'threshold' => 1])->create();
    $user = Users::inGroups(['members', 'tl1'], ['reputation_points' => 5]);

    expect($this->service->evaluate($user, BadgeService::TRIGGER_JOIN))->toBe(1) // only the join badge
        ->and(badgeCountFor($user))->toBe(1);

    expect($this->service->evaluate($user, BadgeService::TRIGGER_REPUTATION))->toBe(1)
        ->and(badgeCountFor($user))->toBe(2);
});

it('validates criteria against the closed set, normalising the stored document', function () {
    expect(BadgeService::validateCriteria(['type' => 'join']))->toBe(['type' => 'join'])
        ->and(BadgeService::validateCriteria(['type' => 'join', 'threshold' => 99]))->toBe(['type' => 'join']) // join takes no threshold
        ->and(BadgeService::validateCriteria(['type' => 'post_count', 'threshold' => 5]))->toBe(['type' => 'post_count', 'threshold' => 5])
        ->and(BadgeService::validateCriteria(['type' => 'reputation', 'threshold' => '10']))->toBe(['type' => 'reputation', 'threshold' => 10])
        ->and(BadgeService::validateCriteria(['type' => 'post_count']))->toBeNull()              // missing threshold
        ->and(BadgeService::validateCriteria(['type' => 'post_count', 'threshold' => 0]))->toBeNull() // sub-floor
        ->and(BadgeService::validateCriteria(['type' => 'eval', 'threshold' => 1]))->toBeNull()  // outside the set
        ->and(BadgeService::validateCriteria([]))->toBeNull();
});
