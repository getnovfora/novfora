<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Theme\LayoutManager;
use App\Theme\ThemeApi;
use App\Theme\WidgetRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

/*
| The layout/region configurator (ADR-0032, B2): built-in widgets, region rendering, the settings constraint
| (unknown keys dropped), reorder, and the apex pin — admin HTML in the HTML-block widget is sanitised. Plus
| the versioned theme-API token contract.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('registers the built-in widgets', function () {
    $registry = app(WidgetRegistry::class);
    expect($registry->has('html'))->toBeTrue()
        ->and($registry->has('stats'))->toBeTrue()
        ->and(collect($registry->all())->map->key()->all())->toContain('html', 'stats');
});

it('renders an HTML-block widget and sanitises its admin input', function () {
    $manager = app(LayoutManager::class);
    $placement = $manager->add('forum_top', 'html');
    $manager->updateSettings($placement, ['html' => '<p>Hello <strong>world</strong></p><script>alert(1)</script>']);

    $html = $manager->render('forum_top');
    expect($html)->toContain('Hello')
        ->toContain('<strong>world</strong>')
        ->not->toContain('<script>')
        ->not->toContain('alert(1)');
});

it('drops settings keys the widget does not declare', function () {
    $manager = app(LayoutManager::class);
    $placement = $manager->add('forum_top', 'html');
    $manager->updateSettings($placement, ['html' => 'ok', 'evil' => 'x', 'is_enabled' => false]);

    expect($placement->fresh()->settings)->toBe(['html' => 'ok']);
});

it('skips disabled placements and unknown regions', function () {
    $manager = app(LayoutManager::class);
    $placement = $manager->add('forum_top', 'html');
    $manager->updateSettings($placement, ['html' => 'VISIBLE-MARKER']);
    expect($manager->render('forum_top'))->toContain('VISIBLE-MARKER');

    $manager->setEnabled($placement, false);
    expect($manager->render('forum_top'))->toBe('')
        ->and($manager->render('not.a.region'))->toBe('');
});

it('renders the stats widget with board counts', function () {
    app(LayoutManager::class)->add('forum_bottom', 'stats');
    expect(app(LayoutManager::class)->render('forum_bottom'))
        ->toContain('Board statistics')
        ->toContain('Members');
});

it('reorders placements with move', function () {
    $manager = app(LayoutManager::class);
    $a = $manager->add('forum_top', 'html');
    $b = $manager->add('forum_top', 'stats');
    expect($a->position)->toBeLessThan($b->position);

    $manager->move($b, -1); // move the second widget up
    expect($b->fresh()->position)->toBeLessThan($a->fresh()->position);
});

it('exposes the configured region on the forum index page', function () {
    $manager = app(LayoutManager::class);
    $placement = $manager->add('forum_top', 'html');
    $manager->updateSettings($placement, ['html' => 'ANNOUNCEMENT-XYZ']);

    $this->actingAs(Users::inGroups(['members', 'tl1']))
        ->get(route('forums.index'))
        ->assertOk()
        ->assertSee('ANNOUNCEMENT-XYZ', false);
});

it('publishes a versioned theme-API token contract', function () {
    expect(ThemeApi::VERSION)->toBe('1.2.0')
        ->and(ThemeApi::tokens())->toContain('--novfora-accent', '--accent', '--accent-ink');
});
