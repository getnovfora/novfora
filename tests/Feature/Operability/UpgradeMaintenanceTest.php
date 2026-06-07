<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Upgrade\SchemaState;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
| Requests during the upgrade window (RH-10 / ADR-0021 §3): while the schema is behind the deployed code,
| every page gets a branded maintenance 503 with Retry-After — never a raw SQL error — except the health
| endpoints (so the owner/Cowork can watch the upgrade) and assets. PreventRequestsDuringUpgrade decides
| this from the cached flag only (no DB on the request path).
*/

beforeEach(function () {
    app(SchemaState::class)->forget();
    $this->seed();
});

it('serves a branded 503 maintenance page (not a SQL error) while a run is in progress', function () {
    app(SchemaState::class)->beginRun();

    $res = $this->get('/forums');

    $res->assertStatus(503);
    $res->assertHeader('Retry-After');
    expect($res->getContent())->toContain('Just a moment'); // the branded view rendered, not an exception
});

it('keeps /health reachable during the window so the upgrade can be watched without SSH', function () {
    app(SchemaState::class)->beginRun();

    $this->getJson('/health')->assertOk()->assertJsonPath('schema.upgrading', true);
});

it('returns a 503 JSON body for AJAX/JSON requests during the window', function () {
    app(SchemaState::class)->beginRun();

    $res = $this->getJson('/forums');

    $res->assertStatus(503)->assertJsonPath('status', 'maintenance');
    $res->assertHeader('Retry-After');
});

it('shows the operator recovery hint (pre-upgrade backup) when an upgrade is stuck', function () {
    app(SchemaState::class)->put(['stuck' => true, 'last' => ['backup' => 'hearth-20260607-101500.zip']]);

    $res = $this->get('/forums');

    $res->assertStatus(503);
    expect($res->getContent())->toContain('Upgrade paused');
    expect($res->getContent())->toContain('hearth-20260607-101500.zip'); // the recovery hint
});

it('gates in auto mode for a new-deploy fingerprint mismatch (the deploy window)', function () {
    config(['hearth.upgrade.auto' => true]);
    app(SchemaState::class)->put(['pending' => false, 'fingerprint' => 'an-old-release']);

    $this->get('/forums')->assertStatus(503);
});

it('does not gate in manual mode for a merely-pending state (admin can reach the panel)', function () {
    config(['hearth.upgrade.auto' => false]);
    app(SchemaState::class)->put(['pending' => true, 'fingerprint' => app(SchemaState::class)->codeFingerprint()]);

    $this->get('/forums')->assertOk();
});

it('does not gate a normal, up-to-date site', function () {
    app(SchemaState::class)->refresh(); // nothing pending

    $this->get('/forums')->assertOk();
});

it('treats a stale upgrading flag from a hard-killed run as expired, so the site is never wedged forever', function () {
    // A process killed between beginRun() and the success/failure record leaves upgrading=true. The
    // timestamped flag self-expires past the lock window so the site recovers on its own — not a permanent
    // 503 — even in automatic mode (refresh() first so a missing fingerprint doesn't independently gate).
    $schema = app(SchemaState::class);
    $schema->refresh(); // nothing pending: pending=false, fingerprint stamped
    $window = (int) config('hearth.upgrade.lock_seconds', 600);
    $schema->put(['upgrading' => true, 'upgrading_at' => now()->subSeconds($window + 60)->timestamp]);

    $this->get('/forums')->assertOk();
});
