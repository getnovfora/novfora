<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Admin\GroupManager;
use App\Groups\GroupAutoPromoter;
use App\Groups\GroupMembershipService;
use App\Models\GroupJoinRequest;
use App\Permissions\AclVersion;
use App\Permissions\PermissionInspector;
use App\Permissions\PermissionResolver;
use App\Permissions\PermissionValue as V;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Acl;

uses(RefreshDatabase::class);

/*
| The ACP v3 · v3-e membership-cache seam (ADR-0083 — G9's sibling). A group is a permission HOLDER, so a
| join/leave/promote/approve changes effective permissions WITHOUT touching acl_entries, and the pivot
| writes fire no model events — so MembershipCache::flushFor() must invalidate the resolver caches by hand.
|
| These tests assert through PermissionInspector — the test ORACLE (G4) — on the SAME in-memory user
| instance the mutation touched, because the resolver's per-request memo (keyed user|perm|scope, WITHOUT
| the group signature) would otherwise serve a stale verdict resolved before the change. The Acl helper's
| own assertion methods flush the memo + fresh() the user, which would MASK the seam, so the seam itself is
| exercised with the inspector directly.
*/

const PERK = 'v3e.perk';

/** A custom group that grants PERK (ALLOW) at global, plus a user who is NOT yet a member. */
function seamFixture(string $membershipModel = 'open', ?array $autoPromotion = null): array
{
    $acl = Acl::make();
    $group = $acl->group('beta', ['type' => 'custom', 'membership_model' => $membershipModel, 'auto_promotion' => $autoPromotion]);
    $acl->grant($group, PERK, $acl->global, V::Allow);
    $user = $acl->user(['members']); // NOT in beta yet → deny-by-default for PERK

    return [$acl, $group, $user];
}

it('a raw pivot attach WITHOUT the seam leaves the memo stale (the hazard the seam prevents)', function () {
    [$acl, $group, $user] = seamFixture();
    $inspector = app(PermissionInspector::class);

    // Resolve once → populates the per-request memo with granted=false for this exact (user, perm, scope).
    expect($inspector->inspect($user, PERK, $acl->global)['granted'])->toBeFalse();

    // Attach the user to the granting group by hand, deliberately NOT calling MembershipCache::flushFor().
    $user->groups()->attach($group->getKey(), ['is_primary' => false]);

    // The memo still answers with the stale pre-attach verdict — this is exactly what the seam exists to fix.
    expect($inspector->inspect($user, PERK, $acl->global)['granted'])->toBeFalse();

    // And a fresh resolution (new instance + flushed memo) proves the grant really is live in the DB.
    expect($acl->explain($user, PERK, $acl->global)->granted)->toBeTrue();
});

it('open-join invalidates the memo so the inspector reflects the new permission immediately', function () {
    [$acl, $group, $user] = seamFixture('open');
    $inspector = app(PermissionInspector::class);

    expect($inspector->inspect($user, PERK, $acl->global)['granted'])->toBeFalse(); // memo := false

    app(GroupMembershipService::class)->joinOpen($group, $user);

    // SAME instance: only the seam (flushMemo + relation refresh on $user) makes this flip to true.
    expect($inspector->inspect($user, PERK, $acl->global)['granted'])->toBeTrue();
    // Oracle cross-check (fresh user + cached can() path) agrees.
    $acl->assertDecision($user, PERK, $acl->global, true, 'group_allow');
});

it('auto-promotion invalidates the memo immediately for the promoted user', function () {
    [$acl, $group, $user] = seamFixture('admin', ['op' => 'AND', 'rules' => [['criterion' => 'posts', 'gte' => 3]]]);
    $user->forceFill(['post_count' => 5])->save();
    $user->refresh();
    $inspector = app(PermissionInspector::class);

    expect($inspector->inspect($user, PERK, $acl->global)['granted'])->toBeFalse();

    app(GroupAutoPromoter::class)->promote($user);

    expect($inspector->inspect($user, PERK, $acl->global)['granted'])->toBeTrue();
    $acl->assertDecision($user, PERK, $acl->global, true);
});

it('approving a join request makes the resolved permission live for the requester', function () {
    [$acl, $group, $user] = seamFixture('request');
    // This fixture uses Acl::make() (no seeded permission catalog), so grant the actor admin.access directly.
    $admin = $acl->user([]);
    $acl->grant($admin, 'admin.access', $acl->global, V::Allow);
    $svc = app(GroupMembershipService::class);

    $request = $svc->requestToJoin($group, $user);
    $acl->assertDecision($user, PERK, $acl->global, false); // pending → not yet a member

    $svc->approve($request, $admin);

    // approve() operates on its own freshly-loaded instance; the oracle (fresh user) must now grant.
    $acl->assertDecision($user, PERK, $acl->global, true, 'group_allow');
    expect(GroupJoinRequest::find($request->id)->status)->toBe(GroupJoinRequest::STATUS_APPROVED);
});

it('admin-assign (GroupManager::addMembers) invalidates and the next resolution grants', function () {
    [$acl, $group, $user] = seamFixture('admin');

    $acl->assertDecision($user, PERK, $acl->global, false);
    app(GroupManager::class)->addMembers($group, [(int) $user->getKey()]);
    $acl->assertDecision($user, PERK, $acl->global, true, 'group_allow');
});

it('leaving a group revokes the permission (membership removal also invalidates)', function () {
    [$acl, $group, $user] = seamFixture('open');
    $svc = app(GroupMembershipService::class);

    $svc->joinOpen($group, $user);
    $acl->assertDecision($user, PERK, $acl->global, true);

    $svc->leave($group, $user);
    $acl->assertDecision($user, PERK, $acl->global, false);
});

it('the cross-request cached can() path also reflects a membership change', function () {
    [$acl, $group, $user] = seamFixture('open');
    $resolver = app(PermissionResolver::class);

    // Warm the cross-request cache (keyed by the group signature) with the pre-join verdict.
    expect($resolver->can($user, PERK, $acl->global))->toBeFalse();

    app(GroupMembershipService::class)->joinOpen($group, $user);

    // flushFor() refreshed $user's groups → its signature changed → the cache re-keys → fresh verdict.
    expect($resolver->can($user, PERK, $acl->global))->toBeTrue();
});

it('bumps AclVersion on a reduction (leave) but NOT on the additive auto-promote hot path', function () {
    [$acl, $open, $user] = seamFixture('open');
    $version = app(AclVersion::class);

    // Additive auto-promotion must NOT bump the global version (else the hourly cron sweep cold-starts every
    // viewer's cache — the whole reason the additive paths re-key by signature instead).
    $acl->group('promo', ['type' => 'custom', 'membership_model' => 'admin', 'auto_promotion' => ['op' => 'AND', 'rules' => [['criterion' => 'posts', 'gte' => 1]]]]);
    $user->forceFill(['post_count' => 5])->save();
    $beforeAdd = $version->current();
    expect(app(GroupAutoPromoter::class)->promote($user->fresh()))->toBe(1);
    expect($version->current())->toBe($beforeAdd);

    // A REDUCTION (leave) MUST bump, so a signature the user round-trips back to can never re-serve a stale entry.
    $mover = $acl->user(['members']);
    app(GroupMembershipService::class)->joinOpen($open, $mover);
    $beforeLeave = $version->current();
    app(GroupMembershipService::class)->leave($open, $mover);
    expect($version->current())->toBeGreaterThan($beforeLeave);
});
