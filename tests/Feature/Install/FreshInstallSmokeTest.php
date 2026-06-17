<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Install\Installer;
use App\Install\InstallInput;
use App\Install\InstallRunner;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Topic;
use App\Models\User;
use App\Permissions\Scope;
use Illuminate\Support\Facades\Schema;

/*
| FRESH-INSTALL smoke (P5.6) — proves the from-scratch redeploy path end to end. Drives the SAME InstallRunner
| the no-SSH web wizard and the `novfora:install` CLI use, against a truly EMPTY sqlite DB, then asserts the
| outcome the operator depends on: the schema is created, the system posture (groups + permissions + roles +
| ACL) is seeded, the first administrator is created AND actually holds admin power through the permission
| engine, and the install lock is written. If any of these regress, a clean redeploy is broken — so this runs
| in the normal gate.
|
| Deliberately NOT RefreshDatabase: the runner points the live connection at its own fresh sqlite file and
| migrates THERE, exactly as a real install does.
*/

/** A throwaway, not-yet-installed sandbox: temp .env + marker + an empty sqlite file. */
function freshInstallSandbox(): string
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'novfora-fresh-'.bin2hex(random_bytes(6));
    @mkdir($dir, 0775, true);
    $db = $dir.DIRECTORY_SEPARATOR.'database.sqlite';
    touch($db); // empty — no schema yet

    config([
        'novfora.install.enforce' => true,
        'novfora.install.env_path' => $dir.DIRECTORY_SEPARATOR.'.env',
        'novfora.install.marker' => $dir.DIRECTORY_SEPARATOR.'installed',
    ]);

    return $db;
}

it('drives a fresh install green: schema + seeded posture + a capable first admin + the lock', function () {
    $db = freshInstallSandbox();
    $installer = app(Installer::class);

    expect($installer->isInstalled())->toBeFalse(); // truly fresh

    app(InstallRunner::class)->run(new InstallInput(
        siteName: 'Fresh Forum',
        appUrl: 'https://fresh.test',
        dbDriver: 'sqlite',
        dbHost: '', dbPort: 0, dbDatabase: $db, dbUsername: '', dbPassword: '',
        adminUsername: 'firstadmin',
        adminEmail: 'first@fresh.test',
        adminPassword: 'Sup3rSecret!!',
        seedDemo: false,
        setupToken: '',
    ));

    // 1. Schema exists — the migrations ran against the empty DB.
    foreach (['users', 'groups', 'permissions', 'roles', 'acl_entries', 'forums', 'topics', 'posts'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("table {$table} should exist after a fresh install");
    }

    // 2. The system posture is seeded (DatabaseSeeder): the system groups, the permission catalogue, roles.
    expect(Group::whereIn('slug', ['admins', 'members', 'guests'])->count())->toBe(3)
        ->and(Group::where('slug', 'tl4')->exists())->toBeTrue()
        ->and(Permission::count())->toBeGreaterThan(0)
        ->and(Role::count())->toBeGreaterThan(0);

    // 3. The first administrator exists, is active + fully trusted, and ACTUALLY holds admin power — the
    //    capability resolves through the seeded permissions + roles + ACL + group membership (the real proof
    //    a redeploy is usable, not just that rows exist).
    $admin = User::where('email', 'first@fresh.test')->firstOrFail();
    expect($admin->status)->toBe('active')
        ->and((int) $admin->trust_level)->toBe(4)
        ->and($admin->email_verified_at)->not->toBeNull()
        ->and($admin->groups->pluck('slug'))->toContain('admins')
        ->and($admin->canDo('admin.access', Scope::global()))->toBeTrue()
        ->and($admin->isStaff())->toBeTrue();

    // 4. No demo content leaked in (seedDemo=false): a fresh redeploy starts empty of topics.
    expect(Topic::count())->toBe(0);

    // 5. Locked LAST — the marker is written only after every step succeeded.
    expect($installer->isInstalled())->toBeTrue();
});
