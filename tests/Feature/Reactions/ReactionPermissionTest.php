<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\User;
use App\Permissions\PermissionValue as V;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Acl;

/*
| Permission-mask coverage for the new react.create key (P2-M1). react.create is forum-scoped, granted to the
| member preset and up, and is deliberately NOT trust-gated (reacting is ungated participation — abuse is
| handled by ReactionRateLimiter, not a NEVER). NEVER still hard-denies (the generic engine invariant).
| Uses the same inspector-trace oracle as PermissionMaskTest (Acl::assertDecision).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    $this->seed(); // expands the member/mod/admin role presets (incl. react.create) + the TL trust gates
});

it('grants react.create to a seeded member at forum scope', function () {
    $acl = Acl::make();
    $acl->assertDecision($acl->user(['members']), 'react.create', $acl->forumScope, true, 'group_allow');
});

it('grants react.create to staff (moderator inherits member)', function () {
    $acl = Acl::make();
    $acl->assertDecision($acl->user(['moderators']), 'react.create', $acl->forumScope, true);
});

it('denies react.create to a groupless user (deny-by-default)', function () {
    $acl = Acl::make();
    $acl->assertDecision($acl->user(), 'react.create', $acl->forumScope, false, 'default');
});

it('denies react.create to a guest', function () {
    $acl = Acl::make();
    expect(User::guest()->canDo('react.create', $acl->forumScope))->toBeFalse();
});

it('a per-forum NEVER hard-denies react.create even for a member', function () {
    $acl = Acl::make();
    $member = $acl->user(['members']);
    $acl->grant('members', 'react.create', $acl->forumScope, V::Never);
    $acl->assertDecision($member, 'react.create', $acl->forumScope, false, 'never');
});

it('is NOT trust-gated — a TL0 member may still react', function () {
    $acl = Acl::make();
    // TL0 carries NEVER on the true spam vectors (links/images/PM) but nothing for react.create, so the
    // member grant stands — reacting is ungated, rate-limited participation.
    $acl->assertDecision($acl->user(['members', 'tl0']), 'react.create', $acl->forumScope, true);
});
