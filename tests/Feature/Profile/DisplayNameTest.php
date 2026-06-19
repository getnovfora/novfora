<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

/*
| BUG-019 (locked 2026-06-19): profile settings gain a Display name field. Username stays READ-ONLY this
| pass — no username input, no username_changed_at cooldown, no redirect logic.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('lets a member set their display name; it persists and shows on the profile (BUG-019)', function () {
    $user = Users::inGroups(['members', 'tl1'], ['username' => 'jdoe', 'display_name' => 'jdoe']);

    $this->actingAs($user)->post(route('settings.profile.save'), [
        'display_name' => 'Jane Doe',
    ])->assertRedirect();

    expect($user->fresh()->display_name)->toBe('Jane Doe');

    $this->get(route('profiles.show', $user->fresh()))->assertOk()->assertSee('Jane Doe');
});

it('clearing the display name nulls it (the profile then falls back to @username) (BUG-019)', function () {
    $user = Users::inGroups(['members', 'tl1'], ['username' => 'jdoe', 'display_name' => 'Jane Doe']);

    $this->actingAs($user)->post(route('settings.profile.save'), [
        'display_name' => '',
    ])->assertRedirect();

    expect($user->fresh()->display_name)->toBeNull();
});

it('exposes a display-name input but no username input (username read-only) (BUG-019)', function () {
    $user = Users::inGroups(['members', 'tl1'], ['username' => 'jdoe']);

    $html = $this->actingAs($user)->get(route('settings.profile'))->assertOk()->getContent();

    expect($html)->toContain('name="display_name"')
        ->and($html)->not->toContain('name="username"');
});
