<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Install\HostDoctor;
use App\Install\PublicStorageLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| novfora:doctor preflight + the copy-based public-storage fallback (phase-1.5). The doctor must surface the
| shared-host gotchas and stay "green" (no hard fails) on a healthy host; the storage linker must fall back
| to copying when symlinks are unavailable so avatars/covers display regardless.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function rmrf(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

it('runs novfora:doctor and exits zero on a healthy host', function () {
    $this->artisan('novfora:doctor')->assertExitCode(0);
});

it('surfaces the shared-host probes', function () {
    $names = collect(app(HostDoctor::class)->run()['checks'])->pluck('name')->all();

    expect($names)->toContain('Disabled PHP functions')
        ->toContain('Symlink support (storage:link)')
        ->toContain('open_basedir')
        ->toContain('File permissions (group/world-writable)')
        ->toContain('Database backups')
        ->toContain('Queue driver')
        ->toContain('Outbound mail')
        ->toContain('Cron (schedule:run)');

    expect(app(HostDoctor::class)->run()['ok'])->toBeTrue(); // warns are fine; no hard fails in this env
});

it('falls back to copying public storage when symlinks are unavailable', function () {
    $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'novfora-storage-'.bin2hex(random_bytes(4));
    $source = $base.DIRECTORY_SEPARATOR.'app-public';
    $link = $base.DIRECTORY_SEPARATOR.'public-storage';
    @mkdir($source.DIRECTORY_SEPARATOR.'avatars', 0775, true);
    file_put_contents($source.DIRECTORY_SEPARATOR.'avatars'.DIRECTORY_SEPARATOR.'a.txt', 'hello');

    config(['novfora.storage.use_symlink' => false]); // force the no-symlink host condition

    try {
        $method = app(PublicStorageLinker::class)->linkPaths($source, $link);

        expect($method)->toBe('copy');
        expect(is_file($link.DIRECTORY_SEPARATOR.'avatars'.DIRECTORY_SEPARATOR.'a.txt'))->toBeTrue();
        expect(file_get_contents($link.DIRECTORY_SEPARATOR.'avatars'.DIRECTORY_SEPARATOR.'a.txt'))->toBe('hello');
    } finally {
        rmrf($base);
    }
});

it('flags group/world-writable application files (the suEXEC/CloudLinux 500 cause)', function () {
    if (DIRECTORY_SEPARATOR === '\\') {
        $this->markTestSkipped('POSIX permission bits only.');
    }

    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'novfora-perm-'.bin2hex(random_bytes(4));
    @mkdir($dir, 0755, true);
    $safe = $dir.DIRECTORY_SEPARATOR.'safe.php';
    $lax = $dir.DIRECTORY_SEPARATOR.'lax.php';
    file_put_contents($safe, '<?php');
    file_put_contents($lax, '<?php');
    chmod($safe, 0644);
    chmod($lax, 0777);

    try {
        $offenders = app(HostDoctor::class)->laxPermissionOffenders([$safe, $lax]);

        expect($offenders)->toHaveCount(1);
        expect($offenders[0])->toContain('lax.php')->toContain('0777');
    } finally {
        @unlink($safe);
        @unlink($lax);
        @rmdir($dir);
    }
});

it('creates a real symlink where the host allows it', function () {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('symlink() needs privilege on Windows');
    }

    $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'novfora-storage-'.bin2hex(random_bytes(4));
    $source = $base.DIRECTORY_SEPARATOR.'app-public';
    $link = $base.DIRECTORY_SEPARATOR.'public-storage';
    @mkdir($source, 0775, true);

    try {
        expect(app(PublicStorageLinker::class)->linkPaths($source, $link))->toBe('symlink');
        expect(is_link($link))->toBeTrue();
    } finally {
        @unlink($link);
        rmrf($base);
    }
});
