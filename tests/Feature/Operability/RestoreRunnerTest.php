<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Backup\BackupException;
use App\Backup\BackupService;
use App\Backup\RestoreRunner;
use App\Backup\RestoreService;
use App\Backup\RestoreState;
use App\Models\AuditLog;
use App\Models\User;
use App\Upgrade\SchemaState;
use App\Upgrade\UpgradeRunner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| The no-SSH panel-restore orchestrator (RH-11 / ADR-0022), driven through RestoreRunner directly. Like the
| backup + auto-upgrade suites, these manage their own temp file-DB + temp backup dir + temp (file-based)
| restore-state path, so they do NOT use RefreshDatabase and never touch the default storage paths (so the
| RH-10 suites stay isolated). The crux test proves the RH-11 → RH-10 hand-off: a restored older schema is
| picked up + migrated by the auto-upgrade tick.
*/

/** @return array{0:string,1:string,2:string} [dir, backupDir, sqlitePath] — migrated temp DB, installed. */
function restoreSandbox(): array
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'novfora-rh11-'.bin2hex(random_bytes(6));
    @mkdir($dir, 0775, true);
    $db = $dir.DIRECTORY_SEPARATOR.'app.sqlite';
    touch($db);
    $backupDir = $dir.DIRECTORY_SEPARATOR.'backups';
    $marker = $dir.DIRECTORY_SEPARATOR.'installed';

    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => $db,
        'novfora.backup.path' => $backupDir,
        'novfora.install.marker' => $marker,
        'novfora.backup.restore_state_path' => $dir.DIRECTORY_SEPARATOR.'novfora-restore.json',
        'novfora.backup.restore_lock_path' => $dir.DIRECTORY_SEPARATOR.'novfora-restore.lock',
        'novfora.upgrade.migration_paths' => [database_path('migrations')],
    ]);
    DB::purge('sqlite');
    Artisan::call('migrate', ['--force' => true]);
    file_put_contents($marker, '{"installed_at":"test"}');

    app(RestoreState::class)->forget();
    app(SchemaState::class)->forget();

    return [$dir, $backupDir, $db];
}

/** A zip that passes manifest+hash validation but is NOT applicable to a SQLite site (kind=sql → driver
 *  mismatch at the restore step), so we can exercise a mid-restore failure that keeps SQLite usable. */
function unapplicableSqlBackup(string $backupDir): string
{
    @mkdir($backupDir, 0775, true);
    $path = $backupDir.DIRECTORY_SEPARATOR.'novfora-20990101-000000.zip';
    $dump = "-- not applied\nSELECT 1;\n";
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('database.sql', $dump);
    $zip->addFromString('manifest.json', json_encode([
        'format' => 1,
        'app' => 'NovFora',
        'version' => 'x',
        'created_at' => now()->toIso8601String(),
        'db' => ['kind' => 'sql', 'driver' => 'mysql', 'file' => 'database.sql', 'sha256' => hash('sha256', $dump)],
        'storage_included' => false,
    ]));
    $zip->close();

    return $path;
}

it('round-trips a restore through the runner: safety snapshot taken, maintenance exited, audit written', function () {
    [, $backupDir] = restoreSandbox();

    $user = User::factory()->create();
    $id = $user->id;
    $target = app(BackupService::class)->create(0)->name(); // the restore point (has the user)

    User::query()->delete(); // the disaster
    expect(User::count())->toBe(0);

    $result = app(RestoreRunner::class)->runNow($backupDir.DIRECTORY_SEPARATOR.$target);

    expect($result->isSuccess())->toBeTrue();
    expect(User::whereKey($id)->exists())->toBeTrue();                 // restored byte-for-byte

    $state = app(RestoreState::class);
    expect($state->isRunning())->toBeFalse();                         // maintenance exited
    expect($state->isStuck())->toBeFalse();
    expect($state->shouldGateRequests())->toBeFalse();                // gate lifted
    expect($result->safetyBackup)->not->toBeNull();                  // (2) pre-restore safety snapshot…
    expect(is_file($backupDir.DIRECTORY_SEPARATOR.$result->safetyBackup))->toBeTrue(); // …actually written
    expect(AuditLog::where('action', 'restore.completed')->exists())->toBeTrue();
});

it('refuses a corrupt archive before touching anything (gate not left up, data intact)', function () {
    [, $backupDir] = restoreSandbox();

    $user = User::factory()->create();
    $id = $user->id;
    $target = app(BackupService::class)->create(0)->name();

    // Tamper the dump inside the archive so the manifest SHA-256 no longer matches.
    $zip = new ZipArchive;
    $zip->open($backupDir.DIRECTORY_SEPARATOR.$target);
    $zip->deleteName('database.sqlite');
    $zip->addFromString('database.sqlite', 'tampered-bytes');
    $zip->close();

    $result = app(RestoreRunner::class)->runNow($backupDir.DIRECTORY_SEPARATOR.$target);

    expect($result->isFailed())->toBeTrue();
    expect($result->stage)->toBe('validate');
    expect(User::whereKey($id)->exists())->toBeTrue();   // nothing was touched
    $state = app(RestoreState::class);
    expect($state->shouldGateRequests())->toBeFalse();   // refused before maintenance → gate not left up
});

it('drains a panel-requested restore from the cron path (runPending)', function () {
    [, $backupDir] = restoreSandbox();

    $user = User::factory()->create();
    $id = $user->id;
    $target = app(BackupService::class)->create(0)->name();
    User::query()->delete();

    // The panel records the request; the gate engages immediately (file-based, survives the DB swap).
    app(RestoreRunner::class)->request($target, actorId: null, actorName: 'Admin');
    expect(app(RestoreState::class)->shouldGateRequests())->toBeTrue();
    expect(app(RestoreState::class)->requestedArchive())->toBe($target);

    // The cron tick performs it.
    $result = app(RestoreRunner::class)->runPending();

    expect($result->isSuccess())->toBeTrue();
    expect(User::whereKey($id)->exists())->toBeTrue();
    expect(app(RestoreState::class)->shouldGateRequests())->toBeFalse(); // request cleared, gate lifted
});

it('runPending is a cheap no-op when nothing is requested', function () {
    restoreSandbox();

    $result = app(RestoreRunner::class)->runPending();

    expect($result->isSkipped())->toBeTrue();
    expect($result->reason)->toBe('nothing-pending');
});

it('hands off to RH-10: a restored older schema reads as pending and the auto-upgrade tick applies it', function () {
    [, $backupDir] = restoreSandbox();

    // (a) back up the BASE schema (the fixture migration is not applied yet).
    $oldBackup = app(BackupService::class)->create(0)->name();

    // (b) the live install moves forward — deployed code now carries + has applied a fixture migration.
    config(['novfora.upgrade.migration_paths' => [
        database_path('migrations'),
        base_path('tests/Fixtures/upgrade/ok'),
    ]]);
    Artisan::call('migrate', [
        '--force' => true,
        '--path' => config('novfora.upgrade.migration_paths'),
        '--realpath' => true,
    ]);
    expect(Schema::hasTable('rh10_probe'))->toBeTrue();
    app(SchemaState::class)->forget();
    expect(app(SchemaState::class)->hasPendingMigrations())->toBeFalse(); // up to date

    // (c) restore the OLDER backup (predates the fixture).
    config(['novfora.upgrade.auto' => true]);
    expect(app(RestoreRunner::class)->runNow($backupDir.DIRECTORY_SEPARATOR.$oldBackup)->isSuccess())->toBeTrue();

    // The restored schema is now BEHIND the deployed code → rh10_probe gone, migrations pending, gate holds.
    expect(Schema::hasTable('rh10_probe'))->toBeFalse();
    $schema = app(SchemaState::class);
    expect($schema->isPending())->toBeTrue();
    expect($schema->shouldGateRequests())->toBeTrue();      // RH-10 upgrade gate holds the site (auto mode)
    expect(app(RestoreState::class)->shouldGateRequests())->toBeFalse(); // the RESTORE gate already lifted

    // (d) the auto-upgrade tick applies the now-pending migration cleanly — the RH-11 → RH-10 hand-off.
    $upgrade = app(UpgradeRunner::class)->runAutomatic();
    expect($upgrade->isSuccess())->toBeTrue();
    expect(Schema::hasTable('rh10_probe'))->toBeTrue();
    expect(app(SchemaState::class)->isPending())->toBeFalse();
});

it('the auto-upgrade tick stands down while a restore is in progress', function () {
    restoreSandbox();
    config(['novfora.upgrade.auto' => true]);

    // A restore is requested but not yet drained — the gate is up.
    app(RestoreState::class)->request('novfora-20260101-000000.zip', null, 'Admin');

    $result = app(UpgradeRunner::class)->runAutomatic();

    expect($result->isSkipped())->toBeTrue();
    expect($result->reason)->toBe('restore-in-progress');
});

it('holds the site after ANY mid-restore failure — even with default config (the HIGH fix)', function () {
    [, $backupDir] = restoreSandbox();
    // No restore_max_attempts tuning: single-attempt is the shipped default. A restore-stage failure must
    // keep the site gated, NOT lift the maintenance page over a possibly half-restored DB (RH-11 review HIGH).
    User::factory()->create();

    // A real archive on disk to stage, but force the apply step to throw (validation passes, restore fails).
    $path = $backupDir.DIRECTORY_SEPARATOR.'novfora-20990101-000000.zip';
    @mkdir($backupDir, 0775, true);
    file_put_contents($path, 'staged-bytes');
    $this->mock(RestoreService::class, function ($m) {
        $m->shouldReceive('validate')->andReturn([]);                       // passes validation
        $m->shouldReceive('restore')->andThrow(new BackupException('disk full mid-restore'));
    });

    $result = app(RestoreRunner::class)->runNow($path);

    expect($result->isFailed())->toBeTrue();
    expect($result->stage)->toBe('restore');                 // failed AFTER validation, at the restore step

    $state = app(RestoreState::class);
    expect($state->isStuck())->toBeTrue();                   // single-attempt → held immediately
    expect($state->isRunning())->toBeFalse();
    expect($state->shouldGateRequests())->toBeTrue();        // site STAYS in maintenance (the HIGH fix)
    expect($state->lastSafetyBackup())->not->toBeNull();     // a pre-restore snapshot was taken first
    expect(AuditLog::where('action', 'restore.failed')->exists())->toBeTrue();
});

it('refuses a cross-engine archive up front (validate stage), without entering maintenance', function () {
    [, $backupDir] = restoreSandbox(); // a SQLite site
    User::factory()->create();
    $crossEngine = unapplicableSqlBackup($backupDir); // a kind=sql / driver=mysql backup

    $result = app(RestoreRunner::class)->runNow($crossEngine);

    expect($result->isFailed())->toBeTrue();
    expect($result->stage)->toBe('validate');            // engine mismatch caught before touching anything
    $state = app(RestoreState::class);
    expect($state->isStuck())->toBeFalse();
    expect($state->shouldGateRequests())->toBeFalse();   // nothing touched → gate not left up
});

it('holds for the operator when a previous restore was interrupted mid-run (crash detection)', function () {
    restoreSandbox();
    $state = app(RestoreState::class);

    // Simulate a process killed mid-restore: a request is recorded and `running` is set, but no result was
    // ever written. The file lock is free (the crashed process released it on death).
    $state->request('novfora-20260101-000000.zip', null, 'Admin');
    $state->beginRun();
    expect($state->isRunning())->toBeTrue();

    $result = app(RestoreRunner::class)->runPending();

    expect($result->isFailed())->toBeTrue();
    expect($result->stage)->toBe('restore');
    expect(app(RestoreState::class)->isStuck())->toBeTrue();             // held — never blindly re-run
    expect(app(RestoreState::class)->shouldGateRequests())->toBeTrue();  // site stays gated
});

it('skips entirely when the site is not yet installed', function () {
    restoreSandbox();
    @unlink(config('novfora.install.marker'));
    app(RestoreState::class)->request('novfora-20260101-000000.zip', null, 'Admin');

    $result = app(RestoreRunner::class)->runPending();

    expect($result->isSkipped())->toBeTrue();
    expect($result->reason)->toBe('not-installed');
});

it('request()  up front, without recording a request', function () {
    [, $backupDir] = restoreSandbox();
    @mkdir($backupDir, 0775, true);
    $foreign = $backupDir.DIRECTORY_SEPARATOR.'novfora-20300101-000000.zip';
    $zip = new ZipArchive;
    $zip->open($foreign, ZipArchive::CREATE);
    $zip->addFromString('readme.txt', 'not a novfora backup'); // no manifest.json
    $zip->close();

    expect(fn () => app(RestoreRunner::class)->request('novfora-20300101-000000.zip', null, 'Admin'))
        ->toThrow(BackupException::class);
    expect(app(RestoreState::class)->isRequested())->toBeFalse(); // nothing queued → gate stays open
});

it('request() refuses a cross-engine archive up front, without recording a request', function () {
    [, $backupDir] = restoreSandbox(); // a SQLite site
    $crossEngine = basename(unapplicableSqlBackup($backupDir)); // a kind=sql / driver=mysql backup

    expect(fn () => app(RestoreRunner::class)->request($crossEngine, null, 'Admin'))
        ->toThrow(BackupException::class);
    expect(app(RestoreState::class)->isRequested())->toBeFalse();
});
