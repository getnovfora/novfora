<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\Forum;
use App\Models\Group;
use App\Models\ModeratorAssignment;
use App\Models\User;
use App\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| Staff flair (ACP v3 · v3-g). DISPLAY-ONLY: User::staffRole() derives a canonical role from the user's groups +
| co-owner flag + per-forum moderator assignments, the <x-ui.staff-flair> component renders it (gated by the
| members.staff_flair_show_badge setting), and the ACP toggle SFC manages the two settings. No acl_entries touch.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

/** Flag the admins-group membership of $user as a co-owner (the is_co_owner pivot — AdminCoOwnerService truth). */
function makeCoOwner(User $user): void
{
    $adminsId = Group::query()->where('slug', 'admins')->value('id');
    $user->groups()->updateExistingPivot((int) $adminsId, ['is_co_owner' => true]);
}

// ── User::staffRole() resolution ───────────────────────────────────────────────────────────────────────────
it('resolves administrator for an admins-group member', function () {
    expect(Users::inGroups(['admins'])->staffRole())->toBe('administrator');
});

it('resolves co_owner for an admins-group member carrying the co-owner flag', function () {
    $owner = Users::inGroups(['admins']);
    makeCoOwner($owner);

    expect($owner->fresh()->staffRole())->toBe('co_owner');
});

it('resolves moderator for a moderators-group member', function () {
    expect(Users::inGroups(['moderators'])->staffRole())->toBe('moderator');
});

it('resolves forum_moderator for a member holding a per-user moderator assignment', function () {
    $forum = Forum::create(['slug' => 'f', 'title' => 'F', 'type' => 'forum']);
    $mod = Users::inGroups(['members', 'tl1']);
    ModeratorAssignment::create(['holder_type' => 'user', 'holder_id' => $mod->id, 'forum_id' => $forum->id, 'bundle' => 'forum-mod-full']);

    expect($mod->fresh()->staffRole())->toBe('forum_moderator');
});

it('returns null for a regular member', function () {
    expect(Users::inGroups(['members', 'tl1'])->staffRole())->toBeNull();
});

it('prefers the higher role: an admin who is also a forum-mod is administrator, not forum_moderator', function () {
    $forum = Forum::create(['slug' => 'f2', 'title' => 'F2', 'type' => 'forum']);
    $admin = Users::inGroups(['admins']);
    ModeratorAssignment::create(['holder_type' => 'user', 'holder_id' => $admin->id, 'forum_id' => $forum->id, 'bundle' => 'forum-mod-full']);

    expect($admin->fresh()->staffRole())->toBe('administrator');
});

// ── <x-ui.staff-flair> component ───────────────────────────────────────────────────────────────────────────
it('renders the role label for staff and nothing for a member or guest', function () {
    $admin = Users::inGroups(['admins']);
    $member = Users::inGroups(['members', 'tl1']);

    expect(Blade::render('<x-ui.staff-flair :user="$user" />', ['user' => $admin]))->toContain('Administrator');
    expect(trim(Blade::render('<x-ui.staff-flair :user="$user" />', ['user' => $member])))->toBe('');
    expect(trim(Blade::render('<x-ui.staff-flair :user="$user" />', ['user' => null])))->toBe('');
});

it('suppresses the flair entirely when members.staff_flair_show_badge is off', function () {
    app(Settings::class)->set('members.staff_flair_show_badge', false);

    expect(trim(Blade::render('<x-ui.staff-flair :user="$user" />', ['user' => Users::inGroups(['admins'])])))->toBe('');
});

it('uses a per-group staff_title override when set on the staff group', function () {
    Group::query()->where('slug', 'admins')->update(['staff_title' => 'Site Owner']);
    $admin = Users::inGroups(['admins']);

    $html = Blade::render('<x-ui.staff-flair :user="$user" />', ['user' => $admin]);
    expect($html)->toContain('Site Owner')->and($html)->not->toContain('Administrator');
});

it('renders the staff flair on a topic page for a staff author', function () {
    $forum = Forum::create(['slug' => 'tp', 'title' => 'TP', 'type' => 'forum']);
    $admin = Users::inGroups(['admins'], ['username' => 'bossadmin']);
    $topic = app(PostService::class)->createTopic($admin, $forum, 'Staff thread', 'tiptap_json', Content::doc('hello'));

    $this->get(route('topics.show', $topic))->assertOk()->assertSee('Administrator');
});

// ── ACP toggle SFC (Admin → Members → Staff flair) ─────────────────────────────────────────────────────────
it('renders the staff-flair ACP page for a 2FA admin and self-guards the SFC', function () {
    $this->get(route('admin.members.staff-flair'))->assertRedirect(route('login')); // guest

    $this->actingAs(Users::inGroups(['members', 'tl0']))
        ->get(route('admin.members.staff-flair'))->assertForbidden(); // non-admin

    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])))
        ->get(route('admin.members.staff-flair'))
        ->assertOk()
        ->assertSee('Show staff role badges');

    // The component self-guards (no route middleware on livewire/update).
    $this->actingAs(Users::inGroups(['members', 'tl0']));
    Livewire::test('admin.settings.staff-flair')->assertStatus(403);
});

it('persists both toggles through the ACP SFC', function () {
    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));

    Livewire::test('admin.settings.staff-flair')
        ->set('rosterEnabled', true)
        ->set('showBadge', false)
        ->call('save');

    $settings = app(Settings::class);
    expect($settings->bool('members.staff_roster_enabled'))->toBeTrue()
        ->and($settings->bool('members.staff_flair_show_badge'))->toBeFalse();
});
