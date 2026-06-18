<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Admin\GroupException;
use App\Admin\GroupManager;
use App\Groups\GroupMembershipService;
use App\Models\Group;
use App\Models\GroupJoinRequest;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

function gms(): GroupMembershipService
{
    return app(GroupMembershipService::class);
}

function makeGroup(string $model): Group
{
    return app(GroupManager::class)->create(['name' => 'Group '.$model.' '.uniqid(), 'membership_model' => $model]);
}

// ── Open join ──────────────────────────────────────────────────────────────────────────────────────────

it('open-join seats an eligible member immediately', function () {
    $group = makeGroup('open');
    $user = Users::inGroups(['members']);

    gms()->joinOpen($group, $user);

    expect($group->users()->whereKey($user->id)->exists())->toBeTrue();
});

it('open-join is refused when the group is not open', function () {
    $user = Users::inGroups(['members']);

    expect(fn () => gms()->joinOpen(makeGroup('admin'), $user))->toThrow(GroupException::class);
    expect(fn () => gms()->joinOpen(makeGroup('request'), $user))->toThrow(GroupException::class);
});

// ── Anti-spam / trust gate ───────────────────────────────────────────────────────────────────────────

it('a banned account cannot self-join', function () {
    $group = makeGroup('open');
    $user = Users::inGroups(['members']);
    $user->forceFill(['status' => 'banned'])->save();

    expect(fn () => gms()->joinOpen($group, $user->fresh()))->toThrow(GroupException::class);
    expect($group->users()->whereKey($user->id)->exists())->toBeFalse();
});

it('a suspended/pending account cannot self-join', function () {
    $group = makeGroup('open');
    $user = Users::inGroups(['members']);
    $user->forceFill(['status' => 'pending'])->save();

    expect(fn () => gms()->joinOpen($group, $user->fresh()))->toThrow(GroupException::class);
});

it('an unverified account cannot self-join', function () {
    $group = makeGroup('open');
    $user = Users::inGroups(['members']);
    $user->forceFill(['email_verified_at' => null])->save();

    expect(fn () => gms()->joinOpen($group, $user->fresh()))->toThrow(GroupException::class);
});

it('the gate applies to request-to-join too', function () {
    $group = makeGroup('request');
    $user = Users::inGroups(['members']);
    $user->forceFill(['status' => 'banned'])->save();

    expect(fn () => gms()->requestToJoin($group, $user->fresh()))->toThrow(GroupException::class);
});

// ── Request + approval ─────────────────────────────────────────────────────────────────────────────────

it('request-to-join creates a pending request and does NOT add membership yet', function () {
    $group = makeGroup('request');
    $user = Users::inGroups(['members']);

    $request = gms()->requestToJoin($group, $user);

    expect($request->status)->toBe(GroupJoinRequest::STATUS_PENDING);
    expect($group->users()->whereKey($user->id)->exists())->toBeFalse();
});

it('approve seats the user; deny does not', function () {
    $group = makeGroup('request');
    $admin = Users::inGroups(['admins']);

    $approved = Users::inGroups(['members']);
    $req1 = gms()->requestToJoin($group, $approved);
    gms()->approve($req1, $admin);
    expect($group->users()->whereKey($approved->id)->exists())->toBeTrue();
    expect($req1->fresh()->status)->toBe(GroupJoinRequest::STATUS_APPROVED);

    $denied = Users::inGroups(['members']);
    $req2 = gms()->requestToJoin($group, $denied);
    gms()->deny($req2, $admin);
    expect($group->users()->whereKey($denied->id)->exists())->toBeFalse();
    expect($req2->fresh()->status)->toBe(GroupJoinRequest::STATUS_DENIED);
});

it('a non-manager cannot approve a request', function () {
    $group = makeGroup('request');
    $member = Users::inGroups(['members']);
    $request = gms()->requestToJoin($group, Users::inGroups(['members']));

    expect(fn () => gms()->approve($request, $member))->toThrow(GroupException::class);
});

it('re-requesting after a denial reuses the one row and flips it back to pending', function () {
    $group = makeGroup('request');
    $admin = Users::inGroups(['admins']);
    $user = Users::inGroups(['members']);

    $req = gms()->requestToJoin($group, $user);
    gms()->deny($req, $admin);

    $reqAgain = gms()->requestToJoin($group, $user);

    expect($reqAgain->id)->toBe($req->id); // same row, not a pile-up
    expect($reqAgain->status)->toBe(GroupJoinRequest::STATUS_PENDING);
    expect(GroupJoinRequest::where('user_id', $user->id)->where('group_id', $group->id)->count())->toBe(1);
});

// ── Leaving ────────────────────────────────────────────────────────────────────────────────────────────

it('a member can leave a self-service group but not an admin-managed one', function () {
    $open = makeGroup('open');
    $user = Users::inGroups(['members']);
    gms()->joinOpen($open, $user);

    gms()->leave($open, $user);
    expect($open->users()->whereKey($user->id)->exists())->toBeFalse();

    $adminGroup = makeGroup('admin');
    app(GroupManager::class)->addMembers($adminGroup, [(int) $user->id]);
    expect(fn () => gms()->leave($adminGroup, $user))->toThrow(GroupException::class);
});
