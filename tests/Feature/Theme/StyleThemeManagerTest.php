<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\SiteTheme;
use App\Theme\StyleThemeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| The ACP visual theme editor: DB-backed style themes (accent + custom CSS) created/activated without
| filesystem edits. Exactly one theme is active; the active theme's compiled CSS is what the layout injects.
*/

uses(RefreshDatabase::class);

it('creates an inactive theme and emits no CSS until one is activated', function () {
    $m = app(StyleThemeManager::class);
    $theme = $m->create(['name' => 'Ocean', 'accent_color' => '#2563eb']);

    expect($theme->is_active)->toBeFalse();
    expect($m->css())->toBe('');
});

it('activates a theme, emits its accent CSS, and enforces a single active theme', function () {
    $m = app(StyleThemeManager::class);
    $a = $m->create(['name' => 'Ocean', 'accent_color' => '#2563eb', 'activate' => true]);
    $b = $m->create(['name' => 'Forest', 'accent_color' => '#166534']);

    expect($a->fresh()->is_active)->toBeTrue();
    expect($m->css())->toContain('--accent:');

    $m->activate($b);

    expect($a->fresh()->is_active)->toBeFalse();
    expect($b->fresh()->is_active)->toBeTrue();
    expect(SiteTheme::where('is_active', true)->count())->toBe(1);
});

it('sanitises custom CSS so it cannot break out of the style element', function () {
    $clean = StyleThemeManager::sanitizeCss('body{color:red}</style><!-- x -->');

    expect($clean)->not->toContain('</style')
        ->and($clean)->not->toContain('<!--')
        ->and($clean)->toContain('body{color:red}');
});

it('drops an invalid accent to null', function () {
    $theme = app(StyleThemeManager::class)->create(['name' => 'Bad', 'accent_color' => 'not-a-hex']);

    expect($theme->accent_color)->toBeNull();
});

it('deactivates back to the built-in default look', function () {
    $m = app(StyleThemeManager::class);
    $t = $m->create(['name' => 'Ocean', 'accent_color' => '#2563eb', 'activate' => true]);
    expect($m->css())->not->toBe('');

    $m->deactivate();

    expect($t->fresh()->is_active)->toBeFalse();
    expect($m->css())->toBe('');
});

it('requires a non-empty name', function () {
    app(StyleThemeManager::class)->create(['name' => '   ']);
})->throws(InvalidArgumentException::class);
