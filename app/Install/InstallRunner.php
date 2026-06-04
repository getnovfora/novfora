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
        private readonly PublicStorageLinker $storageLinker,
    ) {}

    public function run(InstallInput $in): InstallResult
    {
        if ($this->installer->isInstalled()) {
            throw new \RuntimeException('Hearth is already installed.');
        }

        // Setup-token gate (phase-1.5 F-A): the unauthenticated installer only runs for someone who can read
        // the token file on the server, so a passer-by can't seize a freshly-uploaded site.
        if (! $this->installer->verifyToken($in->setupToken)) {
            throw new \RuntimeException('Invalid or missing installer setup token. Find it in '.$this->installer->tokenPath().' on your server (via FTP or your host\'s file manager).');
        }

        // Fail fast if the lock marker won't be writable. Otherwise a run that migrates the DB and creates
        // the admin but then CANNOT write the marker (step 7) would leave the site fully set up yet
        // UNLOCKED — and an unauthenticated visitor could re-run the installer to mint a second admin.
        // Checking up front means the install either completes-and-locks, or changes nothing.
        $this->assertMarkerWritable();

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

        // The setup token is single-use — drop it now the site is installed (phase-1.5 F-A).
        $this->installer->consumeToken();

        return new InstallResult($storageLinked, $demoSeeded, $notes);
    }

    private function writeEnv(InstallInput $in): void
    {
        $values = [
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
        ];

        // HTTPS-only session cookie when the site is served over TLS (security §4 "HTTPS-only cookies").
        // Left unset for an http:// URL so a non-TLS baseline host isn't locked out of its own session.
        if (str_starts_with(strtolower($in->appUrl), 'https://')) {
            $values['SESSION_SECURE_COOKIE'] = 'true';
        }

        $this->env->set($values);
    }

    /**
     * Ensure the install-lock marker directory exists and is writable before any destructive step, so the
     * installer can always lock at the end (see run()). Uses a throwaway probe write because is_writable()
     * can misreport under some shared-host ACL/owner setups.
     */
    private function assertMarkerWritable(): void
    {
        $dir = \dirname($this->installer->markerPath());

        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Cannot create the install directory ({$dir}). Make storage/ writable (e.g. chmod 775) and try again.");
        }

        $probe = $dir.DIRECTORY_SEPARATOR.'.install-write-probe-'.bin2hex(random_bytes(4));
        if (@file_put_contents($probe, '1', LOCK_EX) === false) {
            throw new \RuntimeException("The install directory ({$dir}) is not writable, so the installer could not lock after finishing. Make storage/ writable (e.g. chmod 775) and try again.");
        }
        @unlink($probe);
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
            ],
        );

        // status, trust_level and email_verified_at are guarded (not in User's #[Fillable]) — set them
        // explicitly: active + fully trusted (no TL0 gating) + email-verified so the admin can sign in
        // immediately without an email round-trip during install.
        $admin->forceFill([
            'status' => 'active',
            'trust_level' => 4,
            'email_verified_at' => now(),
        ])->save();

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
        // Tries a real symlink and, where the host forbids symlinks, falls back to a copy mirror — so
        // avatars/covers display either way (no manual `storage:link` needed on a locked-down shared host).
        $method = $this->storageLinker->publish();

        if ($method === 'copy') {
            $notes[] = 'Your host does not allow symlinks, so public files (avatars, covers) are served from '
                .'a COPY at public/storage. The cron line keeps it refreshed automatically; after bulk '
                .'changes you can also run `php artisan hearth:storage:publish`.';

            return true; // a working copy IS published — just not via a symlink
        }

        if ($method === 'failed') {
            $notes[] = 'Could not publish public/storage automatically (symlinks are disabled and the copy '
                .'fallback failed). Run `php artisan hearth:storage:publish` so uploaded avatars and images display.';

            return false;
        }

        return true; // 'symlink'
    }
}
