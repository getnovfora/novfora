<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Deliverability\Digest\DigestAssembler;
use App\Deliverability\SuppressionGate;
use App\Mail\DigestMail;
use App\Models\DigestQueueItem;
use App\Models\DigestRun;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Deliverability;

/*
| GO CRITERION 1 (the GO-BLOCKER) — digest idempotency across cron ticks: N notifications → exactly ONE
| digest per user per cadence; a tick that overlaps another or is killed mid-run never double-sends and
| never drops. The guarantee is the committed UNIQUE(user,cadence,period_key) row inside the assembler
| transaction — NOT the schedule lock. (QUEUE_CONNECTION=sync → SendDigestJob runs inline → Mail::fake
| records the digest as SENT, since the queued unit is the job and DigestMail is a plain mailable.)
*/

uses(RefreshDatabase::class);

beforeEach(fn () => Mail::fake());

it('assembles exactly one digest per user per cadence across repeated / overlapping ticks', function () {
    $user = Deliverability::user('daily');
    Deliverability::stage($user, 4);

    app(DigestAssembler::class)->tick();
    app(DigestAssembler::class)->tick(); // the overlapping / repeat tick within the same period

    expect(DigestRun::where('user_id', $user->getKey())->count())->toBe(1)
        ->and(DigestRun::first()->status)->toBe('sent')
        ->and(DigestRun::first()->item_count)->toBe(4)
        ->and(DigestQueueItem::whereNull('digest_run_id')->count())->toBe(0); // every item claimed, none dropped
    Mail::assertSent(DigestMail::class, 1);
});

it('rests the guarantee on a UNIQUE(user,cadence,period) index — a duplicate run is rejected by the DB', function () {
    $user = Deliverability::user();
    $row = ['user_id' => $user->getKey(), 'cadence' => 'daily', 'period_key' => '2026-06-08', 'status' => 'claimed', 'claimed_at' => now()];

    DigestRun::create($row);

    expect(fn () => DigestRun::create($row))->toThrow(QueryException::class);
});

it('a tick killed mid-transaction (after claim, before commit) drops nothing and never doubles', function () {
    $user = Deliverability::user('daily');
    Deliverability::stage($user, 3);

    // A subclass that throws INSIDE the assembler transaction, after the run insert + the item claim but
    // before COMMIT — modelling a SIGKILL mid-run. InnoDB/SQLite must roll the run row AND the item-claim
    // back together.
    $faulty = new class(app(SuppressionGate::class)) extends DigestAssembler
    {
        protected function afterClaim(DigestRun $run): void
        {
            throw new RuntimeException('killed mid-run');
        }
    };

    expect(fn () => $faulty->tick())->toThrow(RuntimeException::class);

    expect(DigestRun::count())->toBe(0)                                   // no orphan run
        ->and(DigestQueueItem::whereNull('digest_run_id')->count())->toBe(3); // items un-claimed (no drop)
    Mail::assertNothingSent();

    // A clean re-run then assembles exactly one digest — proving no-drop AND no-double after the kill.
    app(DigestAssembler::class)->tick();
    expect(DigestRun::where('user_id', $user->getKey())->count())->toBe(1);
    Mail::assertSent(DigestMail::class, 1);
});

it('self-heals a run committed as built but never enqueued, without double-sending', function () {
    $user = Deliverability::user('daily');
    Deliverability::stage($user, 1);

    // Model a crash between COMMIT (built) and dispatch: a built run, items claimed, mailed_at still NULL.
    $run = DigestRun::create([
        'user_id' => $user->getKey(), 'cadence' => 'daily', 'period_key' => \App\Deliverability\Digest\PeriodKey::for('daily'),
        'status' => 'built', 'built_at' => now(), 'item_count' => 1, 'mailed_at' => null,
    ]);
    DigestQueueItem::where('user_id', $user->getKey())->update(['digest_run_id' => $run->getKey()]);

    app(DigestAssembler::class)->tick(); // phase-0 self-heal re-dispatches it

    expect($run->fresh()->status)->toBe('sent');
    Mail::assertSent(DigestMail::class, 1);

    app(DigestAssembler::class)->tick(); // and never again
    Mail::assertSent(DigestMail::class, 1);
});

it('rolls forward to a new digest per cadence period, one per period', function () {
    $user = Deliverability::user('daily');

    Carbon::setTestNow('2026-06-08 09:00:00');
    Deliverability::stage($user, 2);
    app(DigestAssembler::class)->tick();
    app(DigestAssembler::class)->tick(); // same day → still one
    expect(DigestRun::where('user_id', $user->getKey())->count())->toBe(1);

    Carbon::setTestNow('2026-06-09 09:00:00');
    Deliverability::stage($user, 2);
    app(DigestAssembler::class)->tick();
    expect(DigestRun::where('user_id', $user->getKey())->count())->toBe(2); // a new period → a second digest
    Mail::assertSent(DigestMail::class, 2);

    Carbon::setTestNow();
});
