<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\AclEntry;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Role;
use App\Permissions\PermissionResolver;
use App\Permissions\PermissionSync;
use App\Permissions\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Users;

/*
| ACP v3 · v3-a (ADR-0080) — the 10 per-section admin.<section>.access keys. The administrator preset grants the
| NINE non-security keys additively, so every existing admin keeps the full rail; admin.security.access is held
| only by co-owners. PermissionSync must propagate the nine onto installs seeded before this release (the
| "no admin loses the rail on upgrade" invariant — a regression here is an operator-team self-lockout).
*/

uses(RefreshDatabase::class);

/** The nine section keys the administrator preset grants (every rail section except Security). */
const PRESET_SECTION_KEYS = [
    'admin.forums.access', 'admin.members.access', 'admin.groups.access', 'admin.moderation.access',
    'admin.appearance.access', 'admin.plugins.access', 'admin.analytics.access', 'admin.settings.access',
    'admin.system.access',
];

beforeEach(function () {
    Cache::flush();
    app(PermissionResolver::class)->flushMemo();
    $this->seed();
});

it('registers all ten per-section access keys in the Administration catalog cluster', function () {
    $keys = [...PRESET_SECTION_KEYS, 'admin.security.access'];

    foreach ($keys as $key) {
        $perm = Permission::query()->where('key', $key)->first();
        expect($perm)->not->toBeNull("catalog must define {$key}");
        expect($perm->group)->toBe('Administration'); // → admin-tier, only a full admin may mint it into a role
        expect($perm->scope_kind)->toBe('global');
    }
});

it('grants an existing admin every non-security section key via the preset, but not security', function () {
    $admin = Users::inGroups(['admins']);

    foreach (PRESET_SECTION_KEYS as $key) {
        expect($admin->canDo($key, Scope::global()))->toBeTrue("admin should hold {$key} via the preset");
    }

    // admin.security.access is co-owner-only — a plain admins-group member never inherits it from the preset.
    expect($admin->canDo('admin.security.access', Scope::global()))->toBeFalse();
});

it('grants a non-admin none of the section keys', function () {
    $member = Users::inGroups(['members']);

    foreach ([...PRESET_SECTION_KEYS, 'admin.security.access'] as $key) {
        expect($member->canDo($key, Scope::global()))->toBeFalse("member must not hold {$key}");
    }
});

it('propagates the nine section keys onto an install seeded before v3-a (PermissionSync, add-only)', function () {
    $admin = Users::inGroups(['admins']);
    $adminsId = (int) Group::query()->where('slug', 'admins')->value('id');

    // Simulate a pre-v3-a install: strip the section keys from both the administrator role and the admins-group
    // global acl_entries, so the admin resolves NO on each (the "403 on the new section" repro).
    Role::query()->where('slug', 'administrator')->first()
        ->permissions()->whereIn('permission_key', PRESET_SECTION_KEYS)->delete();
    AclEntry::query()->where('holder_type', 'group')->where('holder_id', $adminsId)
        ->where('scope_type', 'global')->whereNull('scope_id')
        ->whereIn('permission_key', PRESET_SECTION_KEYS)->delete();
    Cache::flush();
    app(PermissionResolver::class)->flushMemo();

    foreach (PRESET_SECTION_KEYS as $key) {
        expect($admin->fresh()->canDo($key, Scope::global()))->toBeFalse("repro: {$key} denied pre-sync");
    }

    $report = app(PermissionSync::class)->sync();
    app(PermissionResolver::class)->flushMemo(); // simulate the next request (fresh memo); the bump cleared the cache

    foreach (PRESET_SECTION_KEYS as $key) {
        expect($admin->fresh()->canDo($key, Scope::global()))->toBeTrue("post-sync: {$key} restored");
        expect($report->permissionsAdded['administrator'] ?? [])->toContain($key);
        expect($report->entriesWritten['admins'] ?? [])->toContain($key);
    }

    // Security is NOT a preset key, so the sync must never grant it to the admins group.
    expect($report->entriesWritten['admins'] ?? [])->not->toContain('admin.security.access');
});
