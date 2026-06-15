<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Forum;
use App\Theme\StyleThemeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

/*
| Theme Studio 1.2 — a style theme can carry custom header / footer HTML (a wrapper around the board). It is
| sanitised through the SAME allowlist as posts at write time, cached, and rendered into the layout chrome.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => Cache::flush());

it('sanitises header/footer HTML through the post allowlist on save', function () {
    $theme = app(StyleThemeManager::class)->create([
        'name' => 'Branded',
        'header_html' => '<p>Welcome</p><script>alert(1)</script><style>body{display:none}</style>',
        'footer_html' => '<a href="https://example.test" onclick="evil()">Our site</a>',
    ]);

    expect($theme->header_html)->toBe('<p>Welcome</p>')              // script + style dropped
        ->and($theme->footer_html)->toContain('Our site')
        ->and($theme->footer_html)->not->toContain('onclick');      // event handler stripped
});

it('stores null when the HTML sanitises down to nothing', function () {
    $theme = app(StyleThemeManager::class)->create([
        'name' => 'Empty chrome',
        'header_html' => '<script>only()</script>',
        'footer_html' => '   ',
    ]);

    expect($theme->header_html)->toBeNull()
        ->and($theme->footer_html)->toBeNull();
});

it('exposes the active theme chrome and clears it when none is active', function () {
    $m = app(StyleThemeManager::class);
    expect($m->chrome())->toBe(['header' => '', 'footer' => '']);

    $m->create(['name' => 'Band', 'header_html' => '<p>Top</p>', 'footer_html' => '<p>Bottom</p>', 'activate' => true]);
    expect($m->chrome())->toBe(['header' => '<p>Top</p>', 'footer' => '<p>Bottom</p>']);

    $m->deactivate();
    expect($m->chrome())->toBe(['header' => '', 'footer' => '']); // invalidated on write
});

it('reflects an edit immediately (cache invalidated on update)', function () {
    $m = app(StyleThemeManager::class);
    $t = $m->create(['name' => 'Band', 'header_html' => '<p>One</p>', 'activate' => true]);
    expect($m->chrome()['header'])->toBe('<p>One</p>');

    $m->update($t, ['name' => 'Band', 'header_html' => '<p>Two</p>']);
    expect($m->chrome()['header'])->toBe('<p>Two</p>');
});

it('renders the active theme header & footer HTML into the page chrome', function () {
    $this->seed();
    Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);

    $m = app(StyleThemeManager::class);
    $m->create([
        'name' => 'Banner',
        'header_html' => '<p>Grand opening sale</p>',
        'footer_html' => '<p>Run by volunteers</p>',
        'activate' => true,
    ]);

    $this->get(route('forums.index'))->assertOk()
        ->assertSee('Grand opening sale')
        ->assertSee('Run by volunteers');

    $m->deactivate();
    $this->get(route('forums.index'))->assertOk()->assertDontSee('Grand opening sale');
});
