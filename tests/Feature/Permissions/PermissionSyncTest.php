<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\AclEntry;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Role;
use App\Permissions\AclVersion;
use App\Permissions\PermissionResolver;
use App\Permissions\PermissionSync;
use App\Permissions\PermissionValue as V;
use App\Permissions\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Acl;

/*
| Wave 0.1 / ADR-0036 — permissions:sync re-provisions role presets onto EXISTING roles and groups so a
| permission added to a preset in a newer release reaches an already-installed site (the live "Badges 403"
| class). Additive only: it never overwrites an admin-customised value, and a re-run is a true no-op.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    app(PermissionResolver::class)->flushMemo();
    $this->seed(); // full install posture (groups, roles, role_permissions, acl_entries, catalog)
});

/** The admins system-group id. */
function psyncAdminsId(): int
{
    return (int) Group::query()->where('slug', 'admins')->value('id');
}

/** The admins-group GLOBAL-scope acl_entry for a key (the resolver's input for an admin at global). */
function psyncAdminEntry(string $key): ?AclEntry
{
    return AclEntry::query()
        ->where('holder_type', 'group')->where('holder_id', psyncAdminsId())
        ->where('scope_type', 'global')->whereNull('scope_id')
        ->where('permission_key', $key)->first();
}

function psyncAdminRolePerm(string $key): bool
{
    return Role::query()->where('slug', 'administrator')->first()
        ->permissions()->where('permission_key', $key)->exists();
}

it('propagates a permission added to a preset onto existing roles and groups (fixes the Badges 403)', function () {
    $acl = Acl::make();
    $admin = $acl->user(['admins']);

    // Simulate a site seeded BEFORE badge.manage existed on the administrator preset: drop the
    // role_permission AND the admins-group global acl_entry for badge.manage.
    Role::query()->where('slug', 'administrator')->first()
        ->permissions()->where('permission_key', 'badge.manage')->delete();
    psyncAdminEntry('badge.manage')?->delete();
    Cache::flush();
    app(PermissionResolver::class)->flushMemo();

    // Repro: the admin now 403s on the Badges panel (the resolver denies badge.manage).
    expect($admin->fresh()->canDo('badge.manage', Scope::global()))->toBeFalse();

    $report = app(PermissionSync::class)->sync();

    // Simulate the next HTTP request (a fresh process has an empty memo); the cache itself is left intact
    // so this also proves the sync's AclVersion bump invalidated the previously-cached DENY verdict.
    app(PermissionResolver::class)->flushMemo();

    // Both layers are restored and the verdict flips to ALLOW.
    expect(psyncAdminRolePerm('badge.manage'))->toBeTrue();
    expect(psyncAdminEntry('badge.manage'))->not->toBeNull();
    expect(psyncAdminEntry('badge.manage')->value)->toBe(V::Allow->value);
    expect($admin->fresh()->canDo('badge.manage', Scope::global()))->toBeTrue();

    // …and the report names exactly what it changed.
    expect($report->permissionsAdded['administrator'] ?? [])->toContain('badge.manage');
    expect($report->entriesWritten['admins'] ?? [])->toContain('badge.manage');
});

it('is a true no-op on a freshly seeded install (idempotent, no acl churn, no version bump)', function () {
    $before = AclEntry::count();
    $version = app(AclVersion::class)->current();

    $report = app(PermissionSync::class)->sync();

    expect($report->isNoop())->toBeTrue();
    expect(AclEntry::count())->toBe($before);
    expect(app(AclVersion::class)->current())->toBe($version); // nothing written → no cache invalidation

    expect(app(PermissionSync::class)->sync()->isNoop())->toBeTrue(); // and again
});

it('never clobbers an admin-customised acl entry value (a NEVER survives a sync)', function () {
    // The admin hard-denies a baseline permission on a system group at global scope.
    $entry = psyncAdminEntry('prefix.manage');
    expect($entry)->not->toBeNull();
    $entry->update(['value' => V::Never->value]);

    app(PermissionSync::class)->sync();

    // Add-only: the customised value is preserved, NOT overwritten back to ALLOW.
    expect(psyncAdminEntry('prefix.manage')->value)->toBe(V::Never->value);
});

it('heals a missing acl entry even when the role_permission still exists (partial-state repair)', function () {
    psyncAdminEntry('badge.manage')->delete(); // entry lost, role_permission intact
    expect(psyncAdminRolePerm('badge.manage'))->toBeTrue();

    $report = app(PermissionSync::class)->sync();

    expect(psyncAdminEntry('badge.manage'))->not->toBeNull();
    expect($report->entriesWritten['admins'] ?? [])->toContain('badge.manage');
    expect($report->permissionsAdded['administrator'] ?? [])->not->toContain('badge.manage'); // not re-added
});

it('re-inserts a catalog key dropped since the last seed', function () {
    Permission::query()->where('key', 'badge.manage')->delete();

    $report = app(PermissionSync::class)->sync();

    expect(Permission::query()->where('key', 'badge.manage')->exists())->toBeTrue();
    expect($report->catalogAdded)->toContain('badge.manage');
});

it('dry-run reports the exact plan without writing anything', function () {
    Role::query()->where('slug', 'administrator')->first()
        ->permissions()->where('permission_key', 'badge.manage')->delete();
    psyncAdminEntry('badge.manage')->delete();
    $before = AclEntry::count();
    $version = app(AclVersion::class)->current();

    $plan = app(PermissionSync::class)->preview();

    // The plan names the change…
    expect($plan->entriesWritten['admins'] ?? [])->toContain('badge.manage');
    expect($plan->permissionsAdded['administrator'] ?? [])->toContain('badge.manage');
    // …but NOTHING was written.
    expect(AclEntry::count())->toBe($before);
    expect(psyncAdminEntry('badge.manage'))->toBeNull();
    expect(app(AclVersion::class)->current())->toBe($version);

    // A real run then applies exactly what the plan described.
    expect(app(PermissionSync::class)->sync()->totalChanges())->toBe($plan->totalChanges());
});

it('the artisan command applies the sync and is idempotent on a second run', function () {
    psyncAdminEntry('badge.manage')->delete();

    $this->artisan('novfora:permissions:sync')->assertSuccessful();
    expect(psyncAdminEntry('badge.manage'))->not->toBeNull();

    $this->artisan('novfora:permissions:sync')
        ->expectsOutputToContain('already in sync')
        ->assertSuccessful();
});

it('the --dry-run command writes nothing', function () {
    psyncAdminEntry('badge.manage')->delete();

    $this->artisan('novfora:permissions:sync', ['--dry-run' => true])->assertSuccessful();

    expect(psyncAdminEntry('badge.manage'))->toBeNull(); // still missing — dry-run only previewed
});
