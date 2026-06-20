<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Admin\AdminBundleService;
use App\Admin\AdminNavigation;
use App\Models\AclEntry;
use App\Models\Group;
use App\Models\Role;
use App\Models\User;
use App\Permissions\PermissionResolver;
use App\Permissions\PermissionValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Users;

/*
| ACP v3 · v3-a (ADR-0080) — per-section rail + landing gating. A section appears in the rail and its landing
| loads ONLY if the viewer holds admin.<section>.access. A full admin holds the nine non-security keys via the
| preset; a bundle-restricted admin only their granted subset; admin.security.access is co-owner-only. 'overview'
| (the dashboard) stays the any-admin home. The render-walk's "no existing admin loses the rail" invariant is in
| AdminAccessWalkTest; this pins the RESTRICTED side + the direct-URL landing guard.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    app(PermissionResolver::class)->flushMemo();
    $this->seed();
});

/** A 2FA co-owner: admins membership + is_co_owner + the admin.security.access grant. */
function railCoOwner(): User
{
    $u = Users::withTwoFactor(Users::inGroups(['admins']));
    $adminsId = (int) Group::query()->where('slug', 'admins')->value('id');
    $u->groups()->updateExistingPivot($adminsId, ['is_co_owner' => true]);
    AclEntry::updateOrCreate(
        ['permission_key' => 'admin.security.access', 'holder_type' => 'user', 'holder_id' => (int) $u->id,
            'scope_type' => 'global', 'scope_id' => null],
        ['value' => PermissionValue::Allow->value],
    );

    return $u->fresh();
}

/** The rail section keys visible to the currently-authenticated user. @return list<string> */
function railKeys(): array
{
    app(PermissionResolver::class)->flushMemo();

    return array_column(AdminNavigation::rail(), 'key');
}

it('a full admin sees every section EXCEPT Security (co-owner only)', function () {
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));
    $this->actingAs($admin);

    $keys = railKeys();
    foreach (['overview', 'forums', 'members', 'groups', 'moderation', 'appearance', 'plugins', 'analytics', 'settings', 'system'] as $k) {
        expect($keys)->toContain($k);
    }
    expect($keys)->not->toContain('security'); // no admin.security.access → no Security in the rail

    $this->get(route('admin.forums'))->assertOk();
    $this->get(route('admin.analytics'))->assertOk();
    $this->get(route('admin.security'))->assertForbidden(); // the landing is co-owner-gated
});

it('a co-owner sees every section including Security', function () {
    $co = railCoOwner();
    $this->actingAs($co);

    expect(railKeys())->toContain('security');
    $this->get(route('admin.security'))->assertOk();
});

it('a bundle-restricted admin sees ONLY their granted sections in the rail and landings', function () {
    $owner = Users::inGroups(['admins']);
    $restricted = Users::withTwoFactor(Users::inGroups(['members'])); // 2FA so they may use the panel
    app(AdminBundleService::class)->assign($owner, $restricted, Role::query()->where('slug', 'admin-bundle-community')->firstOrFail());
    $restricted = $restricted->fresh();
    $this->actingAs($restricted);

    $keys = railKeys();
    expect($keys)->toContain('overview', 'forums', 'members', 'groups', 'moderation');
    foreach (['appearance', 'plugins', 'analytics', 'settings', 'system', 'security'] as $k) {
        expect($keys)->not->toContain($k);
    }

    // Granted landings load; un-granted ones 403 even by direct URL (SectionController is the authority).
    $this->get(route('admin.forums'))->assertOk();
    $this->get(route('admin.moderation'))->assertOk();
    $this->get(route('admin.appearance'))->assertForbidden();
    $this->get(route('admin.analytics'))->assertForbidden();
    $this->get(route('admin.security'))->assertForbidden();
});

it('a restricted admin must carry 2FA to reach the panel (security-by-default, not just group staff)', function () {
    $owner = Users::inGroups(['admins']);
    $noTwoFa = Users::inGroups(['members']); // a member, NO 2FA
    app(AdminBundleService::class)->assign($owner, $noTwoFa, Role::query()->where('slug', 'admin-bundle-community')->firstOrFail());

    // They hold admin.access (a per-user grant) but no confirmed authenticator → bounced to 2FA setup, even
    // though isStaff() is false (they are not in a staff group).
    $this->actingAs($noTwoFa->fresh())->get(route('admin.forums'))->assertRedirect(route('settings.two-factor'));
});
