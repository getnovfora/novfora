<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

/*
| Admin-panel authorization (ADR-0006) + the staff-2FA requirement (the brief's "Must"):
|  - admin.access is enforced through the permission engine (EnsureSystemPanelAccess);
|  - staff (admins/moderators) must additionally have a confirmed authenticator
|    (RequireTwoFactorForStaff) before reaching privileged routes.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class)); // default groups + roles + ACL posture

it('redirects guests to login from the admin panels', function () {
    $this->get(route('admin.security.permissions'))->assertRedirect(route('login'));
});

it('forbids a non-admin member from the admin panels (no admin.access)', function () {
    $member = Users::inGroups(['members']);

    $this->actingAs($member)->get(route('admin.security.permissions'))->assertForbidden();
});

it('requires staff to enable 2FA before reaching the admin panels', function () {
    $admin = Users::inGroups(['admins']); // has admin.access, but no confirmed 2FA

    $this->actingAs($admin)->get(route('admin.security.permissions'))
        ->assertRedirect(route('settings.two-factor'));
});

it('admits an admin with admin.access and confirmed 2FA', function () {
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));

    $this->actingAs($admin)->get(route('admin.security.permissions'))
        ->assertOk()
        ->assertSee('Permission Inspector');
});

it('lets staff reach the 2FA setup page (it is not behind the staff gate)', function () {
    $mod = Users::inGroups(['moderators']);

    $this->actingAs($mod)->get(route('settings.two-factor'))
        ->assertOk()
        ->assertSee('Two-factor');
});
