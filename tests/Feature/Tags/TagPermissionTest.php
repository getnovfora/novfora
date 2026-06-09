<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Permissions\PermissionResolver;
use App\Permissions\PermissionValue as V;
use App\Permissions\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Acl;

/*
| tag.create NEVER-gate truth table (P2-M1, ADR-0007 §2.3).
|
| tag.create mints a brand-new tag into the durable global namespace → same spam vector class as
| post.links/post.images. The NEVER at TL0 is therefore ABSOLUTE — even an admin ALLOW grant
| cannot lift it (NEVER is absolute per the resolver). This test suite proves that invariant.
|
| tag.apply is ungated participation (like react.create/poll.vote) — the member preset grants it
| at global scope, so any member in a forum where forum.view is allowed can apply existing tags.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    app(PermissionResolver::class)->flushMemo();
    $this->seed();
});

// ── tag.create: TL0 is hard-NEVER ───────────────────────────────────────────────────────────────

it('TL0 member cannot mint a tag — NEVER is absolute', function () {
    $acl = Acl::make();
    $newbie = $acl->user(['members', 'tl0']);

    $acl->assertDecision($newbie, 'tag.create', Scope::global(), false, 'never');
});

it('granting tag.create ALLOW to members at global scope still leaves a TL0 member denied (NEVER wins)', function () {
    $acl = Acl::make();
    $newbie = $acl->user(['members', 'tl0']);

    // An admin explicitly grants tag.create to the members group at global scope.
    $acl->grant('members', 'tag.create', Scope::global(), V::Allow);

    // The TL0 group's NEVER still wins — this is the whole point of the NEVER-vs-ALLOW distinction.
    $acl->assertDecision($newbie, 'tag.create', Scope::global(), false, 'never');
});

it('TL1 member can mint a tag — grant via $trusted array', function () {
    $acl = Acl::make();
    $trusted = $acl->user(['members', 'tl1']);

    $acl->assertDecision($trusted, 'tag.create', Scope::global(), true);
});

it('moderator can mint a tag — moderator preset grants tag.create', function () {
    $acl = Acl::make();
    $mod = $acl->user(['moderators']);

    $acl->assertDecision($mod, 'tag.create', Scope::global(), true);
});

// ── tag.apply: ungated participation for members ─────────────────────────────────────────────

it('a member can apply existing tags — member preset grants tag.apply', function () {
    $acl = Acl::make();
    $member = $acl->user(['members', 'tl0']); // even TL0: tag.apply is not gated on trust level

    $acl->assertDecision($member, 'tag.apply', $acl->forumScope, true);
});

it('a per-forum NEVER on tag.apply denies a member at that forum', function () {
    $acl = Acl::make();
    $member = $acl->user(['members', 'tl1']);

    // Place a NEVER on tag.apply for the members group at this specific forum.
    $acl->grant('members', 'tag.apply', $acl->forumScope, V::Never);

    $acl->assertDecision($member, 'tag.apply', $acl->forumScope, false, 'never');
    // But global scope is still fine (the NEVER is scoped to the forum only).
    $acl->assertDecision($member, 'tag.apply', $acl->global, true);
});

// ── contrast: poll.create is a SOFT gate (no), so an admin CAN lift it for TL0 ───────────────

it('poll.create is a soft gate — an admin ALLOW to TL0 can lift it (contrast with tag.create)', function () {
    $acl = Acl::make();
    $newbie = $acl->user(['members', 'tl0']);

    // Before any override, TL0 is denied poll.create (no = neutral; member preset doesn't grant it).
    $acl->assertDecision($newbie, 'poll.create', $acl->forumScope, false);

    // Grant it directly to this user.
    $acl->grant($newbie, 'poll.create', $acl->forumScope, V::Allow);

    // Soft gate: user ALLOW wins, TL0 NO is neutral → granted.
    $acl->assertDecision($newbie, 'poll.create', $acl->forumScope, true, 'user_allow');
});
