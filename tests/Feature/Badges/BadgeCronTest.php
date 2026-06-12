<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Badge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Users;

/*
| nevo:badges:recompute (P2-M5): the catch-up sweep — awards anything a missed event dropped, idempotent
| under repeated runs (awards are UNIQUE-keyed and permanent, so the sweep only ever adds). The `nevo:`
| name is the Phase-5 rename surface #8 (ADR-0028); scheduler registration is pinned in SchedulerTest.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    Badge::query()->delete();
});

it('awards a badge a missed event dropped, and repeated sweeps add nothing new', function () {
    Badge::factory()->criteria(['type' => 'reputation', 'threshold' => 10])->create();
    // The user crossed the threshold while the event was lost (e.g. a dead queue row) — no award exists.
    $user = Users::inGroups(['members', 'tl1'], ['reputation_points' => 25]);

    expect(DB::table('user_badges')->count())->toBe(0);

    $this->artisan('nevo:badges:recompute', ['--chunk' => 2])->assertSuccessful();
    expect(DB::table('user_badges')->where('user_id', $user->id)->count())->toBe(1);

    $this->artisan('nevo:badges:recompute')->assertSuccessful(); // idempotent
    $this->artisan('nevo:badges:recompute')->assertSuccessful();
    expect(DB::table('user_badges')->count())->toBe(1);
});

it('re-evaluates a single user via --user', function () {
    Badge::factory()->criteria(['type' => 'reputation', 'threshold' => 10])->create();
    $a = Users::inGroups(['members', 'tl1'], ['reputation_points' => 25]);
    $b = Users::inGroups(['members', 'tl1'], ['reputation_points' => 25]);

    $this->artisan('nevo:badges:recompute', ['--user' => $a->id])->assertSuccessful();

    expect(DB::table('user_badges')->where('user_id', $a->id)->count())->toBe(1)
        ->and(DB::table('user_badges')->where('user_id', $b->id)->count())->toBe(0); // untouched
});
