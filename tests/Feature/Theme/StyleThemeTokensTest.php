<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\SiteTheme;
use App\Support\AccentPalette;
use App\Theme\StyleThemeManager;
use App\Theme\ThemeApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| Theme Studio 1.1 — a style theme can override the core design tokens (surfaces / ink / borders / radius),
| not just the accent. Values are strict-validated, emitted into the head as a :root{} override (light
| palette; dark stays tuned), and the editor shows a live WCAG-AA badge.
*/

uses(RefreshDatabase::class);

it('publishes the editable-token contract (API v1.1+)', function () {
    expect(ThemeApi::VERSION)->toBe('1.3.0');

    $keys = array_keys(ThemeApi::editableTokens());
    expect($keys)->toContain('surface', 'ink', 'ink_muted', 'line', 'radius');
    // Every editable token maps to a real core CSS variable that is also in the token contract.
    foreach (ThemeApi::editableTokens() as $meta) {
        expect(ThemeApi::tokens())->toContain($meta['var']);
    }
});

it('publishes the U9 grouped style-property registry (API v1.3)', function () {
    $registry = ThemeApi::editableTokens();

    // The v1.3 additions are present and typed.
    foreach (['ink_subtle', 'line_strong', 'ember', 'success', 'warn_soft', 'danger_strong'] as $key) {
        expect($registry)->toHaveKey($key);
        expect($registry[$key]['type'])->toBe('color');
    }

    // Every token belongs to a declared group, and every colour token carries a dark default.
    foreach ($registry as $key => $meta) {
        expect(ThemeApi::tokenGroups())->toContain($meta['group']);
        if ($meta['type'] === 'color') {
            expect($meta['dark_default'])->toMatch('/^#[0-9a-f]{6}$/');
        }
    }

    // Lengths have no dark variant.
    expect($registry['radius']['dark_default'])->toBeNull();
});

it('computes the WCAG contrast ratio (black on white = 21, identical = 1)', function () {
    expect(round(AccentPalette::contrastRatio('#000000', '#ffffff'), 2))->toBe(21.0);
    expect(round(AccentPalette::contrastRatio('#2563eb', '#2563eb'), 2))->toBe(1.0);
    expect(AccentPalette::contrastRatio('nope', '#fff'))->toBeNull();

    expect(AccentPalette::passesAA('#141a2b', '#ffffff'))->toBeTrue();  // dark ink on white — passes
    expect(AccentPalette::passesAA('#cccccc', '#ffffff'))->toBeFalse(); // light grey on white — fails
});

it('persists valid token overrides and drops invalid / blank ones', function () {
    $theme = app(StyleThemeManager::class)->create([
        'name' => 'Tokyo',
        'tokens' => [
            'surface' => '#FFFFFF',     // normalised to lowercase
            'ink' => '#000000',
            'radius' => '4px',
            'line' => 'not-a-colour',   // dropped
            'ink_muted' => '',          // dropped
            'bogus_key' => '#123456',   // not in the contract — dropped
        ],
    ]);

    expect($theme->tokens)->toBe([
        'surface' => '#ffffff',
        'ink' => '#000000',
        'radius' => '4px',
    ]);
});

it('clears the tokens column when nothing valid is supplied', function () {
    $theme = app(StyleThemeManager::class)->create([
        'name' => 'Empty',
        'tokens' => ['surface' => 'xxx', 'radius' => '10things'],
    ]);

    expect($theme->tokens)->toBeNull();
});

it('emits the real core-token overrides into the active theme CSS', function () {
    $m = app(StyleThemeManager::class);
    $m->create([
        'name' => 'Mono',
        'accent_color' => '#2563eb',
        'tokens' => ['surface' => '#ffffff', 'ink' => '#111111', 'radius' => '2px'],
        'activate' => true,
    ]);

    $css = $m->css();
    expect($css)->toContain('--surface:#ffffff;')
        ->and($css)->toContain('--ink:#111111;')
        ->and($css)->toContain('--radius-md:2px;')
        ->and($css)->toContain('--accent:'); // accent still emitted too
});

it('tokenCss only emits contract keys and ignores the rest', function () {
    $css = StyleThemeManager::tokenCss(['ink' => '#222222', 'not_real' => '#fff']);

    expect($css)->toBe(':root{--ink:#222222;}');
    expect(StyleThemeManager::tokenCss(null))->toBe('');
    expect(StyleThemeManager::tokenCss([]))->toBe('');
});

it('persists dark token overrides through the same strict gate (U9)', function () {
    $theme = app(StyleThemeManager::class)->create([
        'name' => 'Nocturne',
        'tokens_dark' => [
            'surface' => '#000000',
            'ink' => '#EEEEEE',              // normalised to lowercase
            'line' => ':red;}body{',         // injection attempt — dropped
            'bogus' => '#123456',            // not in the contract — dropped
        ],
    ]);

    expect($theme->tokens_dark)->toBe(['surface' => '#000000', 'ink' => '#eeeeee']);
});

it('emits dark overrides under both dark selectors and leaves light untouched (U9)', function () {
    $m = app(StyleThemeManager::class);
    $m->create([
        'name' => 'Oled',
        'tokens' => ['ink' => '#111111'],
        'tokens_dark' => ['surface' => '#000000'],
        'activate' => true,
    ]);

    $css = $m->css();
    expect($css)->toContain(':root{--ink:#111111;}')
        ->and($css)->toContain("@media (prefers-color-scheme: dark){:root:not([data-theme='light']){--surface:#000000;}}")
        ->and($css)->toContain(":root[data-theme='dark']{--surface:#000000;}");

    // A dark-only value never leaks into the light :root block.
    expect($css)->not->toContain(':root{--surface:#000000;}');
});

it('keeps the tuned built-in dark palette when no dark overrides are set', function () {
    $m = app(StyleThemeManager::class);
    $m->create(['name' => 'LightOnly', 'tokens' => ['surface' => '#ffffff'], 'activate' => true]);

    expect($m->css())->not->toContain('prefers-color-scheme');
});

it('lets a 2FA admin save dark values through the grouped editor (U9)', function () {
    $this->seed();
    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));

    Livewire::test('admin.settings.themes')
        ->call('newTheme')
        ->set('name', 'Duotone')
        ->set('tokens.surface', '#fafafa')
        ->set('tokensDark.surface', '#050508')
        ->set('tokensDark.ink', 'nope')     // invalid → dropped by the manager
        ->call('save')
        ->assertHasNoErrors();

    $theme = SiteTheme::where('name', 'Duotone')->firstOrFail();
    expect($theme->tokens)->toBe(['surface' => '#fafafa']);
    expect($theme->tokens_dark)->toBe(['surface' => '#050508']);
});

it('round-trips dark tokens when re-editing a theme (U9)', function () {
    $this->seed();
    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));
    $theme = app(StyleThemeManager::class)->create(['name' => 'Edit me', 'tokens_dark' => ['ink' => '#dddddd']]);

    Livewire::test('admin.settings.themes')
        ->call('edit', $theme->id)
        ->assertSet('tokensDark.ink', '#dddddd');
});

it('lets a 2FA admin save token overrides through the editor, dropping invalid values', function () {
    $this->seed();
    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));

    Livewire::test('admin.settings.themes')
        ->call('newTheme')
        ->set('name', 'Studio')
        ->set('tokens.surface', '#fafafa')
        ->set('tokens.ink', '#101010')
        ->set('tokens.line', 'garbage') // invalid → dropped by the manager
        ->call('save')
        ->assertHasNoErrors();

    $theme = SiteTheme::where('name', 'Studio')->firstOrFail();
    expect($theme->tokens)->toBe(['surface' => '#fafafa', 'ink' => '#101010']);
});

it('blocks a non-admin from the theme editor (403)', function () {
    $this->seed();
    $this->actingAs(Users::inGroups(['members']));

    Livewire::test('admin.settings.themes')->assertStatus(403);
});
