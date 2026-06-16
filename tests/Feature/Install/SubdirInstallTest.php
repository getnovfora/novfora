<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Http\Middleware\RedirectIfNotInstalled;
use App\Models\Forum;
use App\Support\Http\BasePathDetector;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

uses(RefreshDatabase::class);

/*
| RH-4.5 (ADR-0070) — the subdirectory install matrix + the G4 root-layout regression guard + the G2 rebuild
| drift guard. A subdirectory request is simulated as the front controller sees it: SCRIPT_NAME points at
| /community/index.php, so Symfony's request base path is /community and every generated URL (route(), @vite,
| Livewire's update endpoint) carries the prefix — exactly the live behaviour the base-path detector confirms
| and pins (its isolated contract is in BasePathDetectorTest).
*/

const SUBDIR_SERVER = [
    'SCRIPT_NAME' => '/community/index.php',
    'SCRIPT_FILENAME' => '/var/www/community/index.php',
];

/** GET a path as the front controller of a /community subdirectory install sees it. */
function subdirGet(string $uri)
{
    return test()->call('GET', $uri, [], [], [], SUBDIR_SERVER);
}

/** Switch on installer enforcement against an absent marker (a pristine, not-yet-installed site). */
function notInstalledSandbox(): void
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'novfora-subinst-'.bin2hex(random_bytes(6));
    @mkdir($dir, 0775, true);

    config([
        'novfora.install.enforce' => true,
        'novfora.install.require_token' => false,
        'novfora.install.marker' => $dir.DIRECTORY_SEPARATOR.'installed', // absent → not installed
    ]);
}

// ── Pre-install: the wizard is reachable + styled with a working Livewire endpoint under /community ──────

it('serves the installer wizard at /community/install (200) with a /community-prefixed Livewire endpoint', function () {
    notInstalledSandbox();

    $html = subdirGet('/community/install')->assertOk()->assertSee('System check')->getContent();

    // The headline RH-4 fix: the wizard's Livewire update endpoint carries the subpath (a SINGLE /community),
    // so the browser's "Continue" POSTs to /community/livewire-<hash>/update — not /livewire-<hash>/update.
    expect($html)->toMatch('#data-update-uri="[^"]*/community/livewire-[a-f0-9]+/update"#');
});

it('keeps the pre-install allowlist prefix-agnostic for install, assets, and the Livewire update (open Q#3)', function () {
    notInstalledSandbox();

    foreach (['/community/install', '/community/build/assets/app.css', '/community/livewire-abc12345/update'] as $uri) {
        $passed = false;
        app(RedirectIfNotInstalled::class)->handle(
            Request::create($uri, 'GET', [], [], [], SUBDIR_SERVER),
            function () use (&$passed) {
                $passed = true;

                return new Response('reached');
            },
        );
        expect($passed)->toBeTrue("{$uri} must pass the pre-install allowlist under the subpath");
    }
});

it('still forces a non-allowlisted /community page to the installer (enforcement intact under a subpath)', function () {
    notInstalledSandbox();

    $response = app(RedirectIfNotInstalled::class)->handle(
        Request::create('/community/members', 'GET', [], [], [], SUBDIR_SERVER),
        fn () => new Response('should not reach'),
    );

    expect($response->isRedirect())->toBeTrue();
    expect($response->headers->get('Location'))->toContain('/install');
});

// ── Post-install: the forum index IS the home at /community/, /community/forums 301s to it ───────────────

it('post-install: /community/ serves the forum index styled (200, not a redirect)', function () {
    $this->seed();
    Forum::create(['slug' => 'general', 'title' => 'General Chat', 'type' => 'forum']);

    // The mount root as the front controller actually receives it (REQUEST_URI=/community/, base=/community,
    // pathInfo=/). Dispatch directly through the Kernel because the test client's URL normaliser strips the
    // trailing slash (turning /community/ into the bare /community Symfony can't base-strip). Every sub-path
    // (/community/install, /community/forums) is proven separately via the standard test client above.
    $request = Request::create('http://localhost/community/', 'GET', [], [], [], SUBDIR_SERVER);
    $response = app(Kernel::class)->handle($request);

    expect($response->getStatusCode())->toBe(200);
    $html = (string) $response->getContent();
    expect($html)->toContain('General Chat');
    // @vite assets carry the subpath → the page is styled (CSS resolves under /community/build, not a 404).
    expect($html)->toContain('/community/build/');
});

it('post-install: /community/forums permanently 301s to the /community root', function () {
    $this->seed();
    Forum::create(['slug' => 'general', 'title' => 'General Chat', 'type' => 'forum']);

    $response = subdirGet('/community/forums');

    $response->assertStatus(301);
    expect($response->headers->get('Location'))->toContain('/community');
    expect($response->headers->get('Location'))->not->toContain('/community/forums');
});

// ── Uploaded avatars resolve under /community/storage (the public disk URL derives from APP_URL) ──────────

it('resolves uploaded avatars under /community/storage for a subdirectory install', function () {
    // Post-install the installer writes APP_URL = https://example.com/community; the public disk url is
    // APP_URL + /storage (config/filesystems.php), so Storage::url() puts uploads under the subpath.
    config(['filesystems.disks.public.url' => 'https://example.com/community/storage']);

    expect(Storage::disk('public')->url('avatars/me.png'))
        ->toBe('https://example.com/community/storage/avatars/me.png');
});

// ── G4: the ROOT layout is unchanged (the same contract at a domain root) ────────────────────────────────

it('root-layout regression guard (G4): / serves the index, /forums 301s to /, no subpath leaks in', function () {
    $this->seed();
    Forum::create(['slug' => 'general', 'title' => 'General Chat', 'type' => 'forum']);

    $html = $this->get('/')->assertOk()->assertSee('General Chat')->getContent();
    expect($html)->not->toContain('/community/');            // no spurious prefix forced at the root
    expect(route('forums.index'))->toBe('http://localhost'); // the canonical home is the bare root

    $this->get('/forums')->assertStatus(301)->assertRedirect(route('forums.index'));
});

// ── G2: one canonical build/ — the subpath @vite URL maps to the single public/build (no dual-public) ────

it('rebuild drift guard (G2): subpath @vite URLs map to the single canonical public/build', function () {
    // Force the subpath root the way a /community request does, then render @vite from the committed manifest.
    $request = Request::create('/community/', 'GET', [], [], [], SUBDIR_SERVER);
    app()->instance('request', $request);
    app('url')->setRequest($request);
    app(BasePathDetector::class)->apply($request, 'http://localhost');

    $vite = app(Vite::class);
    $vite->useHotFile(storage_path('framework/testing/vite-no-hot-'.bin2hex(random_bytes(4))));
    $html = (string) $vite(['resources/css/app.css', 'resources/js/app.js']);

    expect(preg_match_all('#https?://[^"/]+(/community/build/[^"\'\s>]+)#', $html, $m))->toBeGreaterThan(0);
    foreach ($m[1] as $path) {
        // Strip the /community mount → the URL must resolve to the ONE public/build on disk (G2: no drift).
        $relative = preg_replace('#^/community/#', '', $path);
        expect(is_file(public_path($relative)))->toBeTrue("served asset must exist in the single public/build: {$relative}");
    }
});
