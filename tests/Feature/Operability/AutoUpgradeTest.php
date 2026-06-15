<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\AclEntry;
use App\Models\AuditLog;
use App\Models\Group;
use App\Models\Role;
use App\Permissions\PermissionSync;
use App\Permissions\PermissionSyncReport;
use App\Upgrade\SchemaState;
use App\Upgrade\UpgradeRunner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| The no-SSH automatic upgrade mechanism (RH-10 / ADR-0021), driven through the UpgradeRunner directly.
| These manage their own temp file-DB + an out-of-tree fixture "release" migration (so real migrations
| actually run), so they do NOT use RefreshDatabase. The fixture dirs live in tests/Fixtures/upgrade/* and
| are added to novfora.upgrade.migration_paths so they read as pending and the runner applies them for real.
*/

/**
 * A migrated temp SQLite DB marked installed, with the chosen fixture "release" registered as a pending
 * migration. @return array{0:string,1:string} [dir, backupDir]
 */
function upgradeSandbox(string $fixture = 'ok'): array
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'novfora-upgrade-'.bin2hex(random_bytes(6));
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
    ]);
    DB::purge('sqlite');

    // Base schema only (the default path) — the fixture is NOT yet applied, so it reads as pending.
    Artisan::call('migrate', ['--force' => true]);

    // The site is installed (the runner only upgrades an installed site).
    file_put_contents($marker, '{"installed_at":"test"}');

    // Now the deployed "code" carries the fixture migration the DB has not seen.
    config(['novfora.upgrade.migration_paths' => [
        database_path('migrations'),
        base_path("tests/Fixtures/upgrade/{$fixture}"),
    ]]);

    app(SchemaState::class)->forget();

    return [$dir, $backupDir];
}

afterEach(function () {
    // Release the upgrade lock between tests so a held-lock test can't leak into the next.
    Cache::lock('novfora:upgrade:lock')->forceRelease();
});

it('auto-applies pending migrations: takes a backup, migrates, then exits maintenance', function () {
    [, $backupDir] = upgradeSandbox('ok');
    config(['novfora.upgrade.auto' => true]);
    $schema = app(SchemaState::class);

    expect($schema->hasPendingMigrations())->toBeTrue();
    expect(Schema::hasTable('rh10_probe'))->toBeFalse();

    $result = app(UpgradeRunner::class)->runAutomatic();

    expect($result->isSuccess())->toBeTrue();
    expect($result->migrationsApplied)->toBe(1);
    expect(Schema::hasTable('rh10_probe'))->toBeTrue();                 // (c) migrate ran
    expect($result->backup)->not->toBeNull();
    expect(is_file($backupDir.DIRECTORY_SEPARATOR.$result->backup))->toBeTrue(); // (b) backup taken first
    expect($schema->isUpgrading())->toBeFalse();                       // (e) maintenance exited
    expect($schema->isPending())->toBeFalse();                         // gate cleared
    expect($schema->shouldGateRequests())->toBeFalse();
    expect(AuditLog::where('action', 'upgrade.completed')->exists())->toBeTrue();
});

it('is idempotent: a second run after success is a no-op', function () {
    upgradeSandbox('ok');
    config(['novfora.upgrade.auto' => true]);

    expect(app(UpgradeRunner::class)->runAutomatic()->isSuccess())->toBeTrue();

    $again = app(UpgradeRunner::class)->runAutomatic();
    expect($again->isSkipped())->toBeTrue();
    expect($again->reason)->toBe('up-to-date');
});

it('does not auto-run in manual mode, but the manual apply (admin/CLI) works', function () {
    upgradeSandbox('ok');
    config(['novfora.upgrade.auto' => false]);

    $auto = app(UpgradeRunner::class)->runAutomatic();
    expect($auto->isSkipped())->toBeTrue();
    expect($auto->reason)->toBe('manual-mode');
    expect(Schema::hasTable('rh10_probe'))->toBeFalse(); // nothing applied automatically

    $manual = app(UpgradeRunner::class)->runManual();
    expect($manual->isSuccess())->toBeTrue();
    expect(Schema::hasTable('rh10_probe'))->toBeTrue();  // human-triggered apply worked
});

it('never double-runs: a held cache lock makes the run skip without migrating', function () {
    upgradeSandbox('ok');
    config(['novfora.upgrade.auto' => true]);

    // Another tick is "mid-run" — it holds the lock.
    expect(Cache::lock('novfora:upgrade:lock', 60)->get())->toBeTrue();

    $result = app(UpgradeRunner::class)->runAutomatic();

    expect($result->isSkipped())->toBeTrue();
    expect($result->reason)->toBe('locked');
    expect(Schema::hasTable('rh10_probe'))->toBeFalse(); // the lock owner runs it, not us
});

it('aborts when the pre-upgrade backup fails: no migration runs and it stays gated (surface loudly)', function () {
    [$dir] = upgradeSandbox('ok');
    config(['novfora.upgrade.auto' => true]);

    // Force a REAL backup failure: point the backup directory under a regular file, so it can't be
    // created → BackupService::create() throws. (BackupService is final; a real failure is faithful.)
    $blocker = $dir.DIRECTORY_SEPARATOR.'blocker';
    file_put_contents($blocker, 'x');
    config(['novfora.backup.path' => $blocker.DIRECTORY_SEPARATOR.'backups']);

    $result = app(UpgradeRunner::class)->runAutomatic();

    expect($result->isFailed())->toBeTrue();
    expect($result->stage)->toBe('backup');
    expect(Schema::hasTable('rh10_probe'))->toBeFalse(); // migrate NEVER ran — backup is first and aborts

    $schema = app(SchemaState::class);
    expect($schema->isUpgrading())->toBeFalse();
    expect($schema->isPending())->toBeTrue();            // still pending — not silently "done"
    expect($schema->shouldGateRequests())->toBeTrue();   // stays in maintenance
    expect(AuditLog::where('action', 'upgrade.failed')->exists())->toBeTrue();
});

it('holds for the operator after a migration failure: maintenance retained, health stuck, no retry loop', function () {
    upgradeSandbox('fail');
    config(['novfora.upgrade.auto' => true, 'novfora.upgrade.max_auto_attempts' => 2]);
    $schema = app(SchemaState::class);

    // Attempt 1 fails — a backup is still taken; not yet stuck (1 of 2).
    $r1 = app(UpgradeRunner::class)->runAutomatic();
    expect($r1->isFailed())->toBeTrue();
    expect($r1->stage)->toBe('migrate');
    expect($schema->isStuck())->toBeFalse();
    expect($schema->shouldGateRequests())->toBeTrue();   // maintenance retained
    expect($schema->lastBackupName())->not->toBeNull();  // backup-first even on a doomed migrate

    // Attempt 2 fails — cap reached → held for the operator.
    $r2 = app(UpgradeRunner::class)->runAutomatic();
    expect($r2->isFailed())->toBeTrue();
    expect($schema->isStuck())->toBeTrue();

    // Next tick: held — does NOT retry (no loop).
    $r3 = app(UpgradeRunner::class)->runAutomatic();
    expect($r3->isSkipped())->toBeTrue();
    expect($r3->reason)->toBe('stuck');

    // /health reflects stuck.
    $block = $schema->healthBlock();
    expect($block['stuck'])->toBeTrue();
    expect($block['pending'])->toBeTrue();
});

it("rolls back this run's applied batch when a later migration in the batch fails", function () {
    upgradeSandbox('partial');
    config(['novfora.upgrade.auto' => false]);

    expect(Schema::hasTable('rh10_step_one'))->toBeFalse();

    $result = app(UpgradeRunner::class)->runManual();

    expect($result->isFailed())->toBeTrue();
    expect($result->stage)->toBe('migrate');
    // step_one applied then rolled back → table gone; step_two never applied.
    expect(Schema::hasTable('rh10_step_one'))->toBeFalse();
    expect(app(SchemaState::class)->isStuck())->toBeTrue(); // a manual failure holds immediately
});

it('clears a stuck hold when the drift resolves (operator re-uploaded the previous release)', function () {
    upgradeSandbox('fail');
    config(['novfora.upgrade.auto' => true, 'novfora.upgrade.max_auto_attempts' => 1]);
    $schema = app(SchemaState::class);

    // One failed attempt → stuck (cap = 1).
    expect(app(UpgradeRunner::class)->runAutomatic()->isFailed())->toBeTrue();
    expect($schema->isStuck())->toBeTrue();

    // The operator re-uploads the PREVIOUS release: the failing fixture is no longer in the code, so
    // nothing is pending anymore.
    config(['novfora.upgrade.migration_paths' => [database_path('migrations')]]);

    $next = app(UpgradeRunner::class)->runAutomatic();
    expect($next->isSkipped())->toBeTrue();
    expect($next->reason)->toBe('up-to-date');
    expect($schema->isStuck())->toBeFalse();              // hold released
    expect($schema->shouldGateRequests())->toBeFalse();   // gate lifted on its own
});

it('the novfora:upgrade --check command reports status without applying anything', function () {
    upgradeSandbox('ok');

    $this->artisan('novfora:upgrade', ['--check' => true])->assertSuccessful();

    expect(Schema::hasTable('rh10_probe'))->toBeFalse(); // --check never applies
});

it('the novfora:upgrade command applies pending migrations (the operator CLI path)', function () {
    upgradeSandbox('ok');

    $this->artisan('novfora:upgrade')->assertSuccessful();

    expect(Schema::hasTable('rh10_probe'))->toBeTrue();
});

it('gates from the very first request after the mechanism-introducing deploy (empty state + pending)', function () {
    upgradeSandbox('ok'); // installed, the fixture is pending, and state was forgotten (the old code wrote none)
    config(['novfora.upgrade.auto' => true]);
    $schema = app(SchemaState::class);

    expect($schema->state())->toBe([]); // no prior state to fingerprint-compare against

    // A one-time authoritative bootstrap closes the deploy→first-tick gap: the first request is already gated.
    expect($schema->shouldGateRequests())->toBeTrue();
    expect($schema->state())->not->toBe([]); // …and it populated the cache, so it isn't re-checked per request
});

it('skips entirely when the site is not yet installed', function () {
    upgradeSandbox('ok');
    config(['novfora.upgrade.auto' => true]);
    @unlink(config('novfora.install.marker')); // pretend not installed

    $result = app(UpgradeRunner::class)->runAutomatic();

    expect($result->isSkipped())->toBeTrue();
    expect($result->reason)->toBe('not-installed');
    expect(Schema::hasTable('rh10_probe'))->toBeFalse();
});

it('re-provisions role presets during an upgrade (a preset key dropped since seed is restored) — ADR-0036', function () {
    upgradeSandbox('ok');
    config(['novfora.upgrade.auto' => true]);

    // Seed the install, then simulate a site seeded BEFORE badge.manage existed on the admin preset.
    Artisan::call('db:seed', ['--force' => true]);
    $adminsId = (int) Group::query()->where('slug', 'admins')->value('id');
    $missing = fn () => AclEntry::query()->where('holder_type', 'group')->where('holder_id', $adminsId)
        ->where('scope_type', 'global')->whereNull('scope_id')->where('permission_key', 'badge.manage')->exists();

    AclEntry::query()->where('holder_type', 'group')->where('holder_id', $adminsId)
        ->where('scope_type', 'global')->whereNull('scope_id')->where('permission_key', 'badge.manage')->delete();
    Role::query()->where('slug', 'administrator')->first()
        ->permissions()->where('permission_key', 'badge.manage')->delete();
    expect($missing())->toBeFalse();

    $result = app(UpgradeRunner::class)->runAutomatic();

    expect($result->isSuccess())->toBeTrue();
    expect(Schema::hasTable('rh10_probe'))->toBeTrue();          // schema upgrade ran…
    expect($missing())->toBeTrue();                              // …and permissions:sync restored the grant
});

it('an upgrade still succeeds when permissions:sync throws (best-effort — the schema upgrade is authoritative)', function () {
    upgradeSandbox('ok');
    config(['novfora.upgrade.auto' => true]);

    // Swap in a sync that explodes; the upgrade must not be derailed by it.
    app()->instance(PermissionSync::class, new class extends PermissionSync
    {
        public function sync(): PermissionSyncReport
        {
            throw new RuntimeException('boom');
        }
    });

    $result = app(UpgradeRunner::class)->runAutomatic();

    expect($result->isSuccess())->toBeTrue();
    expect(Schema::hasTable('rh10_probe'))->toBeTrue();
    expect(AuditLog::where('action', 'upgrade.permissions_sync_failed')->exists())->toBeTrue();
});
