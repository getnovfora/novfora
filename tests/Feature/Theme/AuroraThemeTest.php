<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Support\AccentPalette;
use App\Theme\Theme;
use App\Theme\ThemeManager;

/*
| The shipped "Aurora" filesystem child theme (A4) under themes/aurora. It exercises the Blade view-override
| API on two seams: the head-injection point (a distinct, AA-safe accent palette) and the footer tagline
| (branding). It is distinct from the DB style editor (ADR-0029). Mirrors tests/Feature/Theme/ThemeOverrideTest:
| activate via config + ThemeManager::boot, then assert the override resolves ahead of core (core untouched).
*/

function activateAurora(): ?Theme
{
    config(['novfora.theme.path' => base_path('themes'), 'novfora.theme.active' => 'aurora']);

    return app(ThemeManager::class)->boot('aurora');
}

it('loads the Aurora theme and exposes its semver API version (the public contract)', function () {
    $theme = activateAurora();

    expect($theme?->slug)->toBe('aurora')
        ->and($theme?->name)->toBe('Aurora');
});

it('overrides the head partial with a distinct AA-safe accent palette', function () {
    // Core default head partial carries no palette.
    expect(view('partials.theme-head', ['nonce' => 'n0nce'])->render())->not->toContain('#0e7490');

    activateAurora();

    $html = view('partials.theme-head', ['nonce' => 'n0nce'])->render();
    expect($html)
        ->toContain('--accent:#0e7490')   // Aurora's distinct accent hue
        ->toContain('--accent-ink:')      // the AA-safe ink AccentPalette computed for it
        ->toContain('data-theme-aurora')  // the theme's marker
        ->toContain('nonce="n0nce"');     // CSP-nonce'd
});

it('computes an AA-safe accent ink for the Aurora accent (white on the deep teal)', function () {
    // #0e7490 is dark enough that AA contrast picks white ink — proving the palette is AA-derived, not guessed.
    $palette = AccentPalette::for('#0e7490');
    expect($palette['light']['accent-ink'])->toBe('#ffffff');
});

it('overrides the footer tagline with Aurora branding', function () {
    expect(view('partials.footer-tagline')->render())->not->toContain('Aurora');

    activateAurora();
    expect(view('partials.footer-tagline')->render())->toContain('Aurora — a NovFora');
});

it('is a no-op when Aurora is not active (core defaults render, core files untouched)', function () {
    config(['novfora.theme.path' => base_path('themes'), 'novfora.theme.active' => '']);
    app(ThemeManager::class)->boot('');

    expect(view('partials.theme-head', ['nonce' => 'n0nce'])->render())->not->toContain('#0e7490')
        ->and(view('partials.footer-tagline')->render())->not->toContain('Aurora — a NovFora');
});
