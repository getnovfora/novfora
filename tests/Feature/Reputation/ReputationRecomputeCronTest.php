<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Community\ReputationService;
use App\Forum\PostService;
use App\Models\Forum;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\Users;

/*
| novfora:reputation:recompute (P2-M5): the denorm self-heal cron. Idempotent under repeated runs (the M5
| queue-drain discipline), heals deliberate drift, and supports the single-user escape hatch. The `novfora:`
| name is the Phase-5 rename surface #8 (ADR-0028) — scheduler registration is pinned in SchedulerTest.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
});

/** A fresh LIVE ledger source — award() verifies the source row exists (the orphan-ledger guard). */
function cronRepSource(): Reaction
{
    $forum = Forum::firstOrCreate(['slug' => 'cron-src'], ['title' => 'Cron src', 'type' => 'forum']);
    $author = Users::inGroups(['members', 'tl1']);
    $topic = app(PostService::class)->createTopic($author, $forum, 'Src '.Str::random(8), 'markdown', ['source' => 'x']);

    return Reaction::create([
        'post_id' => $topic->posts()->first()->id,
        'user_id' => Users::inGroups(['members', 'tl1'])->id,
        'type' => 'like',
    ]);
}

it('heals drifted denorms across the board and is idempotent under repeated runs', function () {
    $a = Users::inGroups(['members', 'tl1']);
    $b = Users::inGroups(['members', 'tl1']);
    $service = app(ReputationService::class);

    $service->award($a, cronRepSource(), 3);
    $service->award($b, cronRepSource(), 1);

    // Drift both behind the ledger's back (a crashed increment / out-of-order revoke would look like this).
    User::whereKey($a->id)->update(['reputation_points' => 0]);
    User::whereKey($b->id)->update(['reputation_points' => -5]);

    $this->artisan('novfora:reputation:recompute', ['--chunk' => 2])->assertSuccessful();

    expect($a->fresh()->reputation_points)->toBe(3)
        ->and($b->fresh()->reputation_points)->toBe(1);

    $this->artisan('novfora:reputation:recompute')->assertSuccessful(); // run again: nothing to change

    expect($a->fresh()->reputation_points)->toBe(3)
        ->and($b->fresh()->reputation_points)->toBe(1);
});

it('recomputes a single user via --user', function () {
    $a = Users::inGroups(['members', 'tl1']);
    $b = Users::inGroups(['members', 'tl1']);
    app(ReputationService::class)->award($a, cronRepSource(), 2);

    User::whereKey($a->id)->update(['reputation_points' => 99]);
    User::whereKey($b->id)->update(['reputation_points' => 99]); // NOT recomputed — stays drifted

    $this->artisan('novfora:reputation:recompute', ['--user' => $a->id])->assertSuccessful();

    expect($a->fresh()->reputation_points)->toBe(2)
        ->and($b->fresh()->reputation_points)->toBe(99);
});
