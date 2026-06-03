<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Group;
use App\Models\Permission;
use App\Models\Role;
use App\Permissions\PermissionResolver;
use App\Permissions\PermissionValue as V;
use App\Permissions\RoleExpander;
use Database\Seeders\PermissionCatalogSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Acl;

/*
| The seeded default posture (ADR-0006), validated end-to-end through the REAL resolver: role presets
| expand onto system groups, and the resolver returns the intended verdicts for guest/member/mod/admin.
| Also pins the trust-level groups (the gating primitive; enforcement is M3).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    app(PermissionResolver::class)->flushMemo();
    $this->seed();
});

it('seeds the system and trust-level groups', function () {
    expect(Group::whereIn('slug', ['guests', 'members', 'moderators', 'admins'])->where('is_system', true)->count())->toBe(4);

    $trust = Group::where('type', 'trust')->orderBy('priority')->pluck('slug')->all();
    expect($trust)->toBe(['tl0', 'tl1', 'tl2', 'tl3', 'tl4']);
    expect(Group::where('slug', 'tl1')->first()->auto_promotion)->toMatchArray(['min_posts' => 5, 'min_days' => 1]);
});

it('seeds the full permission catalog with no preset drift', function () {
    $catalog = array_keys(PermissionCatalogSeeder::catalog());
    expect(Permission::count())->toBe(count($catalog));

    // Every key any preset grants must exist in the catalog.
    $used = collect(RoleSeeder::presets())
        ->flatMap(fn (array $preset) => array_keys($preset['permissions']))
        ->unique()
        ->values();
    expect($used->diff($catalog)->all())->toBe([]);
});

it('resolves the default posture: member can read & post but not moderate or admin', function () {
    $acl = Acl::make();
    $member = $acl->user(['members']);

    expect($member->canDo('forum.view', $acl->forumScope))->toBeTrue();
    expect($member->canDo('topic.create', $acl->forumScope))->toBeTrue();
    expect($member->canDo('post.create', $acl->threadScope))->toBeTrue();
    expect($member->canDo('topic.moderate', $acl->forumScope))->toBeFalse();
    expect($member->canDo('admin.access', $acl->global))->toBeFalse();
});

it('resolves the default posture: guest is read-only', function () {
    $acl = Acl::make();
    $guest = $acl->user(['guests']);

    expect($guest->canDo('forum.view', $acl->forumScope))->toBeTrue();
    expect($guest->canDo('topic.create', $acl->forumScope))->toBeFalse();
    expect($guest->canDo('post.create', $acl->threadScope))->toBeFalse();
});

it('resolves the default posture: moderator can moderate but not reach the ACP', function () {
    $acl = Acl::make();
    $mod = $acl->user(['moderators']);

    expect($mod->canDo('forum.view', $acl->forumScope))->toBeTrue();        // inherited from member
    expect($mod->canDo('topic.moderate', $acl->subScope))->toBeTrue();
    expect($mod->canDo('post.delete.any', $acl->threadScope))->toBeTrue();
    expect($mod->canDo('admin.access', $acl->global))->toBeFalse();
});

it('resolves the default posture: admin has full access including the ACP', function () {
    $acl = Acl::make();
    $admin = $acl->user(['admins']);

    expect($admin->canDo('admin.access', $acl->global))->toBeTrue();
    expect($admin->canDo('permissions.manage', $acl->global))->toBeTrue();
    expect($admin->canDo('topic.moderate', $acl->threadInSubScope))->toBeTrue();
    expect($admin->canDo('forum.view', $acl->forumScope))->toBeTrue();
});

it('supports trust-level gating as an ACL primitive (enforcement is M3)', function () {
    $acl = Acl::make();

    // A capability granted only to TL1+, demonstrating the gating primitive over seeded trust groups.
    $acl->grant('tl1', 'links.post', $acl->global, V::Allow);

    $newbie = $acl->user(['members', 'tl0']);
    $trusted = $acl->user(['members', 'tl1']);

    expect($newbie->canDo('links.post', $acl->forumScope))->toBeFalse();
    expect($trusted->canDo('links.post', $acl->forumScope))->toBeTrue();
});

it('re-expands a role onto its assignments when its permissions change (§1.5)', function () {
    $acl = Acl::make();
    $member = $acl->user(['members']);
    expect($acl->can($member, 'topic.moderate', $acl->forumScope))->toBeFalse();

    // The member preset gains a permission; re-expanding pushes it onto every assignment (here: the
    // members group at global). The ACL write bumps the version, so the cached verdict is not stale.
    $role = Role::where('slug', 'member')->firstOrFail();
    $role->permissions()->updateOrCreate(['permission_key' => 'topic.moderate'], ['value' => V::Allow->value]);
    app(RoleExpander::class)->reexpand($role);

    expect($acl->can($member, 'topic.moderate', $acl->forumScope))->toBeTrue();
});
