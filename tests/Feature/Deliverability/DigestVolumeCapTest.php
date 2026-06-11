<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Deliverability\Digest\DigestAssembler;
use App\Mail\DigestMail;
use App\Models\DigestQueueItem;
use App\Models\DigestRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Deliverability;

/*
| GO CRITERION 3 — volume hygiene. A large backlog respects the per-tick send cap + the per-user item rate
| and drains over later ticks, NEVER one oversized burst — without ever double-sending a user (capping only
| delays a user to the next tick; the period_key keeps them exactly-once).
*/

uses(RefreshDatabase::class);

beforeEach(fn () => Mail::fake());

it('respects the per-tick user cap and drains the backlog over later ticks, once per user', function () {
    config(['novfora.deliverability.digest.max_users_per_tick' => 3]);

    foreach (range(1, 5) as $i) {
        Deliverability::stage(Deliverability::user('daily'), 1);
    }

    app(DigestAssembler::class)->tick();
    Mail::assertSent(DigestMail::class, 3);   // capped this tick
    expect(DigestRun::count())->toBe(3);

    app(DigestAssembler::class)->tick();
    Mail::assertSent(DigestMail::class, 5);   // remaining 2 drained → 5 total
    expect(DigestRun::count())->toBe(5)
        ->and(DigestRun::query()->distinct()->count('user_id'))->toBe(5); // never one user twice
});

it('caps a single digest to the per-user item rate, rolling the overflow into the next period', function () {
    config(['novfora.deliverability.digest.per_user_item_rate' => 2]);
    $user = Deliverability::user('daily');
    Deliverability::stage($user, 5);

    Carbon::setTestNow('2026-06-08 08:00:00');
    app(DigestAssembler::class)->tick();
    expect(DigestRun::where('user_id', $user->getKey())->first()->item_count)->toBe(2)
        ->and(DigestQueueItem::whereNull('digest_run_id')->count())->toBe(3); // overflow not lost

    Carbon::setTestNow('2026-06-09 08:00:00');
    app(DigestAssembler::class)->tick();
    expect(DigestQueueItem::whereNull('digest_run_id')->count())->toBe(1); // 2 more drained
    Mail::assertSent(DigestMail::class, 2);

    Carbon::setTestNow();
});

it('does not let a gated user starve the per-tick cap or re-scan forever', function () {
    config(['novfora.deliverability.digest.max_users_per_tick' => 5]);

    // A gated (unsubscribed) user created FIRST (lowest id) with stale daily items from before opting out.
    $gated = Deliverability::user('off');
    Deliverability::stage($gated, 2);

    // Active daily users after it.
    $active = collect(range(1, 3))->map(function () {
        $u = Deliverability::user('daily');
        Deliverability::stage($u, 1);

        return $u;
    });

    app(DigestAssembler::class)->tick();

    Mail::assertSent(DigestMail::class, 3); // the active users are NOT starved by the gated one
    foreach ($active as $u) {
        expect(DigestRun::where('user_id', $u->getKey())->where('status', 'sent')->where('item_count', '>', 0)->exists())->toBeTrue();
    }
    // The gated user was retired (items claimed into a terminal run) and won't be re-scanned next tick.
    expect(DigestQueueItem::where('user_id', $gated->getKey())->whereNull('digest_run_id')->count())->toBe(0);

    app(DigestAssembler::class)->tick();
    Mail::assertSent(DigestMail::class, 3); // no new work — gated user is not re-processed
});
