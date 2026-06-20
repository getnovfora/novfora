<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\AclEntry;
use App\Models\Group;
use App\Models\ModeratorAssignment;
use App\Models\User;
use App\Permissions\PermissionValue as V;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Acl;
use Tests\Support\Users;

/*
| ACP v3 · v3-b — the ⚡forum-moderators tab SFC. Self-guards in mount()/every action; the ForumModeratorProjector
| is the actor-independent fence backstop (the 7-case oracle lives in ForumModeratorProjectorTest). Here we pin
| the UI surface: the gate, assign (user + group), revoke, and that a ceiling throw is caught + flashed.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function fmAdmin(): User
{
    return Users::withTwoFactor(Users::inGroups(['admins']));
}

/** A non-admin permissions.manager: reaches the gate (admin.access + permissions.manage) but is NOT staff. */
function fmPermManager(Acl $acl): User
{
    $pm = $acl->group('permmgr', ['priority' => 50]);
    $acl->grant($pm, 'admin.access', $acl->global, V::Allow);
    $acl->grant($pm, 'permissions.manage', $acl->global, V::Allow);

    return $acl->user(['permmgr']);
}

// ── Route page-load gate ─────────────────────────────────────────────────────────────────────────────────

it('redirects a guest away from the moderators page', function () {
    $acl = Acl::make();
    $this->get(route('admin.forums.moderators', $acl->forum))->assertRedirect();
});

it('forbids a logged-in non-admin from the moderators page', function () {
    $acl = Acl::make();
    $this->actingAs(Users::inGroups(['members']))->get(route('admin.forums.moderators', $acl->forum))->assertForbidden();
});

it('loads the moderators page for a 2FA admin', function () {
    $acl = Acl::make();
    $this->actingAs(fmAdmin())->get(route('admin.forums.moderators', $acl->forum))->assertOk()->assertSee('Current moderators');
});

// ── Mount self-guard ─────────────────────────────────────────────────────────────────────────────────────

it('403s the SFC for a logged-in non-admin', function () {
    $acl = Acl::make();
    Livewire::actingAs(Users::inGroups(['members']))->test('admin.forum-moderators', ['forumId' => $acl->forum->id])->assertForbidden();
});

it('403s the SFC for an admin without confirmed 2FA', function () {
    $acl = Acl::make();
    Livewire::actingAs(Users::inGroups(['admins']))->test('admin.forum-moderators', ['forumId' => $acl->forum->id])->assertForbidden();
});

// ── Assign / revoke ──────────────────────────────────────────────────────────────────────────────────────

it('assigns a USER as a forum moderator through the tab', function () {
    $acl = Acl::make();
    $target = Users::inGroups(['members'], ['username' => 'targetmod', 'email' => 'targetmod@fm.test']);

    Livewire::actingAs(fmAdmin())->test('admin.forum-moderators', ['forumId' => $acl->forum->id])
        ->set('holderType', 'user')
        ->set('username', 'targetmod')
        ->set('source', 'bundle')
        ->set('bundle', 'forum-mod-full')
        ->call('assign')
        ->assertSet('messageVariant', 'success');

    expect(ModeratorAssignment::where('holder_type', 'user')->where('holder_id', $target->id)->where('forum_id', $acl->forum->id)->exists())->toBeTrue();
    expect(AclEntry::where('holder_type', 'user')->where('holder_id', $target->id)
        ->where('scope_type', 'forum')->where('scope_id', $acl->forum->id)->where('permission_key', 'topic.moderate')->exists())->toBeTrue();
});

it('assigns a GROUP as a forum moderator through the tab', function () {
    $acl = Acl::make();
    $crew = Group::firstOrCreate(['slug' => 'crew-fm'], ['name' => 'Crew', 'type' => 'custom']);

    Livewire::actingAs(fmAdmin())->test('admin.forum-moderators', ['forumId' => $acl->forum->id])
        ->set('holderType', 'group')
        ->set('groupId', $crew->id)
        ->set('source', 'bundle')
        ->set('bundle', 'forum-mod-content')
        ->call('assign')
        ->assertSet('messageVariant', 'success');

    expect(ModeratorAssignment::where('holder_type', 'group')->where('holder_id', $crew->id)->where('forum_id', $acl->forum->id)->exists())->toBeTrue();
});

it('revokes a moderator through the tab', function () {
    $acl = Acl::make();
    $target = Users::inGroups(['members'], ['username' => 'revokeme', 'email' => 'revokeme@fm.test']);

    $component = Livewire::actingAs(fmAdmin())->test('admin.forum-moderators', ['forumId' => $acl->forum->id])
        ->set('username', 'revokeme')->set('bundle', 'forum-mod-full')->call('assign');
    expect(ModeratorAssignment::where('holder_id', $target->id)->where('forum_id', $acl->forum->id)->exists())->toBeTrue();

    $component->call('revoke', 'user', $target->id)->assertSet('messageVariant', 'success');
    expect(ModeratorAssignment::where('holder_id', $target->id)->where('forum_id', $acl->forum->id)->exists())->toBeFalse();
});

it('flashes a danger message and assigns nothing when an unknown username is given', function () {
    $acl = Acl::make();

    Livewire::actingAs(fmAdmin())->test('admin.forum-moderators', ['forumId' => $acl->forum->id])
        ->set('username', 'nobody-here')->set('bundle', 'forum-mod-full')->call('assign')
        ->assertSet('messageVariant', 'danger');

    expect(ModeratorAssignment::where('forum_id', $acl->forum->id)->count())->toBe(0);
});

// ── Ceiling fence surfaces as a flash ────────────────────────────────────────────────────────────────────

it('refuses (flash) a permissions.manager assigning a bundle beyond their forum ceiling', function () {
    $acl = Acl::make();
    $pm = fmPermManager($acl); // holds admin.access + permissions.manage, but NOT the moderation keys
    $target = Users::inGroups(['members'], ['username' => 'overreach-target', 'email' => 'ot@fm.test']);

    Livewire::actingAs($pm)->test('admin.forum-moderators', ['forumId' => $acl->forum->id])
        ->set('username', 'overreach-target')->set('bundle', 'forum-mod-content')->call('assign')
        ->assertSet('messageVariant', 'danger');

    expect(ModeratorAssignment::where('forum_id', $acl->forum->id)->count())->toBe(0);
});
