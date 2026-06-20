<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Permissions\PermissionValue;
use Database\Seeders\ModeratorBundleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('seeds the three forum-moderator bundles as is_preset roles with exactly their keys', function () {
    foreach (ModeratorBundleSeeder::bundles() as $slug => $data) {
        $role = Role::where('slug', $slug)->first();

        expect($role)->not->toBeNull("bundle {$slug} should be seeded");
        expect($role->is_preset)->toBeTrue("bundle {$slug} must be a preset (read-only in the builder)");

        $map = $role->permissions()->pluck('value', 'permission_key')->map(fn ($v): int => (int) $v);
        expect($map->keys()->sort()->values()->all())
            ->toBe(collect($data['permissions'])->sort()->values()->all(), "exact keys for {$slug}");
        expect($map->unique()->values()->all())
            ->toBe([PermissionValue::Allow->value], "every {$slug} grant is ALLOW");
    }
});

it('does NOT expand any bundle onto a group at global scope (only the projector expands, at forum scope)', function () {
    $bundleRoleIds = Role::whereIn('slug', array_keys(ModeratorBundleSeeder::bundles()))->pluck('id')->all();

    // RoleExpander::assign is what creates RoleAssignment rows; the seeder never calls it, so a freshly seeded
    // board has ZERO assignments of these bundles to any holder. They reach acl_entries ONLY via the projector.
    expect(RoleAssignment::whereIn('role_id', $bundleRoleIds)->count())->toBe(0);
});

it('keeps full ⊃ content/queue capabilities distinct', function () {
    $bundles = ModeratorBundleSeeder::bundles();

    expect($bundles['forum-mod-full']['permissions'])
        ->toContain('topic.moderate')->toContain('post.edit.any')->toContain('bans.manage');
    // content edits posts but does not moderate topics; queue moderates topics but cannot edit others' posts.
    expect($bundles['forum-mod-content']['permissions'])->not->toContain('topic.moderate');
    expect($bundles['forum-mod-queue']['permissions'])->not->toContain('post.edit.any');
});

it('is idempotent — re-running the seeder neither duplicates roles nor keys', function () {
    (new ModeratorBundleSeeder)->run();
    (new ModeratorBundleSeeder)->run();

    foreach (ModeratorBundleSeeder::bundles() as $slug => $data) {
        expect(Role::where('slug', $slug)->count())->toBe(1);
        expect(Role::where('slug', $slug)->first()->permissions()->count())->toBe(count($data['permissions']));
    }
});
