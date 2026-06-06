<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

/*
| RH-6 regression guard (no browser required). The /install wizard lives on a STANDALONE pre-install layout
| that can't use layouts/app (no DB/auth yet). The real-host failure: the layout shipped no Livewire runtime
| of its own and relied entirely on Livewire's response-rewrite AUTO-INJECTION — which a shared-host page
| cache / JS optimizer can strip or defer, so the wizard rendered but every wire:click/wire:model was dead
| (Livewire's bundle auto-starts only from a DOMContentLoaded listener; a bundle that loads late never
| starts). These tests render /install and assert the layout (a) delivers Livewire's styles+scripts ITSELF
| even with auto-injection OFF, (b) carries the boot guard that starts a late-loaded bundle, and (c) does
| not double-inject when auto-injection is ON. The full in-browser proof is tests/Browser/InstallerWizardTest.
*/

beforeEach(function () {
    // A pristine, not-yet-installed sandbox so /install renders: no marker, no token gate, no redirect.
    config([
        'hearth.install.enforce' => false,
        'hearth.install.require_token' => false,
        'hearth.install.marker' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'hearth-nomarker-'.bin2hex(random_bytes(5)),
    ]);
});

it('delivers Livewire styles + scripts from the layout even when auto-injection is OFF', function () {
    // Stand in for a host where Livewire's post-render auto-injection never reaches the browser intact.
    config(['livewire.inject_assets' => false]);

    $html = $this->get('/install')->assertOk()->getContent();

    // The wizard itself rendered (its component snapshot + step 1 content)...
    expect($html)->toContain('wire:snapshot')->toContain('System check');

    // ...and CRUCIALLY the runtime is present FROM THE LAYOUT, not from auto-injection: the bundle <script
    // src> and the Livewire <style> block. Without the explicit @livewireScripts/@livewireStyles, an
    // auto-injection-off host gets neither and the wizard is dead — which is the bug this guards against.
    expect($html)->toMatch('/<script[^>]+src="[^"]*livewire(\.min)?\.js/');
    expect($html)->toContain('wire:loading'); // doubled wire:loading selectors == the @livewireStyles block
});

it('emits the boot guard that starts a bundle which loads after DOMContentLoaded', function () {
    $html = $this->get('/install')->assertOk()->getContent();

    // The guard recovers the real-host failure mode (optimizer-deferred bundle whose event-gated auto-start
    // never fires) by starting Livewire once the window has loaded, and only if it hasn't already started.
    expect($html)->toContain('window.Livewire.start()');
    expect($html)->toContain("addEventListener('livewire:init'"); // the flag that prevents a double-start
});

it('does not double-inject the Livewire runtime when auto-injection is ON', function () {
    config(['livewire.inject_assets' => true]); // the production default

    $html = $this->get('/install')->assertOk()->getContent();

    // Exactly one Livewire bundle <script src>: FrontendAssets' render-guard means the explicit
    // @livewireScripts suppresses the auto-injected copy, so there is no duplicate Alpine/Livewire.
    $bundles = substr_count($html, '/livewire.js') + substr_count($html, '/livewire.min.js');
    expect($bundles)->toBe(1);
});
