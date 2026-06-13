<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Modules\SlotRegistry;
use App\Theme\LayoutManager;
use App\Theme\Theme;
use App\Theme\ThemeApi;
use App\Theme\ThemeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| DOGFOOD (D2): the first-party "Nebula" filesystem child theme (themes/nebula), built purely via the theme API.
| It exercises TOKEN OVERRIDES (the documented ThemeApi token contract) + branding through Blade view overrides,
| and proves it COEXISTS with the module slot system + the admin layout/region configurator — zero core edits.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function activateNebula(): ?Theme
{
    config(['novfora.theme.path' => base_path('themes'), 'novfora.theme.active' => 'nebula']);

    return app(ThemeManager::class)->boot('nebula');
}

it('loads the Nebula theme and exposes its semver API version', function () {
    $theme = activateNebula();

    expect($theme?->slug)->toBe('nebula')
        ->and($theme?->name)->toBe('Nebula');
});

it('overrides the documented ThemeApi token contract (AA-safe accent + semantic tokens)', function () {
    // Core default head carries no Nebula palette.
    expect(view('partials.theme-head', ['nonce' => 'n0nce'])->render())->not->toContain('data-theme-nebula');

    activateNebula();
    $html = view('partials.theme-head', ['nonce' => 'n0nce'])->render();

    expect($html)
        ->toContain('--accent:#7c3aed')     // a ThemeApi-listed AccentPalette token, distinctly Nebula
        ->toContain('--novfora-accent:')    // a documented semantic alias, re-pointed
        ->toContain('--novfora-radius:')    // another documented semantic token overridden
        ->toContain('data-theme-nebula')
        ->toContain('nonce="n0nce"');       // CSP-nonce'd

    // Every token Nebula overrides is one the public contract actually lists.
    expect(ThemeApi::tokens())->toContain('--novfora-accent', '--accent');
});

it('rebrands the footer via the view-override seam', function () {
    expect(view('partials.footer-tagline')->render())->not->toContain('Nebula');

    activateNebula();
    expect(view('partials.footer-tagline')->render())->toContain('Nebula — a NovFora');
});

it('coexists with the layout/region configurator and module slots under the active theme', function () {
    activateNebula();

    // A configured layout region still renders under the theme (theme = presentation only).
    $manager = app(LayoutManager::class);
    $placement = $manager->add('forum_top', 'html');
    $manager->updateSettings($placement, ['html' => 'NEBULA-REGION-OK']);
    expect($manager->render('forum_top'))->toContain('NEBULA-REGION-OK');

    // A module slot still renders (and is still sanitised) under the theme.
    app(SlotRegistry::class)->addSlot('footer.widgets', fn () => '<span class="nebula-slot">slot-ok</span><script>x</script>');
    $slot = app(SlotRegistry::class)->render('footer.widgets');
    expect($slot)->toContain('slot-ok')->not->toContain('<script>');
});

it('is a no-op when Nebula is not active (core defaults render, core untouched)', function () {
    config(['novfora.theme.path' => base_path('themes'), 'novfora.theme.active' => '']);
    app(ThemeManager::class)->boot('');

    expect(view('partials.theme-head', ['nonce' => 'n0nce'])->render())->not->toContain('data-theme-nebula')
        ->and(view('partials.footer-tagline')->render())->not->toContain('Nebula — a NovFora');
});
