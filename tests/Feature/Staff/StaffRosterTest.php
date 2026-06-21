<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Forum;
use App\Models\Group;
use App\Models\ModeratorAssignment;
use App\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

/*
| The public "The Team" roster (/staff, ACP v3 · v3-g). DISPLAY-ONLY. Gated by members.staff_roster_enabled
| (OFF by default → 404, no disclosure). Lists the ACTIVE members of show_on_staff_page groups (seeded on the
| Administrators + Moderators system groups) plus per-user forum-moderators, grouped by canonical staff role;
| never leaks a member of a non-flagged group.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function enableRoster(bool $on = true): void
{
    app(Settings::class)->set('members.staff_roster_enabled', $on);
}

it('404s for everyone when the roster is disabled (the default)', function () {
    $this->get(route('members.staff'))->assertNotFound();
    $this->actingAs(Users::inGroups(['admins']))->get(route('members.staff'))->assertNotFound();
});

it('lists staff grouped by role when enabled', function () {
    enableRoster();
    Users::inGroups(['admins'], ['username' => 'adminalice', 'display_name' => 'Admin Alice']);
    Users::inGroups(['moderators'], ['username' => 'modmandy', 'display_name' => 'Mod Mandy']);

    $this->get(route('members.staff'))
        ->assertOk()
        ->assertSee('Administrators')   // bucket heading
        ->assertSee('adminalice')
        ->assertSee('Moderators')
        ->assertSee('modmandy');
});

it('does not leak members of non-flagged groups', function () {
    enableRoster();
    Users::inGroups(['members', 'tl1'], ['username' => 'regularrick', 'display_name' => 'Regular Rick']);

    $this->get(route('members.staff'))->assertOk()->assertDontSee('regularrick');
});

it('lists a per-user forum moderator under Forum moderators', function () {
    enableRoster();
    $forum = Forum::create(['slug' => 'lounge', 'title' => 'Lounge', 'type' => 'forum']);
    $mod = Users::inGroups(['members', 'tl1'], ['username' => 'forumfred', 'display_name' => 'Forum Fred']);
    ModeratorAssignment::create(['holder_type' => 'user', 'holder_id' => $mod->id, 'forum_id' => $forum->id, 'bundle' => 'forum-mod-full']);

    $this->get(route('members.staff'))
        ->assertOk()
        ->assertSee('Forum moderators')
        ->assertSee('forumfred');
});

it('excludes banned staff', function () {
    enableRoster();
    $banned = Users::inGroups(['admins'], ['username' => 'bannedboss', 'display_name' => 'Banned Boss']);
    $banned->forceFill(['status' => 'banned'])->saveQuietly();

    $this->get(route('members.staff'))->assertOk()->assertDontSee('bannedboss');
});

it('separates co-owners from administrators into their own bucket', function () {
    enableRoster();
    $owner = Users::inGroups(['admins'], ['username' => 'owneroctavia', 'display_name' => 'Owner Octavia']);
    $adminsId = Group::query()->where('slug', 'admins')->value('id');
    $owner->groups()->updateExistingPivot((int) $adminsId, ['is_co_owner' => true]);
    Users::inGroups(['admins'], ['username' => 'plainpaul', 'display_name' => 'Plain Paul']);

    $this->get(route('members.staff'))
        ->assertOk()
        ->assertSee('Co-owners')
        ->assertSee('owneroctavia')
        ->assertSee('Administrators')
        ->assertSee('plainpaul');
});

it('respects a non-flagged system group: unflagging moderators drops them from the roster', function () {
    enableRoster();
    Group::query()->where('slug', 'moderators')->update(['show_on_staff_page' => false]);
    Users::inGroups(['moderators'], ['username' => 'hiddenmod', 'display_name' => 'Hidden Mod']);

    $this->get(route('members.staff'))->assertOk()->assertDontSee('hiddenmod');
});
