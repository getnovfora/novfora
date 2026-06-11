<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Backup\RestoreState;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
| Requests during a no-SSH restore window (RH-11 / ADR-0022 §4): while a restore is requested, running, or
| held for the operator, every page gets a branded maintenance 503 (restore variant) — never a SQL error or
| a half-restored page — except the health endpoints (so it can be watched) and assets. The decision is a
| single FILE read (RestoreState), which is why it survives the DB/cache the restore overwrites. The state
| file is pointed at a temp path so this never touches the real storage path (keeps the RH-10 suites clean).
*/

beforeEach(function () {
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'novfora-rh11-mw-'.bin2hex(random_bytes(6));
    @mkdir($dir, 0775, true);
    config([
        'novfora.backup.restore_state_path' => $dir.DIRECTORY_SEPARATOR.'novfora-restore.json',
        'novfora.backup.restore_lock_path' => $dir.DIRECTORY_SEPARATOR.'novfora-restore.lock',
    ]);
    app(RestoreState::class)->forget();
    $this->seed();
});

afterEach(fn () => app(RestoreState::class)->forget());

it('serves a branded 503 restore page (not a SQL error) while a restore is running', function () {
    app(RestoreState::class)->beginRun();

    $res = $this->get('/forums');

    $res->assertStatus(503);
    $res->assertHeader('Retry-After');
    expect($res->getContent())->toContain('Just a moment');         // the branded view rendered
    expect($res->getContent())->toContain('restoring a backup');     // …in restore mode
});

it('gates the queued window too: a requested-but-not-yet-running restore returns 503', function () {
    app(RestoreState::class)->request('novfora-20260607-101500.zip', null, 'Admin');

    $this->get('/forums')->assertStatus(503);
});

it('keeps /health reachable during the window and reports the restore block', function () {
    app(RestoreState::class)->beginRun();

    $this->getJson('/health')
        ->assertOk()
        ->assertJsonPath('restore.running', true);
});

it('returns a 503 JSON body for AJAX/JSON requests during the window', function () {
    app(RestoreState::class)->beginRun();

    $res = $this->getJson('/forums');

    $res->assertStatus(503)->assertJsonPath('status', 'maintenance');
    $res->assertHeader('Retry-After');
});

it('shows the recovery hint (pre-restore safety snapshot) when a restore is stuck, and /health is degraded', function () {
    app(RestoreState::class)->put([
        'stuck' => true,
        'last' => ['result' => 'failed', 'archive' => 'novfora-20260607-090000.zip', 'safety_backup' => 'novfora-20260607-101500.zip'],
    ]);

    $res = $this->get('/forums');
    $res->assertStatus(503);
    expect($res->getContent())->toContain('Restore paused');
    expect($res->getContent())->toContain('novfora-20260607-101500.zip'); // the safety-snapshot recovery hint

    $this->getJson('/health')
        ->assertOk()
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('restore.stuck', true);
});

it('does not gate a normal site with no restore in flight', function () {
    // No restore state at all.
    $this->get('/forums')->assertOk();
    $this->getJson('/health')->assertOk()->assertJsonPath('restore.running', false);
});
