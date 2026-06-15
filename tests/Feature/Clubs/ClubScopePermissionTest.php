<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Clubs\ClubRoleProjector;
use App\Clubs\ClubService;
use App\Models\AclEntry;
use App\Models\ClubMembership;
use App\Models\Forum;
use App\Models\Group;
use App\Models\Permission;
use App\Permissions\PermissionResolver;
use App\Permissions\PermissionSync;
use App\Permissions\Scope;
use App\Permissions\ScopeChain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

/** The engine memoises per request + caches cross-request; after ACL writes a test must drop both. */
function freshAcl(): void
{
    app(PermissionResolver::class)->flushMemo();
    Cache::flush();
}

function makeOwner(string $email): array
{
    $owner = Users::inGroups(['members', 'tl2'], ['email' => $email]);
    $club = app(ClubService::class)->create($owner, ['name' => 'Scope Club '.uniqid(), 'privacy' => 'public']);
    freshAcl();

    return [$owner, $club];
}

// ── Scope plumbing ───────────────────────────────────────────────────────────────────────────────────────

it('parses a club scope reference', function () {
    $scope = Scope::parse('club:7');
    expect($scope->type)->toBe('club');
    expect($scope->id)->toBe(7);
    expect($scope->key())->toBe('club:7');
});

it('builds a club scope chain rooted at global', function () {
    $chain = ScopeChain::for(Scope::club(42));
    expect($chain)->toHaveCount(2);
    expect($chain[0]->type)->toBe('global');
    expect($chain[1]->type)->toBe('club');
    expect($chain[1]->id)->toBe(42);
});

// ── Owner / moderator / member capability resolution at club scope ───────────────────────────────────────

it('grants the founder owner club.manage at their club but not at another club', function () {
    [$owner, $clubA] = makeOwner('owner-a@scope.test');
    $clubB = app(ClubService::class)->create(Users::inGroups(['members', 'tl2'], ['email' => 'owner-b@scope.test']), ['name' => 'Other Club', 'privacy' => 'public']);
    freshAcl();

    expect($owner->fresh()->canDo('club.manage', Scope::club((int) $clubA->id)))->toBeTrue();
    expect($owner->fresh()->canDo('club.manage', Scope::club((int) $clubB->id)))->toBeFalse();
});

it('grants the owner club-scoped moderation but isolates it from other forums', function () {
    [$owner, $club] = makeOwner('owner-mod@scope.test');
    $forum = Forum::create(['slug' => 'general-scope', 'title' => 'General', 'type' => 'forum']);
    freshAcl();

    // Owner moderates within the club…
    expect($owner->fresh()->canDo('topic.moderate', Scope::club((int) $club->id)))->toBeTrue();
    // …but NOT in an unrelated forum (the club grant is scope-isolated; a plain member has no global moderate).
    expect($owner->fresh()->canDo('topic.moderate', $forum->permissionScope()))->toBeFalse();
});

it('grants a club moderator topic.moderate but never club.manage', function () {
    [$owner, $club] = makeOwner('owner-for-mod@scope.test');
    $mod = Users::inGroups(['members', 'tl1'], ['email' => 'clubmod@scope.test']);
    $m = ClubMembership::create(['club_id' => $club->id, 'user_id' => $mod->id, 'role' => 'moderator', 'status' => 'active', 'joined_at' => now()]);
    app(ClubRoleProjector::class)->project($m);
    freshAcl();

    expect($mod->fresh()->canDo('topic.moderate', Scope::club((int) $club->id)))->toBeTrue();
    expect($mod->fresh()->canDo('club.manage', Scope::club((int) $club->id)))->toBeFalse();
});

it('grants a plain club member no club-scope capabilities', function () {
    [$owner, $club] = makeOwner('owner-for-member@scope.test');
    $member = Users::inGroups(['members', 'tl1'], ['email' => 'plainmember@scope.test']);
    $m = ClubMembership::create(['club_id' => $club->id, 'user_id' => $member->id, 'role' => 'member', 'status' => 'active', 'joined_at' => now()]);
    app(ClubRoleProjector::class)->project($m);
    freshAcl();

    expect($member->fresh()->canDo('club.manage', Scope::club((int) $club->id)))->toBeFalse();
    expect($member->fresh()->canDo('topic.moderate', Scope::club((int) $club->id)))->toBeFalse();
});

it('lets a global administrator manage any club through the global preset', function () {
    [$owner, $club] = makeOwner('owner-for-admin@scope.test');
    $admin = Users::inGroups(['admins'], ['email' => 'admin@scope.test']);
    freshAcl();

    expect($admin->fresh()->canDo('club.manage', Scope::club((int) $club->id)))->toBeTrue();
    expect($club->isManageableBy($admin->fresh()))->toBeTrue();
});

it('revokes club-scope grants when a membership is cleared', function () {
    [$owner, $club] = makeOwner('owner-leave@scope.test');
    expect($owner->fresh()->canDo('club.manage', Scope::club((int) $club->id)))->toBeTrue();

    app(ClubRoleProjector::class)->clear((int) $club->id, (int) $owner->id);
    freshAcl();

    expect($owner->fresh()->canDo('club.manage', Scope::club((int) $club->id)))->toBeFalse();
});

it('re-projects grants when a role changes from moderator to owner', function () {
    [$owner, $club] = makeOwner('owner-promote@scope.test');
    $user = Users::inGroups(['members', 'tl1'], ['email' => 'promote@scope.test']);

    $m = ClubMembership::create(['club_id' => $club->id, 'user_id' => $user->id, 'role' => 'moderator', 'status' => 'active', 'joined_at' => now()]);
    app(ClubRoleProjector::class)->project($m);
    freshAcl();
    expect($user->fresh()->canDo('club.manage', Scope::club((int) $club->id)))->toBeFalse();

    $m->update(['role' => 'owner']);
    app(ClubRoleProjector::class)->project($m);
    freshAcl();
    expect($user->fresh()->canDo('club.manage', Scope::club((int) $club->id)))->toBeTrue();
});

// ── permissions:sync awareness of club.manage ───────────────────────────────────────────────────────────

it('re-provisions club.manage through permissions:sync when missing', function () {
    // Simulate an install that predates club.manage: drop the catalog row + the admins-group entry.
    Permission::where('key', 'club.manage')->delete();
    $admins = Group::where('slug', 'admins')->firstOrFail();
    AclEntry::where('permission_key', 'club.manage')
        ->where('holder_type', 'group')->where('holder_id', $admins->id)->delete();

    $report = app(PermissionSync::class)->sync();

    expect(Permission::where('key', 'club.manage')->exists())->toBeTrue();
    expect(AclEntry::where('permission_key', 'club.manage')
        ->where('holder_type', 'group')->where('holder_id', $admins->id)
        ->where('scope_type', 'global')->exists())->toBeTrue();
    expect($report->isNoop())->toBeFalse();

    // Idempotent: a second run changes nothing.
    expect(app(PermissionSync::class)->sync()->isNoop())->toBeTrue();
});
