<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Support\Http\BasePathDetector;
use Illuminate\Http\Request;

/*
| RH-4.2 (ADR-0070, APEX) — the request-time base-path detector for subdirectory installs.
|
| It must force the URL/asset root to the mount prefix ONLY when APP_URL is unset/localhost AND the request
| genuinely arrived under a subpath — and be a strict NO-OP for the root/subdomain layout and whenever a real
| APP_URL is configured. These pin both the "make /community work" path and the conservative fences (G4 + the
| never-override-a-real-APP_URL rule), and prove all three URL surfaces (route(), asset()/@vite, Livewire's
| update URI) carry a SINGLE /community prefix once forced — never a doubled /community/community/.
*/

/** Build a request as a subdirectory front controller sees it, and bind it like the real boot() flow does. */
function bootDetector(string $uri, string $scriptName, string $appUrl = 'http://localhost'): string
{
    $request = Request::create($uri, 'GET', [], [], [], [
        'SCRIPT_NAME' => $scriptName,
        'SCRIPT_FILENAME' => '/var/www'.$scriptName,
        'REQUEST_URI' => $uri,
    ]);

    // Mirror the real request lifecycle: the URL generator resolves URLs against the current request.
    app()->instance('request', $request);
    app('url')->setRequest($request);

    return app(BasePathDetector::class)->apply($request, $appUrl);
}

it('forces the /community root when APP_URL is localhost and the request is under the subpath', function () {
    expect(bootDetector('/community/install', '/community/index.php'))->toBe('/community');
});

it('carries a SINGLE subpath prefix across ALL THREE url surfaces (route, asset/@vite, Livewire)', function () {
    bootDetector('/community/install', '/community/index.php');

    // 1) route()/url() — forums.index lives at '/', so it generates the mount root itself (RH-4.1b).
    expect(url('/foo'))->toBe('http://localhost/community/foo');
    expect(route('forums.index'))->toBe('http://localhost/community');

    // 2) @vite/asset() — inherits forcedRoot via UrlGenerator::formatRoot() (assetRoot is null, no ASSET_URL).
    expect(asset('build/assets/app.css'))->toBe('http://localhost/community/build/assets/app.css');

    // 3) Livewire's hashed update endpoint — url(getUpdateUri()) is what the browser POSTs to. It MUST carry
    //    exactly one /community (the doubled-prefix bug is the headline RH-4 symptom this guards against).
    $updateUri = url(app('livewire')->getUpdateUri());
    expect($updateUri)->toStartWith('http://localhost/community/livewire-');
    expect($updateUri)->toEndWith('/update');
    expect(substr_count($updateUri, '/community/'))->toBe(1);
});

it('treats localhost with a port and loopback IPs (and empty) as "unset/localhost"', function () {
    expect(bootDetector('/community/x', '/community/index.php', 'http://localhost:8080'))->toBe('/community');
    expect(bootDetector('/community/x', '/community/index.php', 'http://127.0.0.1'))->toBe('/community');
    expect(bootDetector('/community/x', '/community/index.php', ''))->toBe('/community');
});

it('NEVER overrides a real configured APP_URL (the conservative fence)', function () {
    expect(bootDetector('/community/install', '/community/index.php', 'https://forum.example.com'))->toBe('');
});

it('is a strict NO-OP for the root/subdomain layout (G4 — front controller at the docroot root)', function () {
    expect(bootDetector('/install', '/index.php'))->toBe('');
    expect(url('/foo'))->toBe('http://localhost/foo'); // unchanged — no subpath forced
    expect(route('forums.index'))->toBe('http://localhost'); // the canonical home at the bare root
});

it('detects a nested mount prefix (/apps/community) and keeps a single prefix', function () {
    expect(bootDetector('/apps/community/install', '/apps/community/index.php'))->toBe('/apps/community');

    expect(route('forums.index'))->toBe('http://localhost/apps/community');
    $updateUri = url(app('livewire')->getUpdateUri());
    expect($updateUri)->toStartWith('http://localhost/apps/community/livewire-');
    expect(substr_count($updateUri, '/apps/community/'))->toBe(1);
});

it('reports the prefix from the request base path (the SCRIPT_NAME/RewriteBase-derived mount)', function () {
    $detector = app(BasePathDetector::class);

    expect($detector->detectPrefix(Request::create('/community/x', 'GET', [], [], [], [
        'SCRIPT_NAME' => '/community/index.php',
        'SCRIPT_FILENAME' => '/var/www/community/index.php',
        'REQUEST_URI' => '/community/x',
    ])))->toBe('/community');

    expect($detector->detectPrefix(Request::create('/x', 'GET', [], [], [], [
        'SCRIPT_NAME' => '/index.php',
        'SCRIPT_FILENAME' => '/var/www/index.php',
        'REQUEST_URI' => '/x',
    ])))->toBe('');
});
