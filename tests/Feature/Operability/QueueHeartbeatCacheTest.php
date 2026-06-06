<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/*
| RH-9 sweep — the /health queue heartbeat must round-trip through a SERIALIZING store.
|
| The repo-wide cache-write sweep (RH-9 FIX 2) found the heartbeat already stores an epoch INT
| (routes/console.php: `Cache::put(QUEUE_HEARTBEAT, now()->timestamp, …)`), so it is serialization-safe by
| construction — an int survives unserialize(allowed_classes:false) untouched, unlike a Carbon object. (The
| live /health showing queue.ok:null was the cron not yet running on that host — no heartbeat written — NOT
| a deserialization failure.) Nothing proved it, though, because the suite runs the non-serializing array
| store. These pin it through the database store so a future regression that stored a Carbon/object here —
| which would come back as `__PHP_Incomplete_Class`, fail `is_numeric()`, and drop queue.ok to null — fails.
*/

beforeEach(function () {
    config(['cache.default' => 'database']);
});

it('reports a fresh queue heartbeat (queue.ok true, not null) through a serializing store', function () {
    // Prime the heartbeat exactly as the cron line does — an epoch int with a day TTL.
    Cache::put(HealthController::QUEUE_HEARTBEAT, now()->timestamp, now()->addDay());

    // It comes back an int, not a __PHP_Incomplete_Class.
    expect(Cache::get(HealthController::QUEUE_HEARTBEAT))->toBeInt();

    $response = $this->getJson('/health')->assertOk();

    // `ok` is a real boolean (true — fresh), never null-because-deserialization-dropped-the-value.
    expect($response->json('checks.queue.ok'))->toBeTrue()
        ->and($response->json('checks.queue.last_drained_age_seconds'))->toBeLessThan(60);
});

it('still reports a STALE heartbeat as queue.ok=false through a serializing store', function () {
    // A 2h-old epoch int must deserialize cleanly and read as stale — not silently become unknown/null.
    Cache::put(HealthController::QUEUE_HEARTBEAT, now()->subHours(2)->timestamp, now()->addDay());

    $response = $this->getJson('/health')->assertOk();

    expect($response->json('checks.queue.ok'))->toBeFalse()
        ->and($response->json('checks.queue.last_drained_age_seconds'))->toBeGreaterThan(1800);
});

it('round-trips an epoch-int heartbeat as an int, never an object (RH-9 sweep)', function () {
    Cache::put(HealthController::QUEUE_HEARTBEAT, 1_700_000_000, now()->addDay());
    expect(Cache::get(HealthController::QUEUE_HEARTBEAT))->toBe(1_700_000_000);
});
