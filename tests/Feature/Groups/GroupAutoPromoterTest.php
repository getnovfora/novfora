<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Groups\GroupAutoPromoter;
use App\Models\Group;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** A user with the four criteria pinned to known values. */
function apUser(array $metrics): User
{
    $user = User::factory()->create();
    $user->forceFill(array_merge([
        'post_count' => 0,
        'reputation_points' => 0,
        'trust_level' => 0,
        'created_at' => now(),
    ], $metrics))->save();

    return $user->fresh();
}

function apGroup(?array $autoPromotion): Group
{
    return Group::create([
        'slug' => 'g'.bin2hex(random_bytes(4)),
        'name' => 'Auto group',
        'type' => 'custom',
        'priority' => 50,
        'is_system' => false,
        'auto_promotion' => $autoPromotion,
    ]);
}

// ── normalize() — back-compat + sanitisation ───────────────────────────────────────────────────────────

it('normalizes the legacy flat shape into a single AND node', function () {
    $tree = app(GroupAutoPromoter::class)->normalize(['min_posts' => 5, 'min_trust_level' => 2]);

    expect($tree['op'])->toBe('AND');
    expect($tree['rules'])->toEqualCanonicalizing([
        ['criterion' => 'posts', 'gte' => 5],
        ['criterion' => 'trust', 'gte' => 2],
    ]);
});

it('treats empty / manual / malformed configs as no auto-promotion (null)', function () {
    $p = app(GroupAutoPromoter::class);

    expect($p->normalize(null))->toBeNull();
    expect($p->normalize([]))->toBeNull();
    expect($p->normalize(['manual' => true]))->toBeNull();
    expect($p->normalize(['op' => 'AND', 'rules' => [['criterion' => 'bogus', 'gte' => 1]]]))->toBeNull();
});

// ── satisfiesTree() — AND / OR / nesting ───────────────────────────────────────────────────────────────

it('evaluates an AND node (all criteria must hold)', function () {
    $p = app(GroupAutoPromoter::class);
    $tree = ['op' => 'AND', 'rules' => [['criterion' => 'posts', 'gte' => 5], ['criterion' => 'trust', 'gte' => 2]]];

    expect($p->satisfiesTree($tree, $p->metrics(apUser(['post_count' => 5, 'trust_level' => 2]))))->toBeTrue();
    expect($p->satisfiesTree($tree, $p->metrics(apUser(['post_count' => 5, 'trust_level' => 1]))))->toBeFalse();
});

it('evaluates an OR node (any criterion suffices)', function () {
    $p = app(GroupAutoPromoter::class);
    $tree = ['op' => 'OR', 'rules' => [['criterion' => 'posts', 'gte' => 100], ['criterion' => 'reputation', 'gte' => 50]]];

    expect($p->satisfiesTree($tree, $p->metrics(apUser(['post_count' => 5, 'reputation_points' => 50]))))->toBeTrue();
    expect($p->satisfiesTree($tree, $p->metrics(apUser(['post_count' => 5, 'reputation_points' => 10]))))->toBeFalse();
});

it('fail-closes on an empty rule set', function () {
    $p = app(GroupAutoPromoter::class);
    expect($p->satisfiesTree(['op' => 'AND', 'rules' => []], $p->metrics(apUser([]))))->toBeFalse();
});

// ── promote() — the headline behaviour ─────────────────────────────────────────────────────────────────

it('promotes a user who satisfies an OR branch but not a user who satisfies neither', function () {
    $group = apGroup(['op' => 'OR', 'rules' => [['criterion' => 'posts', 'gte' => 100], ['criterion' => 'reputation', 'gte' => 50]]]);
    $p = app(GroupAutoPromoter::class);

    $qualifies = apUser(['post_count' => 3, 'reputation_points' => 60]); // OR via reputation
    $doesnt = apUser(['post_count' => 3, 'reputation_points' => 10]);

    expect($p->promote($qualifies))->toBe(1);
    expect($p->promote($doesnt))->toBe(0);

    expect($group->users()->whereKey($qualifies->id)->exists())->toBeTrue();
    expect($group->users()->whereKey($doesnt->id)->exists())->toBeFalse();
});

it('still promotes from a legacy flat config', function () {
    $group = apGroup(['min_posts' => 2]); // legacy flat shape
    $user = apUser(['post_count' => 2]);

    expect(app(GroupAutoPromoter::class)->promote($user))->toBe(1);
    expect($group->users()->whereKey($user->id)->exists())->toBeTrue();
});

it('is promotion-only: a user who later drops below the criteria is NOT removed', function () {
    $group = apGroup(['op' => 'AND', 'rules' => [['criterion' => 'posts', 'gte' => 5]]]);
    $p = app(GroupAutoPromoter::class);

    $user = apUser(['post_count' => 5]);
    $p->promote($user);
    expect($group->users()->whereKey($user->id)->exists())->toBeTrue();

    // The user's standing falls below the bar; re-running must never demote/detach.
    $user->forceFill(['post_count' => 1])->save();
    $p->promote($user->fresh());
    expect($group->fresh()->users()->whereKey($user->id)->exists())->toBeTrue();
});

it('is idempotent: re-running adds nothing and reports zero new promotions', function () {
    $group = apGroup(['op' => 'AND', 'rules' => [['criterion' => 'posts', 'gte' => 5]]]);
    $p = app(GroupAutoPromoter::class);
    $user = apUser(['post_count' => 5]);

    expect($p->promote($user))->toBe(1);
    expect($p->promote($user->fresh()))->toBe(0);
    expect($group->users()->whereKey($user->id)->count())->toBe(1);
});

it('never auto-promotes into a system or trust group (those are engine-managed)', function () {
    $this->seed(DatabaseSeeder::class); // seeds tl1..tl4 with their auto_promotion configs

    // A user who would meet tl1's flat thresholds must NOT be auto-added to tl1 by THIS engine.
    $user = apUser(['post_count' => 999, 'reputation_points' => 999, 'trust_level' => 0, 'created_at' => now()->subYears(1)]);
    app(GroupAutoPromoter::class)->promote($user);

    expect($user->fresh()->groups()->where('type', 'trust')->exists())->toBeFalse();
});
