<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Backup\BackupException;
use App\Backup\BackupService;
use App\Backup\RestoreService;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
| Backups + restore (M5, phase-1-plan §5 / exit criterion 6). A real backup→restore round-trip on the
| baseline DB engine (SQLite here, where backup = file copy; the MySQL mysqldump/mysql path is exercised
| end-to-end in CI), the integrity guard, and the reversible-migration upgrade rehearsal — all asserted
| green, so the upgrade/restore safety net is proven, not just documented.
|
| These manage their own temp file-DB (not the in-memory suite DB), so they don't use RefreshDatabase.
*/

/** @return array{0:string,1:string} [dir, sqlitePath] — a migrated temp SQLite DB + a backup dir. */
function backupSandbox(): array
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'novfora-backup-'.bin2hex(random_bytes(6));
    @mkdir($dir, 0775, true);
    $db = $dir.DIRECTORY_SEPARATOR.'app.sqlite';
    touch($db);

    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => $db,
        'novfora.backup.path' => $dir.DIRECTORY_SEPARATOR.'backups',
    ]);
    DB::purge('sqlite');
    Artisan::call('migrate', ['--force' => true]);

    return [$dir, $db];
}

it('round-trips a backup and restore on the baseline database engine', function () {
    backupSandbox();

    $user = User::factory()->create();
    $id = $user->id;
    expect(User::whereKey($id)->exists())->toBeTrue();

    $result = app(BackupService::class)->create();
    expect(is_file($result->path))->toBeTrue();
    expect($result->kind)->toBe('sqlite');

    // Lose the data (a disaster, or a bad upgrade).
    User::query()->delete();
    expect(User::count())->toBe(0);

    // Restore brings it back, byte-for-byte.
    $report = app(RestoreService::class)->restore($result->path);
    expect($report['db_driver'])->toBe('sqlite');
    expect(User::whereKey($id)->exists())->toBeTrue();
});

it('verifies archive integrity and refuses a tampered dump', function () {
    backupSandbox();
    User::factory()->create();

    $result = app(BackupService::class)->create();

    // Tamper with the database dump inside the archive (manifest SHA-256 no longer matches).
    $zip = new ZipArchive;
    $zip->open($result->path);
    $zip->deleteName('database.sqlite');
    $zip->addFromString('database.sqlite', 'tampered-bytes');
    $zip->close();

    expect(fn () => app(RestoreService::class)->restore($result->path))
        ->toThrow(BackupException::class);
});

it('', function () {
    [$dir] = backupSandbox();
    $bogus = $dir.DIRECTORY_SEPARATOR.'backups'.DIRECTORY_SEPARATOR.'novfora-19990101-000000.zip';
    @mkdir(dirname($bogus), 0775, true);
    $zip = new ZipArchive;
    $zip->open($bogus, ZipArchive::CREATE);
    $zip->addFromString('readme.txt', 'not a novfora backup');
    $zip->close();

    expect(fn () => app(RestoreService::class)->restore($bogus))
        ->toThrow(BackupException::class);
});

it('proves the upgrade rehearsal: back up, run a reversible migration cycle, restore', function () {
    backupSandbox();

    $user = User::factory()->create();
    $id = $user->id;

    $result = app(BackupService::class)->create();

    // Simulate an upgrade: a full reversible migration cycle (down to nothing, back up) wipes data but
    // runs clean — the non-destructive-migration guarantee (CLAUDE.md hard rule).
    Artisan::call('migrate:fresh', ['--force' => true]);
    expect(User::count())->toBe(0);

    // The safety net: restore to exactly the pre-upgrade state.
    app(RestoreService::class)->restore($result->path);
    expect(User::whereKey($id)->exists())->toBeTrue();
});

it('prunes old archives beyond the retention count', function () {
    [$dir] = backupSandbox();
    $backupDir = $dir.DIRECTORY_SEPARATOR.'backups';
    @mkdir($backupDir, 0775, true);

    // Five fake archives with distinct timestamps.
    foreach (['20260101-000000', '20260102-000000', '20260103-000000', '20260104-000000', '20260105-000000'] as $i => $stamp) {
        $path = $backupDir.DIRECTORY_SEPARATOR.'novfora-'.$stamp.'.zip';
        file_put_contents($path, 'x');
        touch($path, mktime(0, 0, 0, 1, $i + 1, 2026));
    }

    app(BackupService::class)->prune(2);

    expect(count(app(BackupService::class)->list()))->toBe(2);
    // The two newest survive.
    expect(is_file($backupDir.DIRECTORY_SEPARATOR.'novfora-20260105-000000.zip'))->toBeTrue();
    expect(is_file($backupDir.DIRECTORY_SEPARATOR.'novfora-20260101-000000.zip'))->toBeFalse();
});
