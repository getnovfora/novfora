<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Upgrade\SchemaState;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
| Schema-state detection (RH-10 / ADR-0021): the cheap request-path signal that decides whether the
| deployed code is ahead of the database, and the non-secret /health block. No real migrations run here —
| pending is forced via an out-of-tree fixture path and via the release-fingerprint mismatch.
*/

beforeEach(fn () => app(SchemaState::class)->forget());

it('reports an up-to-date schema after a normal migrate (nothing pending, gate open)', function () {
    $schema = app(SchemaState::class);

    expect($schema->hasPendingMigrations())->toBeFalse();

    $schema->refresh();

    expect($schema->isPending())->toBeFalse();
    expect($schema->shouldGateRequests())->toBeFalse();
});

it('detects pending migrations when deployed code carries an unapplied migration', function () {
    config(['hearth.upgrade.migration_paths' => [
        database_path('migrations'),
        base_path('tests/Fixtures/upgrade/ok'),
    ]]);
    $schema = app(SchemaState::class);

    expect($schema->hasPendingMigrations())->toBeTrue(); // the fixture is unapplied (detection only — not run)

    $schema->refresh();

    expect($schema->isPending())->toBeTrue();
    expect($schema->shouldGateRequests())->toBeTrue();   // auto mode (default) → gated
});

it('gates on a new-release fingerprint change before the scheduler re-checks (closes the deploy window)', function () {
    $schema = app(SchemaState::class);

    // The last scheduler tick recorded "nothing pending" against the PREVIOUS release's fingerprint…
    $schema->put(['pending' => false, 'fingerprint' => 'previous-release-hash']);

    // …but the deployed code's migration set is different now ⇒ treat as pending ⇒ gate (auto mode).
    expect($schema->isPending())->toBeTrue();
    expect($schema->shouldGateRequests())->toBeTrue();
});

it('does not gate a merely-pending state in manual mode (the documented asymmetry)', function () {
    config(['hearth.upgrade.auto' => false]);
    $schema = app(SchemaState::class);
    $schema->put(['pending' => true, 'fingerprint' => $schema->codeFingerprint()]);

    expect($schema->isPending())->toBeTrue();
    expect($schema->shouldGateRequests())->toBeFalse(); // operator manages it; the panel stays reachable
});

it('never gates a fresh, never-checked install', function () {
    $schema = app(SchemaState::class);
    $schema->forget(); // no state recorded yet

    expect($schema->shouldGateRequests())->toBeFalse();
});

it('exposes a non-secret schema block for /health', function () {
    $schema = app(SchemaState::class);
    $schema->refresh();

    $block = $schema->healthBlock();

    expect($block)->toHaveKeys(['pending', 'upgrading', 'stuck', 'auto', 'last']);
    expect($block['pending'])->toBeFalse();
    expect($block['upgrading'])->toBeFalse();
    expect($block['stuck'])->toBeFalse();
    expect($block['auto'])->toBeTrue();
    // Only primitive, non-secret values (RH-9: scalars survive a serializing store; no paths/secrets leak).
    array_walk_recursive($block, fn ($v) => expect($v === null || is_scalar($v))->toBeTrue());
});
