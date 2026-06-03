<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Theme\ThemeManager;

/*
| The developer theme layer (ADR-0009 §3.2) — the deliberate part: a child theme overrides a core view with
| NO core edit, resolved active theme → parent → core. The theme API is semver'd (the public contract).
*/

it('resolves a child theme view ahead of core without editing core', function () {
    config([
        'hearth.theme.path' => base_path('tests/Fixtures/themes'),
        'hearth.theme.active' => 'sample',
    ]);

    // Before activation the core view renders (no override marker).
    expect(view('search.index', ['q' => '', 'results' => collect()])->render())
        ->not->toContain('THEME-OVERRIDE-ACTIVE');

    // Activating the theme makes its override win — core files are untouched.
    $theme = app(ThemeManager::class)->boot('sample');

    expect($theme?->slug)->toBe('sample');
    expect(view('search.index', ['q' => 'hi', 'results' => collect()])->render())
        ->toContain('THEME-OVERRIDE-ACTIVE');
});

it('exposes a semver theme API version (the public contract)', function () {
    expect(ThemeManager::API_VERSION)->toBe('1.0');
});

it('is a no-op when no theme is active (core is the default)', function () {
    config(['hearth.theme.active' => null]);

    expect(app(ThemeManager::class)->boot())->toBeNull();
});
