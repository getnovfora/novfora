<?php

// SPDX-License-Identifier: Apache-2.0

use App\Permissions\VisibleForumIds;
use Tests\DuskTestCase;
use Tests\TestCase;

/*
| Pest bootstrap — bind the Laravel TestCase to Feature tests so they get the application
| container, config(), and helpers like $this->artisan(). Unit tests stay framework-free.
*/

// Point the file-based no-SSH restore state (RH-11) at a unique, guaranteed-absent temp path for EVERY
// feature test, so no test ever reads (or wedges on) a real on-disk storage/novfora-restore.json. Unlike the
// cache-backed SchemaState (reset by CACHE_STORE=array + the per-test app rebuild), this is a real file the
// harness would not otherwise clear; without this, a leftover restore-state file would silently flip the
// RH-10 upgrade suites to skipped('restore-in-progress') and /health to degraded. RH-11's own suites set
// their own temp paths on top of this (and forget()), so they remain self-contained.
uses(TestCase::class)->beforeEach(function () {
    $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'novfora-test-restore-'.bin2hex(random_bytes(6));
    config([
        'novfora.backup.restore_state_path' => $base.DIRECTORY_SEPARATOR.'novfora-restore.json',
        'novfora.backup.restore_lock_path' => $base.DIRECTORY_SEPARATOR.'novfora-restore.lock',
    ]);

    // VisibleForumIds keeps a STATIC per-viewer memo that — unlike the array cache store — survives the
    // per-test app rebuild, so it persists across tests within a parallel worker. With sqlite + the
    // RefreshDatabase transaction rollback, autoincrement ids are reused between tests, so a stale memo
    // entry for a reused viewer id could bleed a "sees all forums" verdict into the next test (a
    // visibility-leak-shaped flake under --parallel). Flush it before every feature test so each starts
    // from a cold cache — the production resolver is unaffected.
    VisibleForumIds::flush();
})->in('Feature');

// Dusk browser journeys — the Spike-0 editor battery, run via `php artisan dusk` (Chrome-enabled CI).
// The normal pest run uses phpunit.xml's Unit + Feature suites, so it never loads Browser/Chrome.
uses(DuskTestCase::class)->in('Browser');
