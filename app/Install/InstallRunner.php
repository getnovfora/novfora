<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Install;

use App\Models\Group;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * The single, ordered install sequence shared by the web wizard and the `hearth:install` CLI command
 * (M5, phase-1-plan §5). Keeping ONE runner means there is exactly one place where secrets are written,
 * migrations are run, and the admin is created — one surface to reason about and to test.
 *
 * ORDER MATTERS (security + correctness):
 *   1. refuse to run if already installed (defence in depth — routes also guard);
 *   2. write `.env` (secrets) + ensure APP_KEY, then point the LIVE connection at the new DB;
 *   3. verify the DB connects before touching it;
 *   4. migrate → seed the system posture → (optional) demo seed;
 *   5. create the first admin (argon2id, email-verified, staff — 2FA is then enforced on first sign-in);
 *   6. link public storage (closes the M4 avatar/cover caveat);
 *   7. LOCK LAST: write the install marker only after every step above succeeded.
 *
 * If any step throws, the marker is never written, so the installer stays open for a retry rather than
 * locking a half-built site.
 */
final class InstallRunner
{
    public function __construct(
        private readonly Installer $installer,
        private readonly EnvWriter $env,
        private readonly DatabaseVerifier $verifier,
    ) {}

    public function run(InstallInput $in): InstallResult
    {
        if ($this->installer->isInstalled()) {
            throw new \RuntimeException('Hearth is already installed.');
        }

        $notes = [];

        // 2. Persist configuration + secrets, and ensure an APP_KEY exists.
        $this->env->ensureExists();
        $this->env->ensureAppKey();
        $this->writeEnv($in);

        // Point the running process at the just-entered database so migrations land in the right place.
        $this->applyDatabaseConfig($in);

        // 3. Verify connectivity before we run anything destructive.
        $check = $this->verifier->verify(
            $in->dbDriver, $in->dbHost, $in->dbPort, $in->dbDatabase, $in->dbUsername, $in->dbPassword,
        );
        if (! $check['ok']) {
            throw new \RuntimeException($check['message']);
        }

        // 4. Schema + the production-safe default posture (groups, permissions, roles, trust gates).
        Artisan::call('migrate', ['--force' => true]);
        Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

        $demoSeeded = false;
        if ($in->seedDemo) {
            Artisan::call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
            $demoSeeded = true;
        }

        // 5. The first administrator. argon2id is the configured hash driver; the account is email-verified
        //    so they can sign in immediately, and is staff — RequireTwoFactorForStaff (M1) then forces TOTP
        //    enrolment on their first visit to an admin panel.
        $this->createAdmin($in);

        // 6. Public storage symlink (avatars/covers/attachment thumbnails). Some shared hosts forbid
        //    symlinks; that must not fail the install — we record a note instead.
        $storageLinked = $this->linkStorage($notes);

        // Drop any cached config so the next request reads the freshly written .env.
        try {
            Artisan::call('config:clear');
        } catch (Throwable) {
            // Non-fatal; a fresh upload has nothing cached.
        }

        // 7. LOCK — written last, only now that everything above succeeded.
        $this->installer->markInstalled();

        return new InstallResult($storageLinked, $demoSeeded, $notes);
    }

    private function writeEnv(InstallInput $in): void
    {
        $this->env->set([
            'APP_NAME' => $in->siteName,
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => $in->appUrl,
            'DB_CONNECTION' => $in->dbDriver,
            'DB_HOST' => $in->dbHost,
            'DB_PORT' => (string) $in->dbPort,
            'DB_DATABASE' => $in->dbDatabase,
            'DB_USERNAME' => $in->dbUsername,
            'DB_PASSWORD' => $in->dbPassword,
            'MAIL_FROM_NAME' => $in->siteName,
        ]);
    }

    private function applyDatabaseConfig(InstallInput $in): void
    {
        $conn = $in->dbDriver;

        config([
            'database.default' => $conn,
            "database.connections.{$conn}.driver" => $conn,
            "database.connections.{$conn}.host" => $in->dbHost,
            "database.connections.{$conn}.port" => $in->dbPort,
            "database.connections.{$conn}.database" => $in->dbDatabase,
            "database.connections.{$conn}.username" => $in->dbUsername,
            "database.connections.{$conn}.password" => $in->dbPassword,
        ]);

        DB::purge($conn);
    }

    private function createAdmin(InstallInput $in): User
    {
        $admin = User::updateOrCreate(
            ['email' => $in->adminEmail],
            [
                'username' => $in->adminUsername,
                'name' => $in->adminUsername,
                'display_name' => $in->adminUsername,
                'password' => Hash::make($in->adminPassword), // argon2id (config/hashing.php)
                'status' => 'active',
                'trust_level' => 4,
            ],
        );

        // email_verified_at is guarded (not in User's #[Fillable]) — set it explicitly so the admin can
        // sign in immediately without an email round-trip during install.
        $admin->forceFill(['email_verified_at' => now()])->save();

        // admins = staff/privileged (admin.access via the engine); tl4 = trusted, so no TL0 gating.
        $groups = Group::whereIn('slug', ['admins', 'tl4'])->get();
        $sync = [];
        foreach ($groups as $group) {
            $sync[$group->id] = ['is_primary' => $group->slug === 'admins'];
        }
        $admin->groups()->sync($sync);

        return $admin->refresh();
    }

    private function linkStorage(array &$notes): bool
    {
        try {
            $link = public_path('storage');
            if (file_exists($link)) {
                return true; // already linked (or a real directory) — nothing to do
            }
            Artisan::call('storage:link');

            return file_exists($link);
        } catch (Throwable) {
            $notes[] = 'Could not create the public/storage symlink automatically (some shared hosts '
                .'forbid symlinks). Run `php artisan storage:link`, or copy storage/app/public to '
                .'public/storage, so uploaded avatars and images display.';

            return false;
        }
    }
}
