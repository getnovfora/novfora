<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| Resolution + self-guard smoke for the two ACP visual-theme / members-directory ⚡ SFCs. Mirrors the
| GroupManagerTest render test: the page route is admin-gated (guest → login, non-admin → 403, 2FA admin →
| 200), and the Livewire component self-guards in mount() because actions reach it via livewire/update with
| no route middleware. Asserting the components RENDER also proves the ⚡-prefixed Volt SFCs actually resolve.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('renders the admin themes page for a 2FA admin and self-guards the SFC', function () {
    $this->get(route('admin.settings.themes'))->assertRedirect(route('login')); // guest

    $this->actingAs(Users::inGroups(['members', 'tl0']))
        ->get(route('admin.settings.themes'))->assertForbidden(); // non-admin

    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])))
        ->get(route('admin.settings.themes'))
        ->assertOk()
        ->assertSee('New theme');

    // The component self-guards (no route middleware on livewire/update).
    $this->actingAs(Users::inGroups(['members', 'tl0']));
    Livewire::test('admin.settings.themes')->assertStatus(403);
});

it('renders the admin members-directory page for a 2FA admin and self-guards the SFC', function () {
    $this->get(route('admin.members.directory'))->assertRedirect(route('login')); // guest

    $this->actingAs(Users::inGroups(['members', 'tl0']))
        ->get(route('admin.members.directory'))->assertForbidden(); // non-admin

    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])))
        ->get(route('admin.members.directory'))
        ->assertOk()
        ->assertSee('Who can view the members directory');

    $this->actingAs(Users::inGroups(['members', 'tl0']));
    Livewire::test('admin.settings.members-directory')->assertStatus(403);
});
