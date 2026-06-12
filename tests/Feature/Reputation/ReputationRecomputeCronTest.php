<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Community\ReputationService;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

/*
| nevo:reputation:recompute (P2-M5): the denorm self-heal cron. Idempotent under repeated runs (the M5
| queue-drain discipline), heals deliberate drift, and supports the single-user escape hatch. The `nevo:`
| name is the Phase-5 rename surface #8 (ADR-0028) — scheduler registration is pinned in SchedulerTest.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
});

/** An in-memory unique ledger source (no source FK — morph class + id is the whole contract). */
function cronRepSource(): Reaction
{
    static $id = 2000000;

    $reaction = new Reaction;
    $reaction->id = ++$id;
    $reaction->exists = true;

    return $reaction;
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

    $this->artisan('nevo:reputation:recompute', ['--chunk' => 2])->assertSuccessful();

    expect($a->fresh()->reputation_points)->toBe(3)
        ->and($b->fresh()->reputation_points)->toBe(1);

    $this->artisan('nevo:reputation:recompute')->assertSuccessful(); // run again: nothing to change

    expect($a->fresh()->reputation_points)->toBe(3)
        ->and($b->fresh()->reputation_points)->toBe(1);
});

it('recomputes a single user via --user', function () {
    $a = Users::inGroups(['members', 'tl1']);
    $b = Users::inGroups(['members', 'tl1']);
    app(ReputationService::class)->award($a, cronRepSource(), 2);

    User::whereKey($a->id)->update(['reputation_points' => 99]);
    User::whereKey($b->id)->update(['reputation_points' => 99]); // NOT recomputed — stays drifted

    $this->artisan('nevo:reputation:recompute', ['--user' => $a->id])->assertSuccessful();

    expect($a->fresh()->reputation_points)->toBe(2)
        ->and($b->fresh()->reputation_points)->toBe(99);
});
