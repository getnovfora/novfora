<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Community\ReputationService;
use App\Models\Reaction;
use App\Models\ReputationEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

/*
| ReputationService (P2-M5 ⚙): the idempotent ledger. award() is an insertOrIgnore on UNIQUE(source) and
| adjusts the denorm ONLY on a real insert (atomic increment); revoke() undoes the STORED points only when
| this caller's delete removed the row; recomputeFor() overwrites the denorm with the authoritative SUM.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
});

/**
 * A fresh, unique polymorphic source for the ledger. The service reads only getMorphClass() + getKey()
 * (reputation_events carries no source FK), so an in-memory model with a unique id is the exact contract —
 * no post/topic scaffolding needed at the service layer (the wiring tests use the real reaction path).
 */
function repSource(): Reaction
{
    static $id = 1000000;

    $reaction = new Reaction;
    $reaction->id = ++$id;
    $reaction->exists = true;

    return $reaction;
}

it('awards once: a second award for the same source is a no-op for ledger and denorm', function () {
    $recipient = Users::inGroups(['members', 'tl1']);
    $source = repSource();
    $service = app(ReputationService::class);

    expect($service->award($recipient, $source, 2))->toBeTrue()
        ->and($service->award($recipient, $source, 2))->toBeFalse()   // idempotent: UNIQUE(source) holds
        ->and($service->award($recipient, $source, 5))->toBeFalse(); // even a different weight cannot double-award

    expect(ReputationEvent::count())->toBe(1)
        ->and($recipient->fresh()->reputation_points)->toBe(2); // counted exactly once
});

it('revokes the stored points exactly once, immune to later config-weight changes', function () {
    $recipient = Users::inGroups(['members', 'tl1']);
    $source = repSource();
    $service = app(ReputationService::class);

    $service->award($recipient, $source, 2); // banked at weight 2

    expect($service->revoke($source))->toBeTrue()
        ->and($service->revoke($source))->toBeFalse(); // second revoke: row already gone, no double-decrement

    expect(ReputationEvent::count())->toBe(0)
        ->and($recipient->fresh()->reputation_points)->toBe(0);
});

it('writes no ledger row for a zero-point award and sums negative weights correctly', function () {
    $recipient = Users::inGroups(['members', 'tl1']);
    $service = app(ReputationService::class);

    expect($service->award($recipient, repSource(), 0))->toBeFalse()
        ->and(ReputationEvent::count())->toBe(0);

    $service->award($recipient, repSource(), -1); // e.g. a 'disagree'-class weight
    $service->award($recipient, repSource(), 2);

    expect($recipient->fresh()->reputation_points)->toBe(1); // 2 - 1 — needs the signed column migration
});

it('syncSourceAward re-points the UNIQUE slot on a weight change and is a no-op when aligned', function () {
    $recipient = Users::inGroups(['members', 'tl1']);
    $source = repSource();
    $service = app(ReputationService::class);

    $service->syncSourceAward($recipient, $source, 1);
    $service->syncSourceAward($recipient, $source, 1); // aligned → the double-fire path, pure no-op
    expect($recipient->fresh()->reputation_points)->toBe(1)
        ->and(ReputationEvent::count())->toBe(1);

    $service->syncSourceAward($recipient, $source, 2); // type change: revoke stored 1, award 2
    expect($recipient->fresh()->reputation_points)->toBe(2)
        ->and(ReputationEvent::count())->toBe(1);

    $service->syncSourceAward($recipient, $source, 0); // changed to a zero-weight type: cleared
    expect($recipient->fresh()->reputation_points)->toBe(0)
        ->and(ReputationEvent::count())->toBe(0);
});

it('recomputeFor self-heals a deliberately drifted denorm from the ledger', function () {
    $a = Users::inGroups(['members', 'tl1']);
    $b = Users::inGroups(['members', 'tl1']);
    $service = app(ReputationService::class);

    $service->award($a, repSource(), 3);
    $service->award($a, repSource(), -1);

    // Drift both denorms behind the ledger's back.
    User::whereKey($a->id)->update(['reputation_points' => 999]);
    User::whereKey($b->id)->update(['reputation_points' => 42]); // b has NO ledger rows → must reset to 0

    $service->recomputeFor([(int) $a->id, (int) $b->id]);

    expect($a->fresh()->reputation_points)->toBe(2)
        ->and($b->fresh()->reputation_points)->toBe(0);
});

it('shows reputation on the public profile', function () {
    $user = Users::inGroups(['members', 'tl1']);
    app(ReputationService::class)->award($user, repSource(), 7);

    $this->get(route('profiles.show', $user))
        ->assertOk()
        ->assertSeeHtml('dusk="reputation-points"')
        ->assertSee('7');
});
