<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Http\Middleware\RedirectIfNotInstalled;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/*
| RH-7 — the pre-install enforce middleware must NOT eat Livewire's own update endpoint.
|
| The no-SSH installer wizard is a Livewire component. Until NovFora is installed, RedirectIfNotInstalled
| forces every web request to /install behind a short allowlist that keeps the wizard reachable. Livewire 4
| serves its update/asset endpoints under a per-install HASHED prefix — /livewire-<hash>/update, the hash
| derived from APP_KEY (Livewire\Mechanisms\HandleRequests\EndpointResolver::prefix()) — so the old bare
| 'livewire/*' allowlist never matched: the wizard's own wire:click POST fell through and was 302-redirected
| to /install. Livewire received the install-page HTML where it expected JSON, threw a JSON.parse error, and
| hard-reloaded to a blank step 1 — the live-host "pasted the setup token, nothing happens" symptom.
|
| Why every prior test missed it: the installer suite renders the wizard via Livewire::test(), which DISABLES
| the middleware stack entirely (SubsequentRender::temporarilyDisableExceptionHandlingAndMiddleware), and the
| suite at large runs with NOVFORA_INSTALL_ENFORCE=false (Installer::shouldEnforce() opts it out) — so the
| redirect never fired. These tests close that gap by driving the REAL web middleware stack with enforcement
| ON, which is exactly the real-host pre-install state. They are the in-process equivalent of the browser's
| wire:click: the redirect is decided in PHP middleware, identically for any client.
*/

beforeEach(function () {
    // A pristine, NOT-yet-installed site with enforcement ON — the real-host pre-install condition the suite
    // at large opts out of. The marker points at an absent path (→ shouldEnforce() === true); the setup-token
    // gate is off so toStep2 can advance on the requirement checklist alone.
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'novfora-rh7-'.bin2hex(random_bytes(6));
    @mkdir($dir, 0775, true);

    config([
        'novfora.install.enforce' => true,
        'novfora.install.require_token' => false,
        'novfora.install.marker' => $dir.DIRECTORY_SEPARATOR.'installed',
    ]);
});

/**
 * Harvest the live Livewire update path + the wizard's dehydrated snapshot straight from the rendered page —
 * exactly what a browser reads: data-update-uri on the runtime <script>, wire:snapshot on the component root.
 *
 * @return array{0:string,1:string} [updatePath, snapshotJson]
 */
function harvestWizard(TestCase $t): array
{
    $html = $t->get('/install')->assertOk()->getContent();

    expect(preg_match('/data-update-uri="([^"]+)"/', $html, $u))->toBe(1);
    $updatePath = parse_url(html_entity_decode($u[1], ENT_QUOTES), PHP_URL_PATH);

    // wire:snapshot holds json_encode($snapshot) run through htmlspecialchars(ENT_QUOTES); decode it back to
    // the exact JSON string Livewire's JS client posts (its checksum is HMAC-valid for this APP_KEY).
    expect(preg_match('/wire:snapshot="([^"]+)"/', $html, $s))->toBe(1);
    $snapshot = html_entity_decode($s[1], ENT_QUOTES);

    return [$updatePath, $snapshot];
}

// ── The root cause, pinned ───────────────────────────────────────────────────────────────────────────

it('serves Livewire\'s update endpoint under a hashed prefix the bare livewire/* allowlist misses', function () {
    [$updatePath] = harvestWizard($this);

    // The real shape on every host: /livewire-<hash>/update — never /livewire/update.
    expect($updatePath)->toStartWith('/livewire-');

    $path = ltrim($updatePath, '/');
    expect(Str::is('livewire/*', $path))->toBeFalse();   // why the old allowlist failed to match (the bug)
    expect(Str::is('livewire-*/*', $path))->toBeTrue();  // the hash-agnostic pattern the fix adds
});

// ── The middleware, directly ─────────────────────────────────────────────────────────────────────────

it('lets the hashed Livewire update endpoint through pre-install enforcement (RH-7)', function () {
    $updatePath = app('livewire')->getUpdateUri();        // /livewire-<hash>/update, the real route

    $passed = false;
    $response = app(RedirectIfNotInstalled::class)->handle(
        Request::create($updatePath, 'POST'),
        function () use (&$passed) {
            $passed = true;

            return new Response('reached the app');
        },
    );

    expect($passed)->toBeTrue();                          // the wizard's endpoint is allow-listed, not bounced
    expect($response->isRedirect())->toBeFalse();
});

it('still redirects a non-allowlisted request to the installer under enforcement', function () {
    // Guard against the allowlist becoming over-broad: a normal page must still be forced to the wizard.
    $response = app(RedirectIfNotInstalled::class)->handle(
        Request::create('/forums', 'GET'),
        fn () => new Response('should not reach here'),
    );

    expect($response->isRedirect())->toBeTrue();
    expect($response->headers->get('Location'))->toContain('/install');
});

// ── End-to-end through the real HTTP stack (the in-process reproduction of the host failure) ─────────

it('does not redirect a real Livewire update POST to /install when enforcement is ON (RH-7)', function () {
    [$updatePath, $snapshot] = harvestWizard($this);

    // A faithful Livewire update request: the X-Livewire header + JSON body the JS client sends, the real
    // page snapshot, and a harmless $refresh. On buggy main the enforce middleware 302-redirects this to
    // /install BEFORE Livewire sees it, and the assertion below fails with "expected 200, got 302".
    $response = $this->withHeaders(['X-Livewire' => '1'])->postJson($updatePath, [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => (object) [],
            'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
        ]],
    ]);

    $response->assertOk();                                 // Livewire handled it — NOT a 302 to /install
    $response->assertJsonStructure(['components']);        // a real Livewire JSON body, not the install HTML
    $response->assertSee('System check', escape: false);  // the wizard re-rendered (step 1), not the redirect
});

it('advances the wizard through the HTTP update endpoint under enforcement (end-to-end)', function () {
    [$updatePath, $snapshot] = harvestWizard($this);

    // Drive a real wizard action (Continue → toStep2) the way the browser does: through the hashed update
    // endpoint and the full web middleware stack. A future prefix/allowlist regression that re-breaks the
    // redirect fails this end-to-end, not just at the redirect layer.
    $response = $this->withHeaders(['X-Livewire' => '1'])->postJson($updatePath, [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => (object) [],
            'calls' => [['path' => '', 'method' => 'toStep2', 'params' => []]],
        ]],
    ]);

    $response->assertOk();
    $response->assertSee('Database connection', escape: false); // step 2 rendered → the component advanced
});
