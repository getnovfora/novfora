<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Group;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| A3 upgrade path: on a board that pre-dates the reputation threshold, the seeder is NOT re-run (RH-10:
| auto-upgrade runs migrations only), so the migration backfills `min_reputation` onto the existing tl2/tl3
| group rows. It must be idempotent and must never clobber an operator-tuned value.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('backfills min_reputation onto existing trust groups on upgrade, idempotently and without clobbering', function () {
    // Simulate a pre-A3 board: strip the seeded key so the rows look like the old schema.
    foreach (['tl2', 'tl3'] as $slug) {
        $group = Group::where('slug', $slug)->firstOrFail();
        $rules = $group->auto_promotion;
        unset($rules['min_reputation']);
        $group->forceFill(['auto_promotion' => $rules])->save();
    }
    expect(Group::where('slug', 'tl2')->first()->auto_promotion)->not->toHaveKey('min_reputation');

    $migration = require database_path('migrations/2026_06_13_000002_add_reputation_to_trust_auto_promotion.php');
    $migration->up();

    expect(Group::where('slug', 'tl2')->first()->auto_promotion['min_reputation'])->toBe(10)
        ->and(Group::where('slug', 'tl3')->first()->auto_promotion['min_reputation'])->toBe(50);

    // An operator tunes tl3's threshold; a re-run of the migration must leave it untouched (key already set).
    $tl3 = Group::where('slug', 'tl3')->first();
    $tl3->forceFill(['auto_promotion' => array_merge($tl3->auto_promotion, ['min_reputation' => 99])])->save();
    $migration->up();
    expect(Group::where('slug', 'tl3')->first()->auto_promotion['min_reputation'])->toBe(99);

    // down() cleanly removes the key.
    $migration->down();
    expect(Group::where('slug', 'tl2')->first()->auto_promotion)->not->toHaveKey('min_reputation')
        ->and(Group::where('slug', 'tl3')->first()->auto_promotion)->not->toHaveKey('min_reputation');
});
