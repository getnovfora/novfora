<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\AclEntry;
use App\Models\Forum;
use App\Models\User;
use App\Permissions\PermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| The two confirmation surfaces for account deletion (ADR-0025): the voluntary ⚡delete-account settings SFC
| (own-only, password re-auth + explicit confirm) and the admin-forced controller flow (bans.manage + rank +
| the deletion-specific guards). The cascade correctness itself is covered in AccountDeletionTest.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    app(PermissionResolver::class)->flushMemo();
    $this->seed();
});

function uiForum(): Forum
{
    return Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
}

function uiGrantBansManage(User $user): void
{
    AclEntry::create([
        'permission_key' => 'bans.manage', 'holder_type' => 'user', 'holder_id' => $user->getKey(),
        'scope_type' => 'global', 'scope_id' => null, 'value' => 1,
    ]);
    app(PermissionResolver::class)->flushMemo();
}

// ── voluntary path (⚡delete-account SFC) ──────────────────────────────────────────────────────────

it('voluntary: a wrong password deletes nothing and reports an error', function () {
    uiForum();
    $user = Users::inGroups(['members', 'tl1'], ['password' => 'correct-horse']);
    $uid = (int) $user->id;

    Livewire::actingAs($user)->test('settings.delete-account')
        ->call('arm')
        ->set('password', 'WRONG-password')
        ->set('confirm', true)
        ->call('deleteAccount')
        ->assertSet('error', 'That password is incorrect.')
        ->assertNoRedirect();

    expect(User::find($uid))->not->toBeNull();
});

it('voluntary: an unticked confirmation deletes nothing', function () {
    uiForum();
    $user = Users::inGroups(['members', 'tl1'], ['password' => 'correct-horse']);
    $uid = (int) $user->id;

    Livewire::actingAs($user)->test('settings.delete-account')
        ->call('arm')
        ->set('password', 'correct-horse')
        ->set('confirm', false)
        ->call('deleteAccount')
        ->assertNoRedirect();

    expect(User::find($uid))->not->toBeNull();
});

it('voluntary: the correct password + confirmation deletes the account and redirects home', function () {
    uiForum();
    $user = Users::inGroups(['members', 'tl1'], ['password' => 'correct-horse']);
    $uid = (int) $user->id;

    Livewire::actingAs($user)->test('settings.delete-account')
        ->call('arm')
        ->set('password', 'correct-horse')
        ->set('confirm', true)
        ->call('deleteAccount')
        ->assertRedirect('/');

    expect(User::find($uid))->toBeNull();
});

it('voluntary: the settings Account page renders the delete component', function () {
    uiForum();
    $user = Users::inGroups(['members', 'tl1']);

    $this->actingAs($user)->get(route('settings.account'))
        ->assertOk()
        ->assertSee('Delete account')
        ->assertSee('Delete my account');
});

// ── admin-forced path (controller + route) ────────────────────────────────────────────────────────

it('admin-forced: a non-staff member cannot reach the confirm page', function () {
    uiForum();
    $member = Users::inGroups(['members', 'tl1']);
    $target = Users::inGroups(['members', 'tl1']);

    $this->actingAs($member)->get(route('moderation.user-delete.confirm', $target))->assertForbidden();
});

it('admin-forced: staff see the confirm summary and the DELETE removes the account', function () {
    uiForum();
    $admin = Users::inGroups(['admins']);
    uiGrantBansManage($admin);
    $target = Users::inGroups(['members', 'tl1']);
    $tid = (int) $target->id;

    $this->actingAs($admin)->get(route('moderation.user-delete.confirm', $target))
        ->assertOk()
        ->assertSee('Permanently delete account');

    $this->actingAs($admin)
        ->delete(route('moderation.user-delete', $target), ['confirm' => '1'])
        ->assertRedirect();

    expect(User::find($tid))->toBeNull();
});

it('admin-forced: the DELETE requires the explicit confirmation field', function () {
    uiForum();
    $admin = Users::inGroups(['admins']);
    uiGrantBansManage($admin);
    $target = Users::inGroups(['members', 'tl1']);
    $tid = (int) $target->id;

    $this->actingAs($admin)->from(route('profiles.show', $target))
        ->delete(route('moderation.user-delete', $target), [])
        ->assertSessionHasErrors('confirm');

    expect(User::find($tid))->not->toBeNull();
});

it('admin-forced: an admin cannot force-delete an equal-or-higher admin via the route', function () {
    uiForum();
    $admin1 = Users::inGroups(['admins']);
    uiGrantBansManage($admin1);
    $admin2 = Users::inGroups(['admins']);

    $this->actingAs($admin1)->get(route('moderation.user-delete.confirm', $admin2))->assertForbidden();
    $this->actingAs($admin1)->delete(route('moderation.user-delete', $admin2), ['confirm' => '1'])->assertForbidden();

    expect(User::find($admin2->id))->not->toBeNull();
});

it('profile staff tools: the delete trigger shows to authorised staff and is hidden otherwise', function () {
    uiForum();
    $admin = Users::inGroups(['admins']);
    uiGrantBansManage($admin);
    $member = Users::inGroups(['members', 'tl1']);
    $target = Users::inGroups(['members', 'tl1']);

    // staff who may act on this member → sees the trigger
    $this->actingAs($admin)->get(route('profiles.show', $target))->assertOk()->assertSee('Delete account');
    // a regular member → no trigger
    $this->actingAs($member)->get(route('profiles.show', $target))->assertOk()->assertDontSee('Delete account');
    // never on one's own profile (no self-delete via the forced path)
    $this->actingAs($admin)->get(route('profiles.show', $admin))->assertOk()->assertDontSee('Delete account');
});
