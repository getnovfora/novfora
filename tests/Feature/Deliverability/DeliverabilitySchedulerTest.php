<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Mail\DigestMail;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Deliverability;

/*
| The deliverability ticks join the single cron line (ADR-0011) with the M5 drain discipline. They are
| ALWAYS wired (so the cron contract is real) but the COMMANDS no-op while the pipeline is dormant — that is
| what keeps a deploy behaviour-neutral until P2-M2 flips the flag.
*/

uses(RefreshDatabase::class);

it('wires the digest + bounce-poll ticks into the schedule', function () {
    $commands = collect(app(Schedule::class)->events())
        ->map(fn ($event) => (string) $event->command)
        ->filter()
        ->implode("\n");

    expect($commands)->toContain('hearth:deliverability:digest-run')
        ->and($commands)->toContain('hearth:deliverability:poll-bounces');
});

it('guards the digest tick with a SHORT overlap mutex (not Laravel\'s 24h default)', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'hearth-digest-run');

    expect($event)->not->toBeNull();
    expect($event->withoutOverlapping)->toBeTrue();
    // The DB UNIQUE row is the real double-run guard; a hard-killed tick must not strand the digest, so the
    // mutex expiry is short and bounded (< 60), matching the auto-upgrade/restore discipline.
    expect($event->expiresAt)->toBeLessThan(60);
});

it('overlap-guards the bounce poll', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'hearth-poll-bounces');

    expect($event)->not->toBeNull();
    expect($event->withoutOverlapping)->toBeTrue();
});

it('the digest command is a behaviour-neutral no-op while dormant', function () {
    Mail::fake();
    config(['hearth.deliverability.enabled' => false]);
    $user = Deliverability::user('daily');
    Deliverability::stage($user, 3);

    $this->artisan('hearth:deliverability:digest-run')->assertSuccessful();

    Mail::assertNotSent(DigestMail::class); // dormant → the command never assembled anything
});

it('the bounce-poll command is a no-op while dormant', function () {
    config(['hearth.deliverability.enabled' => false]);

    $this->artisan('hearth:deliverability:poll-bounces')->assertSuccessful();
});
