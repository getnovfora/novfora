<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\ModeratorAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Acl;
use Tests\Support\Users;

/*
| ACP v3 · v3-b — the global ⚡moderators pane (Moderation → Moderators). Same projector + fences as the per-forum
| tab; here we pin the page gate, a cross-forum assign, the grouped overview, and remove.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function paneAdmin(): User
{
    return Users::withTwoFactor(Users::inGroups(['admins']));
}

it('redirects a guest and forbids a non-admin from the global moderators page', function () {
    $this->get(route('admin.moderators'))->assertRedirect();
    $this->actingAs(Users::inGroups(['members']))->get(route('admin.moderators'))->assertForbidden();
});

it('loads the global moderators page for a 2FA admin', function () {
    $this->actingAs(paneAdmin())->get(route('admin.moderators'))->assertOk();
});

it('403s the SFC for a logged-in non-admin', function () {
    Livewire::actingAs(Users::inGroups(['members']))->test('admin.moderators')->assertForbidden();
});

it('assigns a moderator to a chosen forum and lists it grouped by forum', function () {
    $acl = Acl::make();
    $target = Users::inGroups(['members'], ['username' => 'panemod', 'email' => 'panemod@v3b.test']);

    Livewire::actingAs(paneAdmin())->test('admin.moderators')
        ->set('forumId', $acl->forum->id)
        ->set('holderType', 'user')
        ->set('username', 'panemod')
        ->set('bundle', 'forum-mod-full')
        ->call('assign')
        ->assertSet('messageVariant', 'success')
        ->assertSee('panemod');

    expect(ModeratorAssignment::where('holder_id', $target->id)->where('forum_id', $acl->forum->id)->exists())->toBeTrue();
});

it('removes a moderator across forums from the global pane', function () {
    $acl = Acl::make();
    $target = Users::inGroups(['members'], ['username' => 'paneremove', 'email' => 'paneremove@v3b.test']);

    $c = Livewire::actingAs(paneAdmin())->test('admin.moderators')
        ->set('forumId', $acl->forum->id)->set('username', 'paneremove')->set('bundle', 'forum-mod-content')->call('assign');
    expect(ModeratorAssignment::where('holder_id', $target->id)->where('forum_id', $acl->forum->id)->exists())->toBeTrue();

    $c->call('revoke', 'user', $target->id, $acl->forum->id)->assertSet('messageVariant', 'success');
    expect(ModeratorAssignment::where('holder_id', $target->id)->where('forum_id', $acl->forum->id)->exists())->toBeFalse();
});

it('requires a forum to be chosen before assigning', function () {
    Livewire::actingAs(paneAdmin())->test('admin.moderators')
        ->set('username', 'whoever')->set('bundle', 'forum-mod-full')->call('assign')
        ->assertSet('messageVariant', 'danger');

    expect(ModeratorAssignment::count())->toBe(0);
});
