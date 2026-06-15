<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

// ── Manifest ─────────────────────────────────────────────────────────────────────────────────────────────

it('serves an installable web app manifest', function () {
    $res = $this->get('/manifest.webmanifest')->assertOk();
    $res->assertHeader('Content-Type', 'application/manifest+json');

    $manifest = $res->json();
    expect($manifest['start_url'])->toBe('/');
    expect($manifest['display'])->toBe('standalone');
    expect($manifest['scope'])->toBe('/');
    expect($manifest['icons'])->not->toBeEmpty();
    expect(collect($manifest['icons'])->pluck('src'))->toContain('/icons/novfora.svg');
});

// ── Service worker ───────────────────────────────────────────────────────────────────────────────────────

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

it('serves an offline fallback page', function () {
    $this->get('/offline')->assertOk()->assertSee('offline');
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

// ── Wiring ───────────────────────────────────────────────────────────────────────────────────────────────

it('wires the manifest and service worker into the page head', function () {
    $this->get(route('forums.index'))
        ->assertOk()
        ->assertSee('manifest.webmanifest')
        ->assertSee('/sw.js', false);
});
