<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\CannedReply;
use App\Models\User;
use App\Permissions\PermissionValue as V;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Acl;
use Tests\Support\Users;

/*
| T1 — the ⚡canned-replies ACP CRUD. Gated admin.access + bans.manage + staff-2FA (a moderation tool). Body is
| a textarea stored as a canonical doc (CannedReply::textToDoc), round-tripped on edit (docToText).
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function crAdmin(): User
{
    return Users::withTwoFactor(Users::inGroups(['admins'])); // admin.access + bans.manage
}

it('forbids guests + non-admins from the canned-replies route', function () {
    $this->get(route('admin.moderation.canned-replies'))->assertRedirect();
    $this->actingAs(Users::inGroups(['members']))->get(route('admin.moderation.canned-replies'))->assertForbidden();
});

it('403s the component for an admin without bans.manage', function () {
    $acl = Acl::make();
    $grp = $acl->group('nobans', ['priority' => 40]);
    $acl->grant($grp, 'admin.access', $acl->global, V::Allow); // admin.access but NOT bans.manage
    Livewire::actingAs($acl->user(['nobans']))->test('admin.canned-replies')->assertForbidden();
});

it('creates a canned reply, storing the body as a canonical doc', function () {
    Livewire::actingAs(crAdmin())->test('admin.canned-replies')
        ->call('create')
        ->set('title', 'Welcome')
        ->set('body', "Hello there\nWelcome to the forum")
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Welcome');

    $reply = CannedReply::where('title', 'Welcome')->firstOrFail();
    expect($reply->body_canonical['type'])->toBe('doc')
        ->and(count($reply->body_canonical['content']))->toBe(2) // two paragraphs (two lines)
        ->and(CannedReply::docToText((array) $reply->body_canonical))->toBe("Hello there\nWelcome to the forum");
});

it('edits a canned reply (round-trips the body text)', function () {
    $reply = CannedReply::create(['title' => 'Old', 'body_canonical' => CannedReply::textToDoc('first body'), 'is_active' => true]);

    Livewire::actingAs(crAdmin())->test('admin.canned-replies')
        ->call('edit', $reply->id)
        ->assertSet('body', 'first body') // docToText round-trip into the textarea
        ->set('title', 'Renamed')
        ->set('body', 'updated body')
        ->call('save')->assertHasNoErrors();

    expect($reply->fresh()->title)->toBe('Renamed')
        ->and(CannedReply::docToText((array) $reply->fresh()->body_canonical))->toBe('updated body');
});

it('toggles active + deletes a canned reply', function () {
    $reply = CannedReply::create(['title' => 'Temp', 'body_canonical' => CannedReply::textToDoc('x'), 'is_active' => true]);
    Livewire::actingAs(crAdmin())->test('admin.canned-replies')->call('toggleActive', $reply->id);
    expect($reply->fresh()->is_active)->toBeFalse();
    Livewire::actingAs(crAdmin())->test('admin.canned-replies')->call('delete', $reply->id);
    expect(CannedReply::find($reply->id))->toBeNull();
});

it('requires a title and body', function () {
    Livewire::actingAs(crAdmin())->test('admin.canned-replies')
        ->call('create')->set('title', '')->set('body', '')->call('save')
        ->assertHasErrors(['title', 'body']);
});
