<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;

/*
| The single cron line drives everything (ADR-0011 / M5 exit criterion 1). Assert the scheduler wires the
| bounded queue drain, automated backups, and the existing trust/anti-spam jobs — so one `schedule:run`
| cron entry runs the whole baseline tier.
*/

it('schedules the queue drain, backups, and the trust/anti-spam jobs', function () {
    $commands = collect(app(Schedule::class)->events())
        ->map(fn ($event) => (string) $event->command)
        ->filter()
        ->implode("\n");

    expect($commands)->toContain('queue:work');
    expect($commands)->toContain('--stop-when-empty');   // bounded drain, not a daemon
    expect($commands)->toContain('novfora:backup');
    expect($commands)->toContain('novfora:trust:recompute');
    expect($commands)->toContain('novfora:antispam:purge');
});

it('registers a liveness heartbeat callback for the health endpoint', function () {
    $hasHeartbeat = collect(app(Schedule::class)->events())
        ->contains(fn ($event) => $event->description === 'novfora-queue-heartbeat');

    expect($hasHeartbeat)->toBeTrue();
});

it('registers the no-SSH auto-upgrade tick (RH-10), overlap-guarded', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'novfora-auto-upgrade');

    expect($event)->not->toBeNull();
    // withoutOverlapping() so a long migration on a coarse, overlapping cron can never double-run.
    expect($event->withoutOverlapping)->toBeTrue();
    // …but with a SHORT, bounded expiry — NOT Laravel's 24h default — so a hard-killed run (which releases
    // no signal handler) can't strand the auto-upgrade (and the maintenance gate) for up to a day.
    expect($event->expiresAt)->toBeLessThan(60);
});

it('registers the no-SSH panel-restore drain (RH-11), overlap-guarded', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'novfora-panel-restore');

    expect($event)->not->toBeNull();
    // Same belt as the auto-upgrade: a short, bounded overlap window (the runner's own FILE lock is the real
    // double-run guard, since a cache lock would be wiped by the very restore it is meant to protect).
    expect($event->withoutOverlapping)->toBeTrue();
    expect($event->expiresAt)->toBeLessThan(60);
});
