<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Upgrade\ReleaseFilesystem;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->releaseDirectory = sys_get_temp_dir().'/novfora-files-'.bin2hex(random_bytes(8));
    foreach (['live/app', 'stage/app', 'backup'] as $directory) {
        mkdir($this->releaseDirectory.'/'.$directory, 0700, true);
    }
    $this->live = $this->releaseDirectory.'/live';
    $this->stage = $this->releaseDirectory.'/stage';
    $this->backup = $this->releaseDirectory.'/backup';
    $this->files = new ReleaseFilesystem;
});

afterEach(function () {
    File::deleteDirectory($this->releaseDirectory);
});

it('can resume a partially applied release and restore the exact previous files', function () {
    file_put_contents($this->live.'/app/Existing.php', 'old');
    file_put_contents($this->live.'/app/Obsolete.php', 'obsolete');
    file_put_contents($this->stage.'/app/Existing.php', 'new');
    file_put_contents($this->stage.'/app/Added.php', 'added');
    $files = ['app/Existing.php' => hash('sha256', 'new'), 'app/Added.php' => hash('sha256', 'added')];
    $plan = $this->files->snapshot($this->live, $this->stage, $this->backup, $files, ['app/Obsolete.php']);
    // Emulate a killed worker after its first durable replacement, then a fresh process resuming.
    $this->files->apply($this->live, $this->stage, array_slice($plan, 0, 1));
    (new ReleaseFilesystem)->apply($this->live, $this->stage, $plan);
    expect(file_get_contents($this->live.'/app/Existing.php'))->toBe('new')
        ->and(file_get_contents($this->live.'/app/Added.php'))->toBe('added')
        ->and(file_exists($this->live.'/app/Obsolete.php'))->toBeFalse();
    (new ReleaseFilesystem)->rollback($this->live, $this->backup, $plan);
    expect(file_get_contents($this->live.'/app/Existing.php'))->toBe('old')
        ->and(file_get_contents($this->live.'/app/Obsolete.php'))->toBe('obsolete')
        ->and(file_exists($this->live.'/app/Added.php'))->toBeFalse();
});

it('never includes settings uploads sqlite data routing or private modules in its mutation plan', function () {
    $protected = ['.env', 'storage/app/upload.jpg', 'database/database.sqlite', 'public/storage/avatar.jpg',
        'public/.htaccess', 'modules/novfora/populate/private.php', 'themes/custom/theme.json'];
    foreach ($protected as $path) {
        File::ensureDirectoryExists(dirname($this->live.'/'.$path));
        file_put_contents($this->live.'/'.$path, 'keep');
    }
    $plan = $this->files->snapshot($this->live, $this->stage, $this->backup,
        array_fill_keys($protected, hash('sha256', 'replace')), $protected);
    expect($plan)->toBe([]);
    foreach ($protected as $path) {
        expect(file_get_contents($this->live.'/'.$path))->toBe('keep');
    }
});

it('refuses symlink escapes and a corrupted stage before changing live code', function () {
    file_put_contents($this->live.'/app/Existing.php', 'old');
    file_put_contents($this->stage.'/app/Existing.php', 'tampered');
    expect(fn () => $this->files->snapshot($this->live, $this->stage, $this->backup,
        ['app/Existing.php' => hash('sha256', 'expected')]))->toThrow(RuntimeException::class, 'integrity');
    symlink($this->backup, $this->live.'/app/escape');
    expect(fn () => $this->files->path($this->live, 'app/escape/payload.php'))
        ->toThrow(RuntimeException::class, 'symbolic link');
    expect(file_get_contents($this->live.'/app/Existing.php'))->toBe('old');
});

it('validates the whole recovery snapshot before rolling back any file', function () {
    foreach (['One.php', 'Two.php'] as $name) {
        file_put_contents($this->live.'/app/'.$name, 'old');
        file_put_contents($this->stage.'/app/'.$name, 'new');
    }
    $plan = $this->files->snapshot($this->live, $this->stage, $this->backup,
        ['app/One.php' => hash('sha256', 'new'), 'app/Two.php' => hash('sha256', 'new')]);
    $this->files->apply($this->live, $this->stage, $plan);
    file_put_contents($this->backup.'/app/Two.php', 'corrupt');
    expect(fn () => $this->files->rollback($this->live, $this->backup, $plan))
        ->toThrow(RuntimeException::class, 'integrity');
    expect(file_get_contents($this->live.'/app/One.php'))->toBe('new');
});

it('preserves site data and host routing while publishing core files on a first managed upgrade', function () {
    foreach (['.env', 'database/database.sqlite', 'storage/app/public/avatar.jpg', 'public/.htaccess',
        'modules/novfora/populate/private.php', 'themes/custom/theme.json'] as $relative) {
        File::ensureDirectoryExists(dirname($this->live.'/'.$relative));
        file_put_contents($this->live.'/'.$relative, 'local');
    }
    File::ensureDirectoryExists($this->live.'/public/build');
    File::ensureDirectoryExists($this->stage.'/public/build');
    file_put_contents($this->live.'/public/build/app.js', 'old asset');
    file_put_contents($this->stage.'/public/build/app.js', 'new asset');
    file_put_contents($this->stage.'/app/Core.php', 'new core');
    $files = ['app/Core.php' => hash('sha256', 'new core'), 'public/build/app.js' => hash('sha256', 'new asset')];
    $plan = $this->files->snapshot($this->live, $this->stage, $this->backup, $files);
    $this->files->apply($this->live, $this->stage, $plan);
    expect(file_get_contents($this->live.'/app/Core.php'))->toBe('new core')
        ->and(file_get_contents($this->live.'/public/build/app.js'))->toBe('new asset');
    foreach (['.env', 'database/database.sqlite', 'storage/app/public/avatar.jpg', 'public/.htaccess',
        'modules/novfora/populate/private.php', 'themes/custom/theme.json'] as $relative) {
        expect(file_get_contents($this->live.'/'.$relative))->toBe('local');
    }
});

it('updates a separate served public mirror without touching its routing or uploads', function () {
    $served = $this->releaseDirectory.'/served';
    $servedBackup = $this->releaseDirectory.'/served-backup';
    mkdir($served.'/build', 0700, true);
    mkdir($served.'/storage', 0700, true);
    file_put_contents($served.'/index.php', 'host routing');
    file_put_contents($served.'/.htaccess', 'host rules');
    file_put_contents($served.'/storage/avatar.jpg', 'upload');
    file_put_contents($served.'/build/app.js', 'old asset');
    mkdir($this->stage.'/public/build', 0700, true);
    file_put_contents($this->stage.'/public/build/app.js', 'new asset');
    $mirror = new ReleaseFilesystem(publicOnly: true);
    $plan = $mirror->snapshot($served, $this->stage.'/public', $servedBackup,
        ['build/app.js' => hash('sha256', 'new asset'), 'index.php' => hash('sha256', 'replacement')]);
    $mirror->apply($served, $this->stage.'/public', $plan);
    expect(file_get_contents($served.'/build/app.js'))->toBe('new asset')
        ->and(file_get_contents($served.'/index.php'))->toBe('host routing')
        ->and(file_get_contents($served.'/.htaccess'))->toBe('host rules')
        ->and(file_get_contents($served.'/storage/avatar.jpg'))->toBe('upload');
});
