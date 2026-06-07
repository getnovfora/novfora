<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

/*
| Appearance settings (default-theme phase, PART 2): per-user colour mode + density. Covers BOTH halves the
| brief asks for — persistence and the rendering effect (the server-applied <html> attributes that make the
| theme work with no JavaScript), plus the guest defaults and validation.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('defaults a new user to auto colour mode and comfortable density', function () {
    $user = Users::inGroups(['members']);

    expect($user->color_mode)->toBe('auto');
    expect($user->density)->toBe('comfortable');
});

it('persists colour mode and density from the settings form', function () {
    $user = Users::inGroups(['members']);

    $this->actingAs($user)
        ->post(route('settings.appearance.save'), ['color_mode' => 'dark', 'density' => 'compact'])
        ->assertRedirect();

    $user->refresh();
    expect($user->color_mode)->toBe('dark');
    expect($user->density)->toBe('compact');
});

it('persists a single field via the JSON quick-toggle path', function () {
    $user = Users::inGroups(['members']);

    $this->actingAs($user)
        ->postJson(route('settings.appearance.save'), ['color_mode' => 'light'])
        ->assertOk()
        ->assertJson(['ok' => true, 'color_mode' => 'light']);

    expect($user->refresh()->color_mode)->toBe('light');
    // The untouched field is left at its default.
    expect($user->density)->toBe('comfortable');
});

it('applies the saved colour mode + density to the rendered <html> (works with no JS)', function () {
    $user = Users::inGroups(['members']);
    $user->color_mode = 'dark';
    $user->density = 'compact';
    $user->save();

    $html = $this->actingAs($user)->get(route('forums.index'))->assertOk()->getContent();

    expect($html)->toContain('data-theme="dark"')
        ->toContain('data-density="compact"')
        ->toContain('data-color-mode="dark"');
});

it('renders auto mode without forcing a data-theme so prefers-color-scheme governs', function () {
    $user = Users::inGroups(['members']); // defaults: auto + comfortable

    $html = $this->actingAs($user)->get(route('forums.index'))->assertOk()->getContent();

    expect($html)->toContain('data-color-mode="auto"')
        ->toContain('data-density="comfortable"')
        ->not->toContain('data-theme='); // auto = no explicit theme attribute
});

it('gives guests the auto/comfortable defaults', function () {
    $html = $this->get(route('forums.index'))->assertOk()->getContent();

    expect($html)->toContain('data-color-mode="auto"')
        ->toContain('data-density="comfortable"')
        ->not->toContain('data-theme=');
});

it('rejects an invalid colour mode', function () {
    $user = Users::inGroups(['members']);

    $this->actingAs($user)
        ->post(route('settings.appearance.save'), ['color_mode' => 'neon', 'density' => 'comfortable'])
        ->assertSessionHasErrors('color_mode');

    expect($user->refresh()->color_mode)->toBe('auto');
});

it('rejects an invalid density', function () {
    $user = Users::inGroups(['members']);

    $this->actingAs($user)
        ->post(route('settings.appearance.save'), ['color_mode' => 'auto', 'density' => 'roomy'])
        ->assertSessionHasErrors('density');
});

it('requires authentication to save appearance', function () {
    $this->post(route('settings.appearance.save'), ['color_mode' => 'dark'])
        ->assertRedirect(route('login'));
});
