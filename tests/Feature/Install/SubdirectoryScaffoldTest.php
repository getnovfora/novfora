<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Install\SubdirectoryScaffold;

/*
| RH-4.3 (ADR-0070) — the Option B generator for no-symlink shared hosts. It must produce a thin index.php
| stub (booting the app from outside the web root), an .htaccess with the right RewriteBase, and build/ +
| storage/ links to the app's SINGLE canonical trees — so a rebuild can never desync the served assets from
| the Vite manifest (G2) and uploads resolve under /community/storage/...
*/

beforeEach(function () {
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'novfora-subdir-'.bin2hex(random_bytes(6));
    $this->appPublic = $root.'/app/public';
    $this->storageSource = $root.'/app/storage/app/public';
    $this->webDir = $root.'/public_html/community';
    $this->root = $root;

    @mkdir($this->appPublic.'/build/assets', 0755, true);
    @mkdir($this->storageSource.'/avatars', 0755, true);
    file_put_contents($this->appPublic.'/index.php', "<?php /* app front controller */\n");
    file_put_contents($this->appPublic.'/.htaccess', "<IfModule mod_rewrite.c>\n    RewriteEngine On\n    RewriteRule ^ index.php [L]\n</IfModule>\n");
    file_put_contents($this->appPublic.'/build/assets/app-abc123.css', 'body{color:red}');
    file_put_contents($this->storageSource.'/avatars/me.png', 'PNG');

    config(['novfora.storage.public_source' => $this->storageSource]);
});

afterEach(function () {
    foreach (['build', 'storage'] as $linked) {
        $p = $this->webDir.'/'.$linked;
        if (is_link($p)) {
            @unlink($p); // remove the symlink, never its target
        }
    }
    // Best-effort recursive cleanup of the temp root (symlinks already removed above).
    $rm = function (string $dir) use (&$rm): void {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $path = $dir.'/'.$f;
            is_dir($path) && ! is_link($path) ? $rm($path) : @unlink($path);
        }
        @rmdir($dir);
    };
    $rm($this->root);
});

it('writes a thin index.php stub that boots the app from outside the web root', function () {
    app(SubdirectoryScaffold::class)->scaffold($this->webDir, '/community', $this->appPublic);

    $stub = file_get_contents($this->webDir.'/index.php');
    expect($stub)->toContain("require '".$this->appPublic."/index.php';");
    expect($stub)->toContain('GENERATED');
});

it('writes an .htaccess carrying RewriteBase for the subpath', function () {
    app(SubdirectoryScaffold::class)->scaffold($this->webDir, '/community', $this->appPublic);

    $htaccess = file_get_contents($this->webDir.'/.htaccess');
    expect($htaccess)->toContain('RewriteBase /community/');
    expect($htaccess)->toContain('index.php [L]'); // preserved the app's front-controller rule
});

it('serves a SINGLE canonical build/ — a file written to the app build is reachable through the subdir (G2)', function () {
    $report = app(SubdirectoryScaffold::class)->scaffold($this->webDir, '/community', $this->appPublic);

    expect($report['build'])->toBeIn(['symlink', 'copy']);
    expect(is_file($this->webDir.'/build/assets/app-abc123.css'))->toBeTrue();
    expect(file_get_contents($this->webDir.'/build/assets/app-abc123.css'))->toBe('body{color:red}');
});

it('links storage/ to the canonical uploads source so avatars resolve under the subpath', function () {
    $report = app(SubdirectoryScaffold::class)->scaffold($this->webDir, '/community', $this->appPublic);

    expect($report['storage'])->toBeIn(['symlink', 'copy']);
    expect(is_file($this->webDir.'/storage/avatars/me.png'))->toBeTrue();
});

it('is idempotent and re-targets RewriteBase when re-run with a different subpath', function () {
    $scaffold = app(SubdirectoryScaffold::class);
    $scaffold->scaffold($this->webDir, '/community', $this->appPublic);
    $scaffold->scaffold($this->webDir, '/forum', $this->appPublic);

    $htaccess = file_get_contents($this->webDir.'/.htaccess');
    expect($htaccess)->toContain('RewriteBase /forum/');
    expect(substr_count($htaccess, 'RewriteBase'))->toBe(1); // not duplicated
    expect(is_file($this->webDir.'/build/assets/app-abc123.css'))->toBeTrue(); // links still valid
});

it('normalises the URL subpath (leading slash, no trailing; root -> empty)', function () {
    $scaffold = app(SubdirectoryScaffold::class);

    expect($scaffold->normaliseBase('community'))->toBe('/community');
    expect($scaffold->normaliseBase('/community/'))->toBe('/community');
    expect($scaffold->normaliseBase('/'))->toBe('');
    expect($scaffold->normaliseBase('apps/community/'))->toBe('/apps/community');
});

it('exposes the generator as the novfora:subdir:scaffold command', function () {
    $this->artisan('novfora:subdir:scaffold', ['webdir' => $this->webDir, '--base' => '/community'])
        ->assertSuccessful();

    expect(is_file($this->webDir.'/index.php'))->toBeTrue();
    expect(is_file($this->webDir.'/.htaccess'))->toBeTrue();
});
