<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Forum;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
| RH-8 — the site root is the community home, never Laravel's scaffold welcome page.
|
| routes/web.php used to serve `view('welcome')` at '/', so post-install the site root rendered Laravel's
| marketing page while the forum lived only at /forums. It stayed invisible because pre-install every
| request is redirected to /install and no test ever asserted '/'. The root now 301-redirects to the
| canonical /forums (one canonical URL, no duplicate content); the scaffold view is deleted.
*/

it('permanently (301) redirects the root to the canonical forum index post-install', function () {
    $response = $this->get('/');

    $response->assertStatus(301);
    $response->assertRedirect(route('forums.index'));
});

it('never renders the welcome view — the root is a redirect, not an HTML page', function () {
    // A redirect carries no body, so the scaffold marketing copy can never be served from '/'.
    $this->get('/')->assertStatus(301)->assertHeader('Location', route('forums.index'));
});

it('does not ship the Laravel scaffold welcome view (clean-room hygiene)', function () {
    expect(file_exists(resource_path('views/welcome.blade.php')))->toBeFalse();
});

it('serves the real community home at the canonical /forums', function () {
    $this->seed();
    Forum::create(['slug' => 'general', 'title' => 'General Chat', 'type' => 'forum']);

    $this->get('/forums')->assertOk()->assertSee('Forums')->assertSee('General Chat');
});

it('still redirects the root to the installer pre-install (enforcement ON, unchanged)', function () {
    // The RH-8 change must not weaken installer enforcement: an un-installed site sends '/' to the wizard,
    // exactly as before — the 301-to-/forums only applies once installed / when enforcement is off.
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hearth-rh8-'.bin2hex(random_bytes(6));
    @mkdir($dir, 0775, true);

    config([
        'hearth.install.enforce' => true,
        'hearth.install.marker' => $dir.DIRECTORY_SEPARATOR.'installed', // absent path → not installed
    ]);

    $this->get('/')->assertRedirect(route('install'));
});
