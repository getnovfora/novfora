<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

/*
| ADR-0079 i18n guard for the profiles domain: keys resolve and the public profile + edit pages render English
| with no raw "profiles.*" token.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('resolves the profiles-domain keys instead of the raw token', function () {
    $keys = ['edit_title', 'shell_title', 'avatar', 'cover_image', 'save_profile', 'trust_level', 'reputation', 'about', 'signature', 'staff_tools', 'delete_account', 'badges'];

    foreach ($keys as $key) {
        expect(trans("profiles.{$key}"))->not->toBe("profiles.{$key}");
    }

    expect(trans('profiles.edit_intro', ['app' => 'Acme']))->toContain('across Acme');
});

it('renders a public profile in English with no raw profiles.* token', function () {
    $user = Users::inGroups(['members', 'tl1'], ['username' => 'profileviewuser']);

    $this->get(route('profiles.show', $user))
        ->assertOk()
        ->assertSee('Trust level')
        ->assertSee('reputation')
        ->assertDontSee('profiles.trust_level');
});

it('renders the profile edit page in English (authenticated)', function () {
    $user = Users::inGroups(['members', 'tl1'], ['username' => 'editprofileuser']);

    $this->actingAs($user)->get(route('settings.profile'))
        ->assertOk()
        ->assertSee('Save profile')
        ->assertSee('Avatar')
        ->assertSee('Cover image')
        ->assertDontSee('profiles.save_profile');
});
