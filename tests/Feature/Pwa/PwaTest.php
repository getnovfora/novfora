<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Forum;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

/**
 * GET a path as the front controller of a /community subdirectory install sees it: SCRIPT_NAME points at
 * /community/index.php, so the request base path is /community and BasePathDetector forces the root URL —
 * exactly the RH-4/ADR-0070 mechanism (mirrors SubdirInstallTest; uniquely named to avoid a parallel-worker
 * function/const collision with it).
 */
function pwaSubdirGet(string $uri)
{
    return test()->call('GET', $uri, [], [], [], [
        'SCRIPT_NAME' => '/community/index.php',
        'SCRIPT_FILENAME' => '/var/www/community/index.php',
    ]);
}

// ── Manifest (root mount — the strict no-op contract) ────────────────────────────────────────────────────

it('serves an installable web app manifest', function () {
    $res = $this->get('/manifest.webmanifest')->assertOk();
    $res->assertHeader('Content-Type', 'application/manifest+json');

    $manifest = $res->json();
    expect($manifest['start_url'])->toBe('/');
    expect($manifest['display'])->toBe('standalone');
    expect($manifest['scope'])->toBe('/');
    expect($manifest['icons'])->not->toBeEmpty();
    // Icon srcs now go through asset() (absolute), so assert the SVG path is present rather than an exact value.
    expect(collect($manifest['icons'])->pluck('src')->implode("\n"))->toContain('/icons/novfora.svg');
});

it('offers 192 + 512 raster icons and a dedicated maskable icon for the install prompt', function () {
    $icons = collect($this->get('/manifest.webmanifest')->assertOk()->json('icons'));

    $has = fn (string $sizes, string $purpose) => $icons->contains(
        fn (array $i) => ($i['sizes'] ?? null) === $sizes && ($i['type'] ?? null) === 'image/png' && ($i['purpose'] ?? null) === $purpose,
    );

    expect($has('192x192', 'any'))->toBeTrue();
    expect($has('512x512', 'any'))->toBeTrue();
    expect($has('512x512', 'maskable'))->toBeTrue();
});

// ── Service worker (root mount) ──────────────────────────────────────────────────────────────────────────

it('serves the service worker at the root with the right headers', function () {
    $res = $this->get('/sw.js')->assertOk();

    expect($res->headers->get('Content-Type'))->toContain('javascript');
    $res->assertHeader('Service-Worker-Allowed', '/');
});

it('ships a service worker that never caches mutations or PII', function () {
    $res = $this->get('/sw.js')->assertOk();

    // Mutations bypass the SW entirely (only GET handled) …
    $res->assertSee("req.method !== 'GET'", false);
    // … and a page is cached only when the server flagged it safe (guest, no PII).
    $res->assertSee('X-PWA-Cacheable', false);
});

it('derives its mount scope from the registration so it works under any mount', function () {
    $res = $this->get('/sw.js')->assertOk();

    // ADR-0078: the SW reads its own scope rather than hard-coding "/", and every cached prefix + the
    // offline fallback are scope-relative — so it is correct under /community/ with no server templating.
    $res->assertSee('new URL(self.registration.scope).pathname', false);
    $res->assertSee("SCOPE + 'offline'", false);
    $res->assertSee("SCOPE + 'build/'", false);
    $res->assertSee("SCOPE + 'icons/'", false);
});

it('serves an offline fallback page', function () {
    $this->get('/offline')->assertOk()->assertSee('offline');
});

// ── Subpath mount (ADR-0078 — the RH-4 deferral resolved) ────────────────────────────────────────────────

it('serves a subpath-scoped manifest under a /community mount', function () {
    $manifest = pwaSubdirGet('/community/manifest.webmanifest')->assertOk()->json();

    expect($manifest['start_url'])->toBe('/community/');
    expect($manifest['scope'])->toBe('/community/');
    // Every icon src carries the mount prefix (asset() inherited the forced root).
    collect($manifest['icons'])->each(fn (array $i) => expect($i['src'])->toContain('/community/'));
});

it('allows the service worker the subpath scope under a /community mount', function () {
    pwaSubdirGet('/community/sw.js')->assertOk()->assertHeader('Service-Worker-Allowed', '/community/');
});

it('registers the service worker with the subpath scope in the page head under a /community mount', function () {
    Forum::create(['slug' => 'general', 'title' => 'General Chat', 'type' => 'forum']);

    // The mount root as the front controller receives it (the test client strips a trailing slash, so dispatch
    // through the Kernel like SubdirInstallTest does for /community/).
    $request = Request::create('http://localhost/community/', 'GET', [], [], [], [
        'SCRIPT_NAME' => '/community/index.php',
        'SCRIPT_FILENAME' => '/var/www/community/index.php',
    ]);
    $html = (string) app(Kernel::class)->handle($request)->getContent();
    $clean = str_replace('\\/', '/', $html); // undo @js slash-escaping for assertion clarity

    expect($clean)->toContain('/community/manifest.webmanifest'); // manifest link carries the prefix
    expect($clean)->toContain('/community/icons/novfora.svg');    // apple-touch-icon via asset() carries it
    expect($clean)->toContain('/community/sw.js');                // SW registered at the subpath URL …
    expect($clean)->toContain("scope: '/community/'");            // … with an explicit subpath scope (@js → single-quoted)
});

// ── Cacheability flag — the "never PII" guarantee ────────────────────────────────────────────────────────

it('flags a guest public page as cacheable for the service worker', function () {
    $this->get(route('forums.index'))->assertOk()->assertHeader('X-PWA-Cacheable', '1');
});

it('never flags an authenticated page as cacheable (PII protection)', function () {
    $user = Users::inGroups(['members', 'tl1'], ['email' => 'pwa@test.test']);

    $this->actingAs($user)->get(route('forums.index'))->assertOk()->assertHeaderMissing('X-PWA-Cacheable');
});

it('never flags an auth-surface page as cacheable even for guests', function () {
    $this->get('/login')->assertOk()->assertHeaderMissing('X-PWA-Cacheable');
});

// ── Wiring (root mount) ──────────────────────────────────────────────────────────────────────────────────

it('wires the manifest and service worker into the page head', function () {
    $this->get(route('forums.index'))
        ->assertOk()
        ->assertSee('manifest.webmanifest')
        ->assertSee('/sw.js', false);
});

it('registers the service worker with the root scope at a domain root (no-op)', function () {
    $html = $this->get(route('forums.index'))->assertOk()->getContent();
    $clean = str_replace('\\/', '/', $html);

    expect($clean)->toContain('/sw.js');
    expect($clean)->toContain("scope: '/'"); // root mount → scope "/" (identical to pre-ADR-0078; @js → single-quoted)
});
