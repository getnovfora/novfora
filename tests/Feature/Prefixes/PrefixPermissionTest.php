<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Permissions\PermissionValue;
use App\Permissions\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Acl;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('grants prefix.manage to an admin', function () {
    $acl = Acl::make();
    $admin = Users::inGroups(['admins']);

    // Seeded administrator preset includes prefix.manage (via RoleSeeder).
    $acl->assertDecision($admin, 'prefix.manage', Scope::global(), true);
});

it('denies prefix.manage to a plain member', function () {
    $acl = Acl::make();
    $member = Users::inGroups(['members', 'tl1']);

    $acl->assertDecision($member, 'prefix.manage', Scope::global(), false);
});

it('denies prefix.manage when a NEVER override is in place', function () {
    $acl = Acl::make();
    $admin = Users::inGroups(['admins']);

    // Even with the seeded allow from the administrator role, a personal NEVER wins.
    $acl->grant($admin, 'prefix.manage', Scope::global(), PermissionValue::Never);

    $acl->assertDecision($admin, 'prefix.manage', Scope::global(), false);
});

it('denies prefix.manage to a moderator', function () {
    $acl = Acl::make();
    $mod = Users::inGroups(['moderators']);

    $acl->assertDecision($mod, 'prefix.manage', Scope::global(), false);
});
