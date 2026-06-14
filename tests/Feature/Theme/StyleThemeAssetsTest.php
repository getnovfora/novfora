<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Forum;
use App\Models\SiteTheme;
use App\Theme\StyleThemeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| Theme Studio 1.5 — a style theme binds its own logo / favicon / background, stored on the public disk and
| rendered when the theme is active (favicon in <head>, logo in the header, background via CSS).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Storage::fake('public');
});

/** A fake image upload that doesn't need the GD extension (which isn't installed in the gate container). */
function fakeImageUpload(string $name = 'asset.png'): UploadedFile
{
    return UploadedFile::fake()->create($name, 80, 'image/png');
}

it('stores an uploaded asset on the public disk and binds it to the theme', function () {
    $m = app(StyleThemeManager::class);
    $theme = $m->create(['name' => 'Branded']);

    $m->storeAsset($theme, 'logo', fakeImageUpload('logo.png'));

    $path = $theme->fresh()->logo_path;
    expect($path)->not->toBeNull()->toStartWith('theme-assets/');
    Storage::disk('public')->assertExists($path);
});

it('replaces the previous file when a new asset is uploaded', function () {
    $m = app(StyleThemeManager::class);
    $theme = $m->create(['name' => 'Branded']);

    $m->storeAsset($theme, 'logo', fakeImageUpload('a.png'));
    $first = $theme->fresh()->logo_path;
    $m->storeAsset($theme->fresh(), 'logo', fakeImageUpload('b.png'));
    $second = $theme->fresh()->logo_path;

    expect($second)->not->toBe($first);
    Storage::disk('public')->assertMissing($first);   // old file cleaned up
    Storage::disk('public')->assertExists($second);
});

it('clears a bound asset (file removed, column nulled)', function () {
    $m = app(StyleThemeManager::class);
    $theme = $m->create(['name' => 'Branded']);
    $m->storeAsset($theme, 'favicon', fakeImageUpload('f.png'));
    $path = $theme->fresh()->favicon_path;

    $m->clearAsset($theme->fresh(), 'favicon');

    expect($theme->fresh()->favicon_path)->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

it('exposes the active theme logo + favicon URLs and clears on no active theme', function () {
    $m = app(StyleThemeManager::class);
    expect($m->assets())->toBe(['logo' => null, 'favicon' => null]);

    $m->create(['name' => 'A', 'activate' => true]);
    $m->storeAsset($m->active(), 'favicon', fakeImageUpload('f.png'));

    expect($m->assets()['favicon'])->not->toBeNull()
        ->and($m->assets()['logo'])->toBeNull();
});

it('emits a body background rule when a background image is set', function () {
    $m = app(StyleThemeManager::class);
    $theme = $m->create(['name' => 'BG', 'activate' => true]);
    $m->storeAsset($theme, 'background', fakeImageUpload('bg.jpg'));

    expect($m->css())->toContain('background-image:url(');
});

it('deletes bound asset files when the theme is deleted', function () {
    $m = app(StyleThemeManager::class);
    $theme = $m->create(['name' => 'Gone']);
    $m->storeAsset($theme, 'logo', fakeImageUpload('l.png'));
    $path = $theme->fresh()->logo_path;

    $m->delete($theme->fresh());

    Storage::disk('public')->assertMissing($path);
});

it('lets a 2FA admin upload a logo through the editor', function () {
    $this->seed();
    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));

    Livewire::test('admin.settings.themes')
        ->call('newTheme')
        ->set('name', 'Studio')
        ->set('logoUpload', fakeImageUpload('brand.png'))
        ->call('save')
        ->assertHasNoErrors();

    $theme = SiteTheme::where('name', 'Studio')->firstOrFail();
    expect($theme->logo_path)->not->toBeNull();
    Storage::disk('public')->assertExists($theme->logo_path);
});

it('renders the active theme favicon and logo into the page', function () {
    $this->seed();
    Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);

    $m = app(StyleThemeManager::class);
    $m->create(['name' => 'Branded', 'activate' => true]);
    $m->storeAsset($m->active(), 'logo', fakeImageUpload('logo.png'));
    $m->storeAsset($m->active(), 'favicon', fakeImageUpload('fav.png'));

    $this->get(route('forums.index'))->assertOk()
        ->assertSee('rel="icon"', false)
        ->assertSee('/storage/theme-assets', false); // both the favicon href and the logo img point here
});
