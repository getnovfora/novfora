<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

/*
| The public members directory (/members). Visibility is admin-controlled via members.directory_visibility:
| everyone (incl. guests) → members (signed-in) → staff → disabled (404 for all). A non-visible viewer gets a
| 404 (no disclosure). Only ACTIVE members are listed.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function setDirectoryVisibility(string $mode): void
{
    app(Settings::class)->set('members.directory_visibility', $mode);
}

it('shows the directory to guests when visibility is everyone', function () {
    setDirectoryVisibility('everyone');

    $this->get(route('members.index'))->assertOk();
});

it('404s for everyone (incl. staff) when the directory is disabled', function () {
    setDirectoryVisibility('disabled');

    $this->get(route('members.index'))->assertNotFound();
    $this->actingAs(Users::inGroups(['moderators']))->get(route('members.index'))->assertNotFound();
});

it('hides from guests but shows to members when members-only', function () {
    setDirectoryVisibility('members');

    $this->get(route('members.index'))->assertNotFound();
    $this->actingAs(Users::inGroups(['members', 'tl0']))->get(route('members.index'))->assertOk();
});

it('restricts to staff when staff-only', function () {
    setDirectoryVisibility('staff');

    $this->actingAs(Users::inGroups(['members', 'tl0']))->get(route('members.index'))->assertNotFound();
    $this->actingAs(Users::inGroups(['moderators']))->get(route('members.index'))->assertOk();
});

it('lists active members and excludes banned ones', function () {
    setDirectoryVisibility('everyone');

    Users::inGroups(['members'], ['username' => 'zerotwoactive', 'display_name' => 'Active Person']);
    $banned = Users::inGroups(['members'], ['username' => 'bannedbobxyz', 'display_name' => 'Banned Bob']);
    $banned->forceFill(['status' => 'banned'])->saveQuietly();

    $this->get(route('members.index'))
        ->assertOk()
        ->assertSee('zerotwoactive')
        ->assertDontSee('bannedbobxyz');
});
