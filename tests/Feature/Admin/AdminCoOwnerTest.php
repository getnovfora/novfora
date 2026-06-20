<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Admin\AdminCoOwnerException;
use App\Admin\AdminCoOwnerService;
use App\Models\AclEntry;
use App\Models\Group;
use App\Models\User;
use App\Permissions\AclVersion;
use App\Permissions\PermissionResolver;
use App\Permissions\PermissionValue;
use App\Permissions\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\Users;

/*
| ACP v3 · v3-a (ADR-0080) — AdminCoOwnerService: the top admin tier. grant/revoke keep the is_co_owner pivot
| flag and the admin.security.access grant in lockstep; the apex concern is the LAST-OWNER guard — a sole
| co-owner can never be removed/demoted, and the locked in-transaction re-read is the authority (a self-lockout
| of the whole owner team is the failure being prevented). PermissionInspector / the resolver is the oracle.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    app(PermissionResolver::class)->flushMemo();
    $this->seed();
});

/** The admins group id. */
function adminsGroupId(): int
{
    return (int) Group::query()->where('slug', 'admins')->value('id');
}

/** Bootstrap a co-owner directly (exactly as the installer crowns the first one): flag + the security grant. */
function bootstrapCoOwner(): User
{
    $u = Users::inGroups(['admins']);
    $u->groups()->updateExistingPivot(adminsGroupId(), ['is_co_owner' => true]);
    AclEntry::updateOrCreate(
        ['permission_key' => 'admin.security.access', 'holder_type' => 'user', 'holder_id' => (int) $u->id,
            'scope_type' => 'global', 'scope_id' => null],
        ['value' => PermissionValue::Allow->value],
    );

    return $u->fresh();
}

/** Resolve a fresh verdict (a clean request: empty memo, the bump already re-keyed the cross-request cache). */
function resolves(User $u, string $key): bool
{
    app(PermissionResolver::class)->flushMemo();

    return $u->fresh()->canDo($key, Scope::global());
}

it('grants co-ownership: the flag is set and admin.security.access resolves ALLOW', function () {
    $owner = bootstrapCoOwner();
    $admin = Users::inGroups(['admins']); // a full admin, not yet a co-owner

    expect(resolves($admin, 'admin.security.access'))->toBeFalse();

    app(AdminCoOwnerService::class)->grant($owner, $admin);

    expect(app(AdminCoOwnerService::class)->isCoOwner($admin))->toBeTrue();
    expect(resolves($admin, 'admin.security.access'))->toBeTrue();
    // The admins-group pivot flag is set.
    expect((bool) DB::table('group_user')->where('user_id', $admin->id)->where('group_id', adminsGroupId())->value('is_co_owner'))->toBeTrue();
});

it('refuses to make a non-admin a co-owner', function () {
    $owner = bootstrapCoOwner();
    $member = Users::inGroups(['members']);

    expect(fn () => app(AdminCoOwnerService::class)->grant($owner, $member))
        ->toThrow(AdminCoOwnerException::class);
    expect(app(AdminCoOwnerService::class)->isCoOwner($member))->toBeFalse();
});

it('refuses an actor who is not a co-owner (the backstop to the SFC gate)', function () {
    $plainAdmin = Users::inGroups(['admins']); // a full admin but NOT a co-owner
    $target = Users::inGroups(['admins']);

    expect(fn () => app(AdminCoOwnerService::class)->grant($plainAdmin, $target))
        ->toThrow(AdminCoOwnerException::class);
    expect(app(AdminCoOwnerService::class)->isCoOwner($target))->toBeFalse();
});

it('is idempotent: re-granting an existing co-owner changes nothing and does not bump the version', function () {
    $owner = bootstrapCoOwner();
    $admin = Users::inGroups(['admins']);
    app(AdminCoOwnerService::class)->grant($owner, $admin);

    $version = app(AclVersion::class)->current();
    app(AdminCoOwnerService::class)->grant($owner, $admin); // no-op

    expect(app(AclVersion::class)->current())->toBe($version);
    expect(app(AdminCoOwnerService::class)->coOwnerIds())->toHaveCount(2);
});

it('revokes co-ownership when another owner remains: the flag clears and security access is withdrawn', function () {
    $owner = bootstrapCoOwner();
    $second = Users::inGroups(['admins']);
    app(AdminCoOwnerService::class)->grant($owner, $second);

    app(AdminCoOwnerService::class)->revoke($owner, $second);

    expect(app(AdminCoOwnerService::class)->isCoOwner($second))->toBeFalse();
    expect(resolves($second, 'admin.security.access'))->toBeFalse();
    expect((bool) DB::table('group_user')->where('user_id', $second->id)->where('group_id', adminsGroupId())->value('is_co_owner'))->toBeFalse();
    // The first owner is untouched.
    expect(app(AdminCoOwnerService::class)->isCoOwner($owner))->toBeTrue();
});

it('blocks removing the LAST co-owner (the crown jewel last-owner guard)', function () {
    $owner = bootstrapCoOwner();
    expect(app(AdminCoOwnerService::class)->coOwnerIds())->toHaveCount(1);

    expect(fn () => app(AdminCoOwnerService::class)->revoke($owner, $owner))
        ->toThrow(AdminCoOwnerException::class);

    // Nothing changed — the sole owner still holds the flag AND security access.
    expect(app(AdminCoOwnerService::class)->isCoOwner($owner))->toBeTrue();
    expect(resolves($owner, 'admin.security.access'))->toBeTrue();
});

it('the locked guard re-reads LIVE state, not a stale snapshot (A5 TOCTOU)', function () {
    // Two co-owners exist…
    $owner = bootstrapCoOwner();
    $second = Users::inGroups(['admins']);
    app(AdminCoOwnerService::class)->grant($owner, $second);
    expect(app(AdminCoOwnerService::class)->coOwnerIds())->toHaveCount(2);

    // …then a CONCURRENT change (simulated by a direct DB write) demotes $second WITHOUT going through the
    // service — the live co-owner set is now just {$owner}. A guard that trusted an earlier count of two would
    // wrongly proceed; the in-transaction LOCKED re-read sees one and aborts.
    DB::table('group_user')->where('user_id', $second->id)->where('group_id', adminsGroupId())
        ->update(['is_co_owner' => false]);

    expect(fn () => app(AdminCoOwnerService::class)->revoke($owner, $owner))
        ->toThrow(AdminCoOwnerException::class);
    expect(app(AdminCoOwnerService::class)->isCoOwner($owner))->toBeTrue(); // not stranded
});

it('allows the genuine last-of-two removal, then protects the new sole owner (A5)', function () {
    $owner = bootstrapCoOwner();
    $second = Users::inGroups(['admins']);
    app(AdminCoOwnerService::class)->grant($owner, $second);

    // The locked guard sees two co-owners → permits removing $second.
    app(AdminCoOwnerService::class)->revoke($owner, $second);
    expect(app(AdminCoOwnerService::class)->coOwnerIds())->toBe([(int) $owner->id]);

    // $owner is now the genuine sole co-owner — the guard refuses to remove them.
    expect(fn () => app(AdminCoOwnerService::class)->revoke($owner, $owner))
        ->toThrow(AdminCoOwnerException::class);
});

it('revoke is a no-op on a target who is not a co-owner', function () {
    $owner = bootstrapCoOwner();
    $admin = Users::inGroups(['admins']);

    $version = app(AclVersion::class)->current();
    app(AdminCoOwnerService::class)->revoke($owner, $admin); // nothing to do

    expect(app(AclVersion::class)->current())->toBe($version);
    expect(app(AdminCoOwnerService::class)->coOwnerIds())->toBe([(int) $owner->id]);
});

it('bumps AclVersion on a real grant and a real revoke (cross-request cache invalidation)', function () {
    $owner = bootstrapCoOwner();
    $second = Users::inGroups(['admins']);

    $v0 = app(AclVersion::class)->current();
    app(AdminCoOwnerService::class)->grant($owner, $second);
    expect(app(AclVersion::class)->current())->toBeGreaterThan($v0);

    $v1 = app(AclVersion::class)->current();
    app(AdminCoOwnerService::class)->revoke($owner, $second);
    expect(app(AclVersion::class)->current())->toBeGreaterThan($v1);
});
