<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Group;
use App\Models\User;
use App\Upgrade\ReleaseCoordinator;
use App\Upgrade\ReleaseFilesystem;
use App\Upgrade\ReleaseRunState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed();
    $this->releaseDir = sys_get_temp_dir().'/novfora-coordinator-'.bin2hex(random_bytes(8));
    app()->instance(ReleaseRunState::class, new ReleaseRunState($this->releaseDir));
});

afterEach(function (): void {
    $lease = $GLOBALS['novfora_release_activity_lease'] ?? null;
    if (is_resource($lease)) {
        flock($lease, LOCK_UN);
        fclose($lease);
    }
    unset($GLOBALS['novfora_release_activity_lease'], $GLOBALS['novfora_release_activity_path']);
    File::deleteDirectory($this->releaseDir);
});

function coordinatorOwner(): User
{
    $owner = Users::withTwoFactor(Users::inGroups(['admins']));
    $owner->groups()->updateExistingPivot((int) Group::where('slug', 'admins')->value('id'), ['is_co_owner' => true]);

    return $owner->fresh();
}

function coordinatorNextVersion(): string
{
    $parts = array_map('intval', explode('.', (string) config('app.version')));
    $parts[2]++;

    return implode('.', $parts);
}

/** @return array{zip:string,sig:string,key:string} */
function coordinatorSignedArtifact(array $extraFiles = []): array
{
    $path = sys_get_temp_dir().'/novfora-coordinator-'.bin2hex(random_bytes(8)).'.zip';
    $files = array_merge([
        'bootstrap/release-upgrade.php' => '<?php // recovery',
        'bootstrap/release-status.php' => '<?php // status',
        'app/CoordinatorProbe.php' => '<?php // new core',
    ], $extraFiles);
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $hashes = [];
    foreach ($files as $name => $body) {
        $zip->addFromString($name, $body);
        $hashes[$name] = hash('sha256', $body);
    }
    $zip->addFromString('release-manifest.json', json_encode([
        'format' => 1, 'version' => coordinatorNextVersion(), 'php' => '>=8.3', 'files' => $hashes,
    ], JSON_THROW_ON_ERROR));
    $zip->close();
    $pair = sodium_crypto_sign_keypair();
    $sig = json_encode([
        'key_id' => 'test',
        'signature' => base64_encode(sodium_crypto_sign_detached(file_get_contents($path), sodium_crypto_sign_secretkey($pair))),
    ], JSON_THROW_ON_ERROR);
    $result = ['zip' => file_get_contents($path), 'sig' => $sig,
        'key' => base64_encode(sodium_crypto_sign_publickey($pair))];
    unlink($path);

    return $result;
}

function fakeCoordinatorRelease(?array $artifact = null): void
{
    $version = coordinatorNextVersion();
    $base = 'https://github.com/getnovfora/novfora/releases/download/v'.$version.'/';
    $responses = ['api.github.com/repos/getnovfora/novfora/releases/latest' => Http::response([
        'draft' => false, 'prerelease' => false, 'tag_name' => 'v'.$version,
        'published_at' => '2026-11-06T00:00:00Z',
        'assets' => [
            ['name' => 'novfora-release.zip', 'browser_download_url' => $base.'novfora-release.zip'],
            ['name' => 'novfora-release.zip.sig', 'browser_download_url' => $base.'novfora-release.zip.sig'],
        ],
    ])];
    if ($artifact !== null) {
        $responses[$base.'novfora-release.zip'] = Http::response($artifact['zip']);
        $responses[$base.'novfora-release.zip.sig'] = Http::response($artifact['sig']);
    }
    Http::fake($responses);
}

it('requires a two factor co-owner to request a release', function (): void {
    config(['releases.trusted_keys' => ['test' => base64_encode(str_repeat('x', 32))]]);
    config(['app.url' => 'https://example.test/community']);
    fakeCoordinatorRelease();
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));
    expect(fn () => app(ReleaseCoordinator::class)->request($admin))->toThrow(HttpException::class);
    Http::assertNothingSent();
    $owner = coordinatorOwner();
    $run = app(ReleaseCoordinator::class)->request($owner);
    expect($run['phase'])->toBe('requested')
        ->and($run['actor_id'])->toBe((int) $owner->id)
        ->and($run['recovery_token'])->toMatch('/^[a-f0-9]{64}$/');
    $persisted = app(ReleaseRunState::class)->read();
    expect($persisted)->not->toHaveKey('recovery_token')
        ->and($persisted['recovery_hash'])->toBe(hash('sha256', $run['recovery_token']))
        ->and($persisted['recovery_run_id'])->toBe($run['run_id'])
        ->and($persisted['recovery_expires_at'])->toBeGreaterThan(time() + 7100);
    $cookie = Cookie::queued('novfora_release_recovery');
    expect($cookie->getValue())->toBe($run['recovery_token'])
        ->and($cookie->isSecure())->toBeTrue()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('strict')
        ->and($cookie->getPath())->toBe('/community/');
});

it('refuses non-revalidating web OPcache while ignoring separate CLI settings', function (): void {
    expect(ReleaseCoordinator::webOpcacheRevalidationSeconds('cli', true, false, 120))->toBe(0)
        ->and(ReleaseCoordinator::webOpcacheRevalidationSeconds('fpm-fcgi', false, false, 120))->toBe(0)
        ->and(ReleaseCoordinator::webOpcacheRevalidationSeconds('fpm-fcgi', true, true, 3))->toBe(3);
    expect(fn () => ReleaseCoordinator::webOpcacheRevalidationSeconds('fpm-fcgi', true, false, 3))
        ->toThrow(RuntimeException::class, 'timestamp validation is disabled');
    expect(fn () => ReleaseCoordinator::webOpcacheRevalidationSeconds('fpm-fcgi', true, true, -1))
        ->toThrow(RuntimeException::class, 'revalidation interval is invalid');
});

it('rechecks owner authority before downloading or modifying live files', function (): void {
    config(['releases.trusted_keys' => ['test' => base64_encode(str_repeat('x', 32))]]);
    fakeCoordinatorRelease();
    $owner = coordinatorOwner();
    app(ReleaseCoordinator::class)->request($owner);
    $owner->groups()->updateExistingPivot((int) Group::where('slug', 'admins')->value('id'), ['is_co_owner' => false]);
    $run = app(ReleaseCoordinator::class)->advance();
    expect($run['phase'])->toBe('failed')
        ->and($run)->not->toHaveKey('backup')
        ->and($run)->not->toHaveKey('data_backup');
    Http::assertSentCount(1); // discovery only
});

it('authenticates and stages a signed release before building the code snapshot plan', function (): void {
    $artifact = coordinatorSignedArtifact();
    config(['releases.trusted_keys' => ['test' => $artifact['key']]]);
    config(['novfora.backup.path' => $this->releaseDir.'/data-backups']);
    fakeCoordinatorRelease($artifact);
    $owner = coordinatorOwner();
    app(ReleaseCoordinator::class)->request($owner);
    // BackupService requires a file-backed SQLite path. The active test connection remains in memory;
    // this fixture exercises the coordinator's stage/snapshot handoff, not database dump contents.
    file_put_contents($this->releaseDir.'/database.sqlite', 'fixture');
    config(['database.connections.sqlite.database' => $this->releaseDir.'/database.sqlite']);
    $run = app(ReleaseCoordinator::class)->advance();
    expect($run['phase'])->toBe('snapshotting')
        ->and($run['snapshot_after'])->toBeGreaterThan(time() + 50)
        ->and($run)->not->toHaveKey('data_backup')
        ->and($run)->not->toHaveKey('backup');
    $run['snapshot_after'] = time() - 1; // Simulate the cron tick after the production drain window.
    app(ReleaseRunState::class)->locked(fn () => app(ReleaseRunState::class)->write($run));
    $run = app(ReleaseCoordinator::class)->advance();
    expect($run['phase'])->toBe('applying')
        ->and($run['plan'])->toBeArray()
        ->and($run['files'])->toHaveKey('bootstrap/release-upgrade.php')
        ->and(file_get_contents($run['stage'].'/app/CoordinatorProbe.php'))->toBe('<?php // new core')
        ->and(file_exists(base_path('app/CoordinatorProbe.php')))->toBeFalse();
    expect($run['data_backup'])->toBe($this->releaseDir.'/runs/'.$run['run_id'].'/data-backup.zip');
    $savedHash = hash_file('sha256', $run['data_backup']);
    // Simulate another backup invoking an aggressive retention policy during the migration phase.
    foreach (glob($this->releaseDir.'/data-backups/novfora-*.zip') as $archive) {
        unlink($archive);
    }
    expect(is_file($run['data_backup']))->toBeTrue()
        ->and(hash_file('sha256', $run['data_backup']))->toBe($savedHash);
});

it('waits for an admitted long-lived request before snapshotting its final code write', function (): void {
    $artifact = coordinatorSignedArtifact();
    config(['releases.trusted_keys' => ['test' => $artifact['key']]]);
    config(['novfora.backup.path' => $this->releaseDir.'/data-backups']);
    fakeCoordinatorRelease($artifact);
    app(ReleaseCoordinator::class)->request(coordinatorOwner());
    file_put_contents($this->releaseDir.'/database.sqlite', 'fixture');
    config(['database.connections.sqlite.database' => $this->releaseDir.'/database.sqlite']);
    $run = app(ReleaseCoordinator::class)->advance();
    expect($run['phase'])->toBe('snapshotting');
    $run['snapshot_after'] = time() - 1;
    app(ReleaseRunState::class)->locked(fn () => app(ReleaseRunState::class)->write($run));

    $liveData = $this->releaseDir.'/database.sqlite';
    $lease = $this->releaseDir.'/activity.lock';
    $script = '$h=fopen('.var_export($lease, true).',"c");flock($h,LOCK_SH);'
        .'file_put_contents('.var_export($liveData, true).',"late database write");'
        .'echo "READY\\n";flush();sleep(20);';
    $holder = new Process([PHP_BINARY, '-r', $script]);
    try {
        $holder->start();
        $holder->waitUntil(fn ($type, $output) => str_contains($holder->getOutput(), 'READY'));
        $deferred = app(ReleaseCoordinator::class)->advance();
        expect($deferred['phase'])->toBe('snapshotting')
            ->and($deferred)->not->toHaveKey('data_backup')
            ->and($deferred)->not->toHaveKey('plan');
        $holder->stop(1);
        $applied = app(ReleaseCoordinator::class)->advance();
        $archive = new ZipArchive;
        expect($archive->open($applied['data_backup']))->toBeTrue();
        $captured = $archive->getFromName('database.sqlite');
        $archive->close();
        expect($applied['phase'])->toBe('applying')
            ->and($captured)->toBe('late database write');
    } finally {
        if ($holder->isRunning()) {
            $holder->stop(1);
        }
    }
});

it('rejects a signed release with unsupported extension files before maintenance or backup', function (): void {
    $artifact = coordinatorSignedArtifact(['modules/novfora/newfeature/module.json' => '{"name":"newfeature"}']);
    config(['releases.trusted_keys' => ['test' => $artifact['key']]]);
    fakeCoordinatorRelease($artifact);
    $owner = coordinatorOwner();
    app(ReleaseCoordinator::class)->request($owner);
    $run = app(ReleaseCoordinator::class)->advance();
    expect($run['phase'])->toBe('failed')
        ->and($run['error'])->toContain('unsupported module or theme layout')
        ->and($run)->not->toHaveKey('snapshot_after')
        ->and($run)->not->toHaveKey('data_backup')
        ->and($run)->not->toHaveKey('plan');
});

it('removes obsolete release assets from a copied public layout and can restore them', function (): void {
    $root = $this->releaseDir.'/app-root';
    $served = $this->releaseDir.'/served-community';
    $stage = $this->releaseDir.'/stage';
    foreach ([$root.'/public', $served.'/build', $stage.'/public/build'] as $directory) {
        mkdir($directory, 0755, true);
    }
    file_put_contents($served.'/sw.js', 'old release worker');
    file_put_contents($served.'/custom.js', 'host custom file');
    file_put_contents($served.'/index.php', 'host routing');
    file_put_contents($served.'/.htaccess', 'RewriteBase /community/');
    file_put_contents($stage.'/public/build/app.js', 'new release asset');

    $run = [
        'root' => $root,
        'public_dir' => $served,
        'stage' => $stage,
        'files' => ['public/build/app.js' => hash_file('sha256', $stage.'/public/build/app.js')],
    ];
    $coordinator = app(ReleaseCoordinator::class);
    $method = new ReflectionMethod(ReleaseCoordinator::class, 'preparePublicMirror');
    $method->invokeArgs($coordinator, [&$run, $this->releaseDir, ['public/sw.js']]);

    $mirror = new ReleaseFilesystem(publicOnly: true);
    expect($run['public_plan'])->toContain(['path' => 'sw.js', 'existed' => true,
        'old_hash' => hash('sha256', 'old release worker'), 'new_hash' => null]);
    $mirror->apply($served, $run['public_stage'], $run['public_plan']);
    expect(file_exists($served.'/sw.js'))->toBeFalse()
        ->and(file_get_contents($served.'/build/app.js'))->toBe('new release asset')
        ->and(file_get_contents($served.'/custom.js'))->toBe('host custom file')
        ->and(file_get_contents($served.'/index.php'))->toBe('host routing')
        ->and(file_get_contents($served.'/.htaccess'))->toBe('RewriteBase /community/');

    $mirror->rollback($served, $run['public_backup'], $run['public_plan']);
    expect(file_get_contents($served.'/sw.js'))->toBe('old release worker');
});

it('lists only known first-party module and theme files for legacy deletion', function (): void {
    $root = $this->releaseDir.'/legacy';
    foreach (['modules/novfora/qa', 'modules/novfora/populate', 'modules/acme/custom',
        'themes/aurora', 'themes/custom'] as $tree) {
        mkdir($root.'/'.$tree, 0755, true);
        file_put_contents($root.'/'.$tree.'/old.php', 'legacy file');
    }

    $method = new ReflectionMethod(ReleaseCoordinator::class, 'obsoleteFiles');
    $obsolete = $method->invoke(app(ReleaseCoordinator::class), $root, []);
    expect($obsolete)->toContain('modules/novfora/qa/old.php', 'themes/aurora/old.php')
        ->not->toContain('modules/novfora/populate/old.php', 'modules/acme/custom/old.php', 'themes/custom/old.php');
});
