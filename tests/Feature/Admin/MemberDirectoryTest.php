<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Group;
use App\Models\User;
use App\Permissions\PermissionValue as V;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Acl;
use Tests\Support\Users;

/*
| ACP v4 · A1 (ADR-0096) — Admin → Members → All members. The directory is server-filtered,
| server-sorted, and guarded inside Livewire because /livewire/update bypasses route middleware.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function a1FullAdmin(): User
{
    return Users::withTwoFactor(Users::inGroups(['admins']));
}

function a1RestrictedViewer(Acl $acl): User
{
    $grp = $acl->group('a1viewer', ['priority' => 40]);
    $acl->grant($grp, 'admin.access', $acl->global, V::Allow);
    $acl->grant($grp, 'admin.members.access', $acl->global, V::Allow);

    return $acl->user(['a1viewer']);
}

it('gates the route and livewire component with admin members access plus staff 2fa', function () {
    $this->get(route('admin.members.index'))->assertRedirect(route('login'));
    $this->actingAs(Users::inGroups(['members']))->get(route('admin.members.index'))->assertForbidden();
    // Route level, staff without a confirmed second factor are BOUNCED to 2FA setup (RequireTwoFactorForStaff),
    // not 403'd — the hard 403 for a non-2FA admin is the Livewire-internal guard asserted below.
    $this->actingAs(Users::inGroups(['admins']))->get(route('admin.members.index'))->assertRedirect(route('settings.two-factor'));

    Livewire::actingAs(Users::inGroups(['members']))->test('admin.members')->assertForbidden();
    Livewire::actingAs(Users::inGroups(['admins']))->test('admin.members')->assertForbidden();

    $this->actingAs(a1FullAdmin())->get(route('admin.members.index'))
        ->assertOk()
        ->assertSee('All members')
        ->assertSee('Last active');
});

it('filters searches and sorts members server side', function () {
    $admin = a1FullAdmin();
    $group = Group::firstOrCreate(['slug' => 'a1group'], ['name' => 'A1 Group', 'type' => 'custom', 'is_public' => true]);
    $match = User::factory()->create([
        'username' => 'zz-a1-match',
        'email' => 'match-a1@example.test',
        'trust_level' => 3,
        'created_at' => now()->subDays(3),
        'last_active_at' => now()->subHour(),
    ]);
    $other = User::factory()->create(['username' => 'aa-a1-other', 'trust_level' => 1, 'created_at' => now()->subDays(10)]);
    $match->groups()->attach($group->id, ['is_primary' => true]);

    Livewire::actingAs($admin)->test('admin.members')
        ->set('search', 'match-a1@example.test')
        ->set('group', (string) $group->id)
        ->set('trust', '3')
        ->set('joinedFrom', now()->subDays(4)->toDateString())
        ->set('joinedTo', now()->subDays(2)->toDateString())
        ->assertSee('zz-a1-match')
        ->assertDontSee('aa-a1-other')
        ->call('sortBy', 'username')
        ->assertSet('sort', 'username')
        ->assertSet('dir', 'asc')
        ->call('sortBy', 'definitely_not_a_column')
        ->assertSet('sort', 'username');
});

it('does not leak email or hidden group filters to a restricted directory viewer', function () {
    $acl = Acl::make();
    $viewer = a1RestrictedViewer($acl);
    $hidden = Group::firstOrCreate(['slug' => 'secret-a1'], ['name' => 'Secret A1', 'type' => 'custom', 'is_public' => false]);
    $target = User::factory()->create(['username' => 'secret-member-a1', 'email' => 'secret-a1@example.test']);
    $target->groups()->attach($hidden->id, ['is_primary' => true]);

    Livewire::actingAs($viewer)->test('admin.members')
        ->assertDontSee('Email')
        ->assertDontSee('secret-a1@example.test')
        ->assertDontSee('Secret A1')
        ->set('search', 'secret-a1@example.test')
        ->assertDontSee('secret-member-a1')
        ->set('search', '')
        ->set('group', (string) $hidden->id)
        ->assertDontSee('secret-member-a1');
});

it('shows row actions only when the actor has the matching capability and rank', function () {
    $full = a1FullAdmin();
    $target = Users::inGroups(['members'], ['username' => 'row-actions-a1']);

    Livewire::actingAs($full)->test('admin.members')
        ->assertSee('member-view-'.$target->id, false)
        ->assertSee('member-edit-'.$target->id, false)
        ->assertSee('member-ban-'.$target->id, false)
        ->assertSee('member-warn-'.$target->id, false)
        ->assertDontSee('member-ban-'.$full->id, false);

    $acl = Acl::make();
    $viewer = a1RestrictedViewer($acl);
    Livewire::actingAs($viewer)->test('admin.members')
        ->assertSee('member-view-'.$target->id, false)
        ->assertDontSee('member-edit-'.$target->id, false)
        ->assertDontSee('member-ban-'.$target->id, false)
        ->assertDontSee('member-warn-'.$target->id, false);
});
