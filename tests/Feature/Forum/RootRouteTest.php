<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Forum;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
| RH-4.1b (ADR-0071) — the forum INDEX is the canonical community home AT the mount root.
|
| RH-8 made '/' a permanent 301 → /forums (one canonical forum URL). RH-4 needs the board list to BE the home
| at the mount root, so a subdirectory install serves it at /community/ (not /community/forums) and a root
| install at /. The `forums.index` route NAME now lives at '/', so every internal route('forums.index') link
| (nav wordmark, breadcrumbs, canonical/OG, the sitemap) generates the mount root automatically. '/forums' is
| kept as a permanent 301 → the root for back-compat with the live beta's links + SEO. Pre-install enforcement
| (RedirectIfNotInstalled → /install) is unchanged — only the INSTALLED root's behaviour changed.
*/

it('serves the forum index directly at the root post-install (200, no redirect)', function () {
    $this->seed();
    Forum::create(['slug' => 'general', 'title' => 'General Chat', 'type' => 'forum']);

    $this->get('/')
        ->assertOk()
        ->assertSee('Forums')
        ->assertSee('General Chat');
});

it('keeps forums.index pointing at the mount root so every internal link generates it', function () {
    // The whole RH-4.1b design rests on this: the route NAME maps to '/', not '/forums'. A subdirectory
    // install then auto-prefixes it (e.g. /community/) without touching any view.
    expect(route('forums.index', [], false))->toBe('/');
});

it('permanently (301) redirects /forums back to the canonical root', function () {
    $this->get('/forums')
        ->assertStatus(301)
        ->assertRedirect(route('forums.index'));
});

it('does not ship the Laravel scaffold welcome view (clean-room hygiene)', function () {
    expect(file_exists(resource_path('views/welcome.blade.php')))->toBeFalse();
});

it('still redirects the root to the installer pre-install (enforcement ON, unchanged)', function () {
    // RH-4.1b must not weaken installer enforcement: an un-installed site sends '/' to the wizard, exactly as
    // before — the forum index only serves at '/' once installed / when enforcement is off.
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'novfora-rh4-'.bin2hex(random_bytes(6));
    @mkdir($dir, 0775, true);

    config([
        'novfora.install.enforce' => true,
        'novfora.install.marker' => $dir.DIRECTORY_SEPARATOR.'installed', // absent path → not installed
    ]);

    $this->get('/')->assertRedirect(route('install'));
});
