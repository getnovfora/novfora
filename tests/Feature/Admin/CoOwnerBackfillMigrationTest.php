<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Admin\AdminCoOwnerService;
use App\Models\Group;
use App\Models\User;
use App\Permissions\PermissionResolver;
use App\Permissions\PermissionValue;
use App\Permissions\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\Users;

/*
| ACP v3 · v3-a (ADR-0080) — NON-DESTRUCTIVE-UPGRADE guard for co-owners. The is_co_owner column ships
| default-false and only the installer crowns a co-owner, so a board that pre-dates v3-a would upgrade to ZERO
| co-owners and lose all Security-section reach (the administrator preset WITHHOLDS admin.security.access). The
|2026_06_20_000200 backfill migration crowns every existing admins-group member on upgrade. These tests pin its
| contract: an upgrade crowns all N pre-existing admins (flag + a grant that RESOLVES), a fresh install is a
| genuine no-op, non-admins are never crowned, it is idempotent, and it is reversible.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    app(PermissionResolver::class)->flushMemo();
    $this->seed(); // groups + permission catalog + role presets; DatabaseSeeder seeds NO users
});

/** The backfill migration under test (companion to 000100 add_is_co_owner). */
function coOwnerBackfill(): object
{
    return require database_path('migrations/2026_06_20_000200_backfill_co_owners_for_existing_admins.php');
}

function backfillAdminsId(): int
{
    return (int) Group::query()->where('slug', 'admins')->value('id');
}

/** Whether $userId holds the per-user GLOBAL ALLOW on admin.security.access (the resolver's input). */
function hasSecurityGrant(int $userId): bool
{
    return DB::table('acl_entries')
        ->where('permission_key', 'admin.security.access')
        ->where('holder_type', 'user')->where('holder_id', $userId)
        ->where('scope_type', 'global')->whereNull('scope_id')
        ->where('value', PermissionValue::Allow->value)
        ->exists();
}

/** A fresh resolver verdict for admin.security.access (clean request memo). */
function resolvesSecurity(User $u): bool
{
    app(PermissionResolver::class)->flushMemo();

    return $u->fresh()->canDo('admin.security.access', Scope::global());
}

it('crowns every pre-existing admin as a co-owner on upgrade (flag + a grant that resolves)', function () {
    // A pre-v3-a board: three full admins, none flagged is_co_owner, none holding the per-user security grant.
    $admins = collect(range(1, 3))->map(fn () => Users::inGroups(['admins']));
    foreach ($admins as $a) {
        expect(app(AdminCoOwnerService::class)->isCoOwner($a))->toBeFalse()
            ->and(hasSecurityGrant((int) $a->id))->toBeFalse()
            ->and(resolvesSecurity($a))->toBeFalse(); // the administrator preset withholds it
    }

    coOwnerBackfill()->up();

    // …ends with N co-owners, each holding admin.security.access (row present AND resolving ALLOW).
    foreach ($admins as $a) {
        expect(app(AdminCoOwnerService::class)->isCoOwner($a))->toBeTrue()
            ->and(hasSecurityGrant((int) $a->id))->toBeTrue()
            ->and(resolvesSecurity($a))->toBeTrue();
    }
    expect(app(AdminCoOwnerService::class)->coOwnerIds())->toHaveCount(3);
});

it('is a genuine no-op on a fresh install (no admins exist at migration time)', function () {
    // A fresh install runs migrations on an empty user table; the installer crowns the first admin afterwards.
    expect(DB::table('group_user')->where('group_id', backfillAdminsId())->count())->toBe(0);

    coOwnerBackfill()->up();

    expect(app(AdminCoOwnerService::class)->coOwnerIds())->toBe([])
        ->and(DB::table('acl_entries')->where('permission_key', 'admin.security.access')->count())->toBe(0);

    // The installer's own crowning still applies on top — one admin, one co-owner (status quo preserved).
    $admin = Users::inGroups(['admins']);
    $admin->groups()->updateExistingPivot(backfillAdminsId(), ['is_co_owner' => true]);
    DB::table('acl_entries')->insert([
        'permission_key' => 'admin.security.access', 'holder_type' => 'user', 'holder_id' => (int) $admin->id,
        'scope_type' => 'global', 'scope_id' => null, 'value' => PermissionValue::Allow->value,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    expect(app(AdminCoOwnerService::class)->coOwnerIds())->toHaveCount(1);
});

it('never crowns a non-admin', function () {
    $member = Users::inGroups(['members']);
    $admin = Users::inGroups(['admins']);

    coOwnerBackfill()->up();

    expect(app(AdminCoOwnerService::class)->isCoOwner($member))->toBeFalse()
        ->and(hasSecurityGrant((int) $member->id))->toBeFalse()
        ->and(resolvesSecurity($member))->toBeFalse()
        ->and(app(AdminCoOwnerService::class)->isCoOwner($admin))->toBeTrue();
});

it('is idempotent — a re-run crowns the same set and never duplicates the grant', function () {
    $admin = Users::inGroups(['admins']);

    coOwnerBackfill()->up();
    coOwnerBackfill()->up(); // re-run (e.g. a partially-applied upgrade replayed)

    expect(app(AdminCoOwnerService::class)->coOwnerIds())->toHaveCount(1)
        ->and(DB::table('acl_entries')
            ->where('permission_key', 'admin.security.access')->where('holder_id', (int) $admin->id)
            ->count())->toBe(1); // exactly one grant row, not two
});

it('is reversible — down() un-crowns the backfilled admins but never strands the owner tier', function () {
    // Sort by id: the last-owner guard in down() preserves the EARLIEST co-owner (lowest user_id).
    $admins = collect(range(1, 2))->map(fn () => Users::inGroups(['admins']))->sortBy('id')->values();
    $kept = $admins->first();
    $demoted = $admins->last();

    $migration = coOwnerBackfill();
    $migration->up();
    expect(app(AdminCoOwnerService::class)->coOwnerIds())->toHaveCount(2);
    // Prime a cached ALLOW verdict for the about-to-be-demoted admin so the post-down() assertion can ONLY pass
    // if down() actually bumps AclVersion — the query-builder delete skips AclEntry::booted() (Finding 3).
    expect(resolvesSecurity($demoted))->toBeTrue();

    $migration->down();

    // The demoted admin is fully reversed — flag + grant gone, and resolves false (proving the cache was busted).
    expect(app(AdminCoOwnerService::class)->isCoOwner($demoted))->toBeFalse()
        ->and(hasSecurityGrant((int) $demoted->id))->toBeFalse()
        ->and(resolvesSecurity($demoted))->toBeFalse();
    // …but the earliest co-owner is preserved: NO rollback ordering may leave the board with zero owners.
    expect(app(AdminCoOwnerService::class)->isCoOwner($kept))->toBeTrue()
        ->and(resolvesSecurity($kept))->toBeTrue()
        ->and(app(AdminCoOwnerService::class)->coOwnerIds())->toBe([(int) $kept->id]);
});

it('down() preserves the sole installer crown on a fresh install (last-owner guard, never strands)', function () {
    // Fresh install: up() is a no-op (no admins yet); the installer then crowns the sole admin. A later rollback
    // of the backfill must NOT strip that lone owner — else the board self-locks out of Security with no recourse
    // (the Security SFC itself gates on admin.security.access). This pins the no-op-on-fresh inverse symmetry.
    coOwnerBackfill()->up();
    $admin = Users::inGroups(['admins']);
    $admin->groups()->updateExistingPivot(backfillAdminsId(), ['is_co_owner' => true]);
    DB::table('acl_entries')->insert([
        'permission_key' => 'admin.security.access', 'holder_type' => 'user', 'holder_id' => (int) $admin->id,
        'scope_type' => 'global', 'scope_id' => null, 'value' => PermissionValue::Allow->value,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    coOwnerBackfill()->down();

    expect(app(AdminCoOwnerService::class)->isCoOwner($admin))->toBeTrue()
        ->and(hasSecurityGrant((int) $admin->id))->toBeTrue()
        ->and(resolvesSecurity($admin))->toBeTrue();
});
