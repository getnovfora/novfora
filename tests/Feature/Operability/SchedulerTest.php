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
    expect($commands)->toContain('hearth:backup');
    expect($commands)->toContain('hearth:trust:recompute');
    expect($commands)->toContain('hearth:antispam:purge');
});

it('registers a liveness heartbeat callback for the health endpoint', function () {
    $hasHeartbeat = collect(app(Schedule::class)->events())
        ->contains(fn ($event) => $event->description === 'hearth-queue-heartbeat');

    expect($hasHeartbeat)->toBeTrue();
});
