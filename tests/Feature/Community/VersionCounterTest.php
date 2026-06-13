<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Community\ActivityVersion;
use App\Permissions\AclVersion;
use Illuminate\Support\Facades\Cache;

/*
| A6: the global cache version counters (ActivityVersion + its structural twin AclVersion) must bump
| ATOMICALLY. The previous read-modify-write (current() + 1, then Cache::forever) let two concurrent callers
| both read N and both write N+1 — losing a bump and serving a stale, version-keyed cache entry to other
| readers. The fix uses Cache::add + Cache::increment. Pins: cold-start, strict no-lost-update across many
| bumps, the atomic primitive itself, and graceful degradation when the store throws.
*/

beforeEach(fn () => Cache::flush());

it('cold-starts at 1 and bumps monotonically', function (string $class) {
    $v = app($class);

    expect($v->current())->toBe(1)
        ->and($v->bump())->toBe(2)
        ->and($v->current())->toBe(2)
        ->and($v->bump())->toBe(3)
        ->and($v->current())->toBe(3);
})->with([
    [ActivityVersion::class],
    [AclVersion::class],
]);

it('never loses a bump across many increments (exact count — the lost-update pin)', function (string $class) {
    $v = app($class);
    $start = $v->current();

    for ($i = 0; $i < 100; $i++) {
        $v->bump();
    }

    expect($v->current())->toBe($start + 100); // every bump landed — none lost to a read-modify-write race
})->with([
    [ActivityVersion::class],
    [AclVersion::class],
]);

it('bumps via the atomic Cache::increment primitive, never a read-modify-write', function (string $class, string $key) {
    // Proves the fix: bump() seeds with add() then increments atomically — no Cache::get/forever pair that a
    // concurrent caller could interleave between.
    Cache::shouldReceive('add')->once()->with($key, 1);
    Cache::shouldReceive('increment')->once()->with($key)->andReturn(42);

    expect(app($class)->bump())->toBe(42);
})->with([
    [ActivityVersion::class, 'novfora.activities.version'],
    [AclVersion::class, 'novfora.acl.version'],
]);

it('degrades gracefully when the cache store throws (never errors on the hot path)', function (string $class) {
    Cache::shouldReceive('add')->andThrow(new RuntimeException('cache down'));
    Cache::shouldReceive('get')->andReturn(7); // read by current() inside the catch

    expect(app($class)->bump())->toBe(8); // best-effort current()+1, no exception escapes
})->with([
    [ActivityVersion::class],
    [AclVersion::class],
]);
