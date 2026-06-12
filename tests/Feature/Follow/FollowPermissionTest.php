<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Permissions\PermissionValue as V;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Acl;

/*
| Permission-mask coverage for follow.create (TL-gated, SOFT) and follow.delete (ungated) — P2-M5.
| follow.create is withheld from the base member preset and granted from TL1 via $trusted, so TL0 is denied
| by default (not by a NEVER) and an admin can lift it; staff (moderator preset) get it regardless of trust.
| follow.delete is granted to the member preset — undoing your own follow is always allowed, so a user
| demoted back to TL0 can still unfollow. Mirrors PollPermissionTest (the same soft-gate doctrine).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    $this->seed();
});

it('denies follow.create to a TL0 member (soft gate, deny-by-default)', function () {
    $acl = Acl::make();
    $acl->assertDecision($acl->user(['members', 'tl0']), 'follow.create', $acl->global, false, 'default');
});

it('grants follow.create to a TL1 member (progressive grant via $trusted)', function () {
    $acl = Acl::make();
    $acl->assertDecision($acl->user(['members', 'tl1']), 'follow.create', $acl->global, true, 'group_allow');
});

it('grants follow.create to staff regardless of trust level (soft gate does not block ALLOW)', function () {
    $acl = Acl::make();
    // A misassigned TL0 moderator still follows — tl0 carries only a soft NO, which ALLOW beats.
    $acl->assertDecision($acl->user(['moderators', 'tl0']), 'follow.create', $acl->global, true);
});

it('is admin-liftable: granting follow.create to members lifts the TL0 soft gate', function () {
    $acl = Acl::make();
    $member = $acl->user(['members', 'tl0']);
    $acl->grant('members', 'follow.create', $acl->global, V::Allow); // an admin opens it for everyone
    $acl->assertDecision($member, 'follow.create', $acl->global, true, 'group_allow');
});

it('grants follow.delete to any member, even at TL0 (undoing your own follow is always allowed)', function () {
    $acl = Acl::make();
    $acl->assertDecision($acl->user(['members', 'tl0']), 'follow.delete', $acl->global, true, 'group_allow');
});

it('denies both follow keys to guests', function () {
    $acl = Acl::make();
    $guest = $acl->user(['guests']);
    $acl->assertDecision($guest, 'follow.create', $acl->global, false);
    $acl->assertDecision($guest, 'follow.delete', $acl->global, false);
});
