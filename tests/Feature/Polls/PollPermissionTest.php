<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Permissions\PermissionValue as V;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Acl;

/*
| Permission-mask coverage for poll.create (TL-gated, SOFT) and poll.vote (ungated) — P2-M1.
| poll.create is withheld from the base member preset and granted from TL1 via $trusted, so TL0 is denied by
| default (not by a NEVER) and an admin can lift it; staff (moderator preset) get it regardless of trust.
| poll.vote is granted to the member preset (ungated participation).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    $this->seed();
});

it('denies poll.create to a TL0 member (soft gate, deny-by-default)', function () {
    $acl = Acl::make();
    $acl->assertDecision($acl->user(['members', 'tl0']), 'poll.create', $acl->forumScope, false, 'default');
});

it('grants poll.create to a TL1 member (progressive grant via $trusted)', function () {
    $acl = Acl::make();
    $acl->assertDecision($acl->user(['members', 'tl1']), 'poll.create', $acl->forumScope, true, 'group_allow');
});

it('grants poll.create to staff regardless of trust level (soft gate does not block ALLOW)', function () {
    $acl = Acl::make();
    // A misassigned TL0 moderator still creates polls — tl0 carries only a soft NO, which ALLOW beats.
    $acl->assertDecision($acl->user(['moderators', 'tl0']), 'poll.create', $acl->forumScope, true);
});

it('is admin-liftable: granting poll.create to members lifts the TL0 soft gate', function () {
    $acl = Acl::make();
    $member = $acl->user(['members', 'tl0']);
    $acl->grant('members', 'poll.create', $acl->global, V::Allow); // an admin opens it for everyone
    $acl->assertDecision($member, 'poll.create', $acl->forumScope, true, 'group_allow');
});

it('grants poll.vote to any member (ungated)', function () {
    $acl = Acl::make();
    $acl->assertDecision($acl->user(['members', 'tl0']), 'poll.vote', $acl->forumScope, true);
});

it('a per-forum NEVER hard-denies poll.vote', function () {
    $acl = Acl::make();
    $member = $acl->user(['members', 'tl2']);
    $acl->grant('members', 'poll.vote', $acl->forumScope, V::Never);
    $acl->assertDecision($member, 'poll.vote', $acl->forumScope, false, 'never');
});
