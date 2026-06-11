<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Backup\BackupService;
use App\Backup\RestoreService;
use App\Models\Group;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
| The pure-PHP MySQL backup + restore path (phase-1.5 baseline hardening): backups must work on shared hosts
| that disable proc_open / lack mysqldump. This exercises the in-process dumper + PDO restore against a real
| MySQL connection, so it is skipped on the default SQLite suite and runs in the MySQL-backed CI job (and
| locally via the compose MySQL service). It does NOT use RefreshDatabase — the dumper reads committed data.
*/

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('pure-PHP MySQL backup path requires a MySQL connection');
    }
    config([
        'novfora.backup.db_method' => 'php', // force the pure-PHP path even where mysqldump exists
        'novfora.backup.path' => storage_path('phpbackup-test'),
    ]);
    Artisan::call('migrate:fresh', ['--force' => true]);
    Artisan::call('db:seed', ['--force' => true]);
});

afterEach(function () {
    $dir = storage_path('phpbackup-test');
    foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
});

it('round-trips a MySQL database through the pure-PHP dump and restore', function () {
    $before = Group::count();
    expect($before)->toBeGreaterThan(0);

    $result = app(BackupService::class)->create();
    expect(is_file($result->path))->toBeTrue();

    Artisan::call('migrate:fresh', ['--force' => true]); // simulate a wipe / bad upgrade
    expect(Group::count())->toBe(0);

    app(RestoreService::class)->restore($result->path);
    expect(Group::count())->toBe($before); // restored to exactly the prior state, no shell tools used
});
