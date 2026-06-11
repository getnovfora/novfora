<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Install\Installer;
use Laravel\Dusk\Browser;

/*
| The no-SSH web installer driven END-TO-END in a real browser (RH-6). The wizard is a Livewire component
| on a STANDALONE pre-install layout (resources/views/install/index.blade.php) — it can't use the main app
| layout, which assumes a database + auth. When that layout failed to fully boot Livewire on a real host,
| every wire:click was a dead no-op and the install could not be completed. There was NO browser coverage
| of /install (Dusk only ever exercised the editor), so the single most important "does it install?" path
| was invisible. This test is that missing coverage: it walks system -> database -> site/admin -> install
| -> done with real clicks and keystrokes, then proves the installer LOCKS (a second visit 403s).
|
| Every Continue here is a wire:click and every field is a wire:model — so a regression back to the dead
| front-end (directives that don't bind) fails this test at the very first "Continue".
|
| Isolation: the served app points its installer env (.env target, marker, setup token, public-storage)
| at a throwaway sandbox under storage/dusk-install (see docker/dusk/run.sh), and the wizard installs into
| a DISPOSABLE MySQL database — so the run never clobbers the harness's own .env, sqlite, or marker.
*/

it('drives the full installer wizard in a real browser, then locks', function () {
    $installer = app(Installer::class);
    $marker = $installer->markerPath();

    // Pre-conditions: a fresh, un-installed site with a setup token on disk — the same value the wizard's
    // step 1 demands (mirroring storage/install-token.txt on a real upload). ensureToken() returns the
    // existing token or mints one at the config-driven token_path the served app also reads, so the test
    // owns this rather than depending on the harness having written it (robust to bind-mount timing).
    expect($installer->isInstalled())->toBeFalse();
    $token = (string) $installer->ensureToken();
    expect($token)->not->toBe('');

    // The disposable database the wizard installs INTO (created empty by the harness).
    $dbHost = (string) env('NOVFORA_DUSK_INSTALL_DB_HOST', 'mysql');
    $dbName = (string) env('NOVFORA_DUSK_INSTALL_DB_NAME', 'novfora_install');
    $dbUser = (string) env('NOVFORA_DUSK_INSTALL_DB_USER', 'root');
    $dbPass = (string) env('NOVFORA_DUSK_INSTALL_DB_PASS', 'secret');

    $this->browse(function (Browser $browser) use ($token, $dbHost, $dbName, $dbUser, $dbPass) {
        // The wizard's inputs are DEFERRED wire:model (sent on the next action, not per keystroke), so a
        // short pause after the last field lets Livewire's client capture every value before the Continue
        // serializes them — without it, a fast type→press can submit a stale/empty field and validation
        // keeps us on the same step (the editor journey settles the same way). Timeouts are generous: under
        // enforcement-ON every request also flows through RedirectIfNotInstalled on a single-threaded
        // `artisan serve`, and step 2→3 verifies a live MySQL connection.
        $browser->visit('/install')
            ->waitForText('System check', 20)
            ->assertSee('Continue')

            // ── STEP 1 — system check + setup token ──────────────────────────────────────────────────
            // Typing exercises wire:model; pressing Continue exercises wire:click -> toStep2. This is the
            // exact interaction that was dead on the real host.
            ->type('#setupToken', $token)
            ->pause(300)
            ->press('Continue')
            ->waitForText('Database connection', 25)

            // ── STEP 2 — database (a disposable MySQL database) ──────────────────────────────────────
            ->type('#dbHost', $dbHost)
            ->type('#dbDatabase', $dbName)
            ->type('#dbUsername', $dbUser)
            ->type('#dbPassword', $dbPass)
            ->pause(300)
            ->press('Continue')                         // toStep3 validates + verifies the live connection
            ->waitForText('Administrator account', 30)

            // ── STEP 3 — site & administrator ────────────────────────────────────────────────────────
            ->type('#siteName', 'Dusk Community')
            ->type('#adminUsername', 'duskadmin')
            ->type('#adminEmail', 'dusk-admin@novfora.test')
            ->type('#adminPassword', 'Sup3rSecret!!')
            ->type('#passwordConfirmation', 'Sup3rSecret!!')
            ->pause(400)
            ->press('Continue')
            ->waitForText('Review &', 30)               // step 4 heading: "Review & install"

            // ── STEP 4 — review & install ────────────────────────────────────────────────────────────
            ->assertSee('Dusk Community')               // the review echoes what we typed (wire:model stuck)

            ->waitForText('is installed', 60)           // step 5 — the real install ran to completion
            ->assertSee('cron');                        // the post-install cron-line guidance
    });

    // The lock landed on disk: the marker was written LAST, so its presence proves a complete install.
    expect(is_file($marker))->toBeTrue();
    expect($installer->isInstalled())->toBeTrue();

    // And the installer is now sealed — a fresh request to /install is a hard 403 (EnsureNotInstalled),
    // both in-process and in a real browser. No re-run, no second admin.
    $this->get('/install')->assertForbidden();

    $this->browse(function (Browser $browser) {
        $browser->visit('/install')->assertDontSee('System check');
    });
});
