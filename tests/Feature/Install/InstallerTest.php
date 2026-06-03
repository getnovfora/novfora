<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Install\DatabaseVerifier;
use App\Install\EnvWriter;
use App\Install\Installer;
use App\Install\InstallInput;
use App\Install\InstallRunner;
use App\Install\RequirementChecker;
use App\Models\Forum;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

/*
| The no-SSH web installer (M5) — security-sensitive. An unauthenticated pre-install surface that writes
| .env, runs migrations, and creates the first admin, so these tests pin: the LOCK (refuses to re-run
| once installed; no admin-reset vector), input validation, the redirect-to-installer behaviour, and
| that credentials never leak into messages or markers.
|
| Each test runs in an isolated sandbox (temp .env + marker + sqlite file) so nothing touches the real
| environment, and installer enforcement — disabled for the suite at large — is switched back ON here.
*/

/** @return array{0:string,1:string,2:string,3:string} [dir, envPath, markerPath, sqlitePath] */
function installSandbox(): array
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hearth-install-'.bin2hex(random_bytes(6));
    @mkdir($dir, 0775, true);
    $env = $dir.DIRECTORY_SEPARATOR.'.env';
    $marker = $dir.DIRECTORY_SEPARATOR.'installed';
    $db = $dir.DIRECTORY_SEPARATOR.'database.sqlite';
    touch($db);

    config([
        'hearth.install.enforce' => true,
        'hearth.install.env_path' => $env,
        'hearth.install.marker' => $marker,
    ]);

    return [$dir, $env, $marker, $db];
}

function sampleInput(string $sqlitePath, bool $demo = false): InstallInput
{
    return new InstallInput(
        siteName: 'Test Forum',
        appUrl: 'https://forum.test',
        dbDriver: 'sqlite',
        dbHost: '',
        dbPort: 0,
        dbDatabase: $sqlitePath,
        dbUsername: '',
        dbPassword: '',
        adminUsername: 'rootadmin',
        adminEmail: 'admin@forum.test',
        adminPassword: 'Sup3rSecret!!',
        seedDemo: $demo,
    );
}

// ── The lock ───────────────────────────────────────────────────────────────────────────────────────

it('is not installed until the marker exists, then locks (and the marker holds no secrets)', function () {
    [, , $marker] = installSandbox();
    $installer = app(Installer::class);

    expect($installer->isInstalled())->toBeFalse();
    expect($installer->shouldEnforce())->toBeTrue();

    $installer->markInstalled();

    expect($installer->isInstalled())->toBeTrue();
    expect($installer->shouldEnforce())->toBeFalse();            // installed → no longer enforced
    expect(strtolower(file_get_contents($marker)))->not->toContain('password');
});

it('serves the installer when not installed, and 403s once installed (no re-trigger)', function () {
    installSandbox();

    $this->get('/install')->assertOk()->assertSee('System check');

    app(Installer::class)->markInstalled();

    $this->get('/install')->assertForbidden();                  // the lock — EnsureNotInstalled
});

it('redirects every other request to the installer until installed', function () {
    installSandbox();

    $this->get('/forums')->assertRedirect(route('install'));
    $this->get('/install')->assertOk();                         // the wizard itself is allow-listed
    $this->get('/health')->assertOk();                          // health works pre-install too
});

// ── Requirement / env / DB probes ────────────────────────────────────────────────────────────────

it('passes the host requirement checklist in a supported environment', function () {
    $req = app(RequirementChecker::class)->run();

    expect($req['checks'])->not->toBeEmpty();
    expect($req['ok'])->toBeTrue();
    expect(collect($req['checks'])->where('status', 'fail'))->toBeEmpty();
});

it('writes env keys surgically and preserves the rest of the file', function () {
    [, $env] = installSandbox();
    $writer = app(EnvWriter::class);
    $writer->ensureExists();                                     // copies .env.example
    $writer->set(['APP_NAME' => 'Hearth Demo', 'DB_DATABASE' => 'mydb']);

    $contents = file_get_contents($env);
    expect($contents)->toContain('APP_NAME="Hearth Demo"');     // quoted (contains a space)
    expect($contents)->toContain('DB_DATABASE=mydb');
    expect($contents)->toContain('SCOUT_DRIVER=database');      // an untouched example key survives
});

it('generates and persists an APP_KEY when missing, applying it to the running config', function () {
    [, $env] = installSandbox();
    config(['app.key' => '']);                                  // simulate a fresh, keyless upload

    $key = app(EnvWriter::class)->ensureAppKey();

    expect($key)->not->toBe('');
    expect(file_get_contents($env))->toContain('APP_KEY=base64:');
    expect(config('app.key'))->not->toBe('');
});

it('verifies a good DB connection and rejects a bad one without leaking the password', function () {
    [, , , $db] = installSandbox();
    $verifier = app(DatabaseVerifier::class);

    expect($verifier->verify('sqlite', '', 0, $db, '', '')['ok'])->toBeTrue();

    $bad = $verifier->verify('mysql', '127.0.0.1', 1, 'absent', 'user', 'SE3cretPW');
    expect($bad['ok'])->toBeFalse();
    expect($bad['message'])->not->toContain('SE3cretPW');       // never echo the credential
});

// ── The full install sequence + idempotent re-run guard ──────────────────────────────────────────

it('runs the full install — env, migrate, seed, admin — then locks; a re-run is refused', function () {
    [, $env, $marker, $db] = installSandbox();

    $result = app(InstallRunner::class)->run(sampleInput($db));

    // 7. the marker is written last → installed + locked.
    expect(app(Installer::class)->isInstalled())->toBeTrue();
    expect(file_exists($marker))->toBeTrue();
    expect($result->demoSeeded)->toBeFalse();

    // 5. the first admin: staff, trusted, email-verified, password hashed (argon2id) + verifiable.
    $admin = User::where('email', 'admin@forum.test')->first();
    expect($admin)->not->toBeNull();
    expect($admin->groups->pluck('slug')->all())->toContain('admins')->toContain('tl4');
    expect($admin->isStaff())->toBeTrue();
    expect($admin->email_verified_at)->not->toBeNull();
    expect($admin->trust_level)->toBe(4);                       // set via forceFill (guarded), not mass assignment
    expect($admin->status)->toBe('active');
    expect($admin->password)->not->toBe('Sup3rSecret!!');       // not stored in plaintext
    expect(Hash::check('Sup3rSecret!!', $admin->password))->toBeTrue();

    // 2. secrets persisted to .env (DB password lives here by necessity — that's the file's job).
    $envContents = file_get_contents($env);
    expect($envContents)->toContain('APP_NAME="Test Forum"');
    expect($envContents)->toContain('APP_KEY=base64:');
    expect($envContents)->toContain('SESSION_SECURE_COOKIE=true'); // https APP_URL → HTTPS-only cookie

    // .env holds the APP_KEY + DB password → it must not be world-readable (0600). chmod is a no-op on Windows.
    if (PHP_OS_FAMILY !== 'Windows') {
        expect(fileperms($env) & 0777)->toBe(0600);
    }

    // The lock: the runner itself refuses a second run even if the routes were somehow bypassed.
    expect(fn () => app(InstallRunner::class)->run(sampleInput($db)))->toThrow(RuntimeException::class);
});

it('refuses to run — writing nothing — when the install lock directory is not writable', function () {
    [$dir, $env, , $db] = installSandbox();

    // Point the marker at a path whose "directory" is actually a regular file: it can be neither created
    // nor written, the unwritable-storage case a shared host can present. The runner must bail BEFORE any
    // destructive step, so a half-built-yet-unlocked site (re-runnable installer → second admin) is impossible.
    $blocker = $dir.DIRECTORY_SEPARATOR.'not-a-dir';
    touch($blocker);
    config(['hearth.install.marker' => $blocker.DIRECTORY_SEPARATOR.'installed']);

    expect(fn () => app(InstallRunner::class)->run(sampleInput($db)))->toThrow(RuntimeException::class);

    expect(app(Installer::class)->isInstalled())->toBeFalse();
    expect(is_file($env))->toBeFalse();                          // .env was never written → nothing ran
});

it('does not force a secure cookie for a plain-http site URL', function () {
    [, $env, , $db] = installSandbox();

    app(InstallRunner::class)->run(new InstallInput(
        siteName: 'Plain', appUrl: 'http://forum.test', dbDriver: 'sqlite',
        dbHost: '', dbPort: 0, dbDatabase: $db, dbUsername: '', dbPassword: '',
        adminUsername: 'httpadmin', adminEmail: 'http@forum.test', adminPassword: 'Sup3rSecret!!',
    ));

    // No ACTIVE secure-cookie line for an http site (the commented hint from .env.example may remain).
    expect(file_get_contents($env))->not->toMatch('/^SESSION_SECURE_COOKIE=true/m');
});

it('seeds the demo community when asked', function () {
    [, , , $db] = installSandbox();

    app(InstallRunner::class)->run(sampleInput($db, demo: true));

    expect(Forum::where('slug', 'announcements')->exists())->toBeTrue();
    expect(Topic::count())->toBeGreaterThan(0);
});

// ── The wizard component (UI path) ───────────────────────────────────────────────────────────────

it('drives the wizard to completion, clears passwords from state, and locks against a second run', function () {
    [, , , $db] = installSandbox();

    Livewire::test('installer.wizard')
        ->set('dbDriver', 'sqlite')
        ->set('dbDatabase', $db)
        ->set('siteName', 'Wizard Forum')
        ->set('appUrl', 'https://wiz.test')
        ->set('adminUsername', 'wizadmin')
        ->set('adminEmail', 'wiz@test.test')
        ->set('adminPassword', 'Sup3rSecret!!')
        ->set('passwordConfirmation', 'Sup3rSecret!!')
        ->set('seedDemo', false)
        ->call('runInstall')
        ->assertSet('step', 5)
        ->assertSet('adminPassword', '')                        // wiped from component state post-install
        ->assertSet('dbPassword', '');

    expect(app(Installer::class)->isInstalled())->toBeTrue();

    // Once installed, even a fresh wizard instance refuses to run (defence in depth at the action).
    Livewire::test('installer.wizard')->call('runInstall')->assertStatus(403);
});

it('rejects weak or incomplete admin input before doing anything', function () {
    installSandbox();

    Livewire::test('installer.wizard')
        ->set('dbDriver', 'sqlite')
        ->set('dbDatabase', ':memory:')
        ->set('siteName', 'X')
        ->set('appUrl', 'not-a-url')
        ->set('adminUsername', 'ab')                            // too short
        ->set('adminEmail', 'nope')                             // invalid
        ->set('adminPassword', 'weak')                          // fails the policy
        ->set('passwordConfirmation', 'different')
        ->call('runInstall')
        ->assertHasErrors(['appUrl', 'adminUsername', 'adminEmail', 'adminPassword']);

    expect(app(Installer::class)->isInstalled())->toBeFalse();  // nothing ran
});
