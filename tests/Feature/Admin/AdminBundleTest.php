<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Admin\AdminBundleException;
use App\Admin\AdminBundleService;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Permissions\AclVersion;
use App\Permissions\PermissionResolver;
use App\Permissions\RoleException;
use App\Permissions\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Users;

/*
| ACP v3 · v3-a (ADR-0080) — AdminBundleService (the Admin Manager). A "restricted admin" is NOT in the admins
| group; they hold admin.access + their bundle's admin.<section>.access keys as PER-USER global grants (disjoint
| rows from the group preset). The service only ever writes user-holder rows, so a full admin is never touched.
| The G10 escalation fence: only a full admin holding a key may grant it (RoleManager::assertWithinCeiling), so a
| restricted admin (isAdmin()===false) can never assign or mint admin-tier keys. The resolver is the oracle.
*/

uses(RefreshDatabase::class);

const COMMUNITY_SECTIONS = ['admin.forums.access', 'admin.members.access', 'admin.groups.access', 'admin.moderation.access'];
const OTHER_SECTIONS = ['admin.appearance.access', 'admin.plugins.access', 'admin.analytics.access', 'admin.settings.access', 'admin.system.access'];

beforeEach(function () {
    Cache::flush();
    app(PermissionResolver::class)->flushMemo();
    $this->seed();
});

function adminBundle(string $slug): Role
{
    return Role::query()->where('slug', $slug)->firstOrFail();
}

/** A clean-request resolve: flush the memo (the bump already re-keyed the cross-request cache). */
function can(User $u, string $key): bool
{
    app(PermissionResolver::class)->flushMemo();

    return $u->fresh()->canDo($key, Scope::global());
}

it('the bundle presets are seeded as read-only roles with the right section keys', function () {
    foreach (['admin-bundle-full', 'admin-bundle-community', 'admin-bundle-content', 'admin-bundle-style', 'admin-bundle-analytics', 'admin-bundle-custom'] as $slug) {
        expect(adminBundle($slug)->is_preset)->toBeTrue();
    }
    expect(adminBundle('admin-bundle-full')->permissions()->pluck('permission_key')->all())
        ->toHaveCount(9)->not->toContain('admin.security.access')->not->toContain('admin.access');
    expect(adminBundle('admin-bundle-custom')->permissions()->count())->toBe(0); // blank starting point
});

it('assigning a bundle makes a restricted admin who sees ONLY the granted sections', function () {
    $owner = Users::inGroups(['admins']);   // a full admin actor (holds every section via the preset)
    $target = Users::inGroups(['members']); // a plain member, NOT in admins

    app(AdminBundleService::class)->assign($owner, $target, adminBundle('admin-bundle-community'));

    expect(app(AdminBundleService::class)->isRestrictedAdmin($target))->toBeTrue();
    expect(can($target, 'admin.access'))->toBeTrue(); // EnsureSystemPanelAccess admits them
    foreach (COMMUNITY_SECTIONS as $key) {
        expect(can($target, $key))->toBeTrue("restricted admin should hold {$key}");
    }
    foreach ([...OTHER_SECTIONS, 'admin.security.access'] as $key) {
        expect(can($target, $key))->toBeFalse("restricted admin must NOT hold {$key}");
    }
    // NOT a group admin — the model invariant.
    expect($target->fresh()->isAdmin())->toBeFalse();
    expect(app(AdminBundleService::class)->grantedSections($target))->toEqualCanonicalizing(COMMUNITY_SECTIONS);
});

it('leaves a full (group) admin entirely unaffected', function () {
    $owner = Users::inGroups(['admins']);
    $fullAdmin = Users::inGroups(['admins']);
    $target = Users::inGroups(['members']);

    app(AdminBundleService::class)->assign($owner, $target, adminBundle('admin-bundle-community'));

    // The full admin still resolves ALLOW on every section (group preset, untouched — only user-holder rows changed).
    foreach ([...COMMUNITY_SECTIONS, ...OTHER_SECTIONS] as $key) {
        expect(can($fullAdmin, $key))->toBeTrue("full admin keeps {$key}");
    }
    expect(app(AdminBundleService::class)->isRestrictedAdmin($fullAdmin))->toBeFalse(); // group admin, not restricted
});

it('replacing a bundle converges: sections from the old bundle that the new one lacks are dropped', function () {
    $owner = Users::inGroups(['admins']);
    $target = Users::inGroups(['members']);

    app(AdminBundleService::class)->assign($owner, $target, adminBundle('admin-bundle-community'));
    expect(can($target, 'admin.members.access'))->toBeTrue();

    // Replace with Style (appearance only).
    app(AdminBundleService::class)->assign($owner, $target, adminBundle('admin-bundle-style'));

    expect(can($target, 'admin.appearance.access'))->toBeTrue();
    foreach (COMMUNITY_SECTIONS as $key) {
        expect(can($target, $key))->toBeFalse("replaced bundle dropped {$key}");
    }
    expect(can($target, 'admin.access'))->toBeTrue(); // still a restricted admin (panel access retained)
});

it('revoke strips ALL restricted-admin access and flips every verdict', function () {
    $owner = Users::inGroups(['admins']);
    $target = Users::inGroups(['members']);
    app(AdminBundleService::class)->assign($owner, $target, adminBundle('admin-bundle-full'));
    expect(can($target, 'admin.system.access'))->toBeTrue();

    app(AdminBundleService::class)->revoke($owner, $target);

    expect(app(AdminBundleService::class)->isRestrictedAdmin($target))->toBeFalse();
    foreach (['admin.access', ...COMMUNITY_SECTIONS, ...OTHER_SECTIONS] as $key) {
        expect(can($target, $key))->toBeFalse("revoked: {$key}");
    }
});

it('setSectionAccess toggles a single section and keeps admin.access until a full revoke', function () {
    $owner = Users::inGroups(['admins']);
    $target = Users::inGroups(['members']);

    // Grant one section → becomes a restricted admin with panel access + that section.
    app(AdminBundleService::class)->setSectionAccess($owner, $target, 'admin.appearance.access', true);
    expect(can($target, 'admin.access'))->toBeTrue();
    expect(can($target, 'admin.appearance.access'))->toBeTrue();

    // Revoke that one section → panel access (admin.access) stays; the section is gone.
    app(AdminBundleService::class)->setSectionAccess($owner, $target, 'admin.appearance.access', false);
    expect(can($target, 'admin.appearance.access'))->toBeFalse();
    expect(can($target, 'admin.access'))->toBeTrue();
    expect(app(AdminBundleService::class)->isRestrictedAdmin($target))->toBeTrue();
});

it('rejects a non-assignable section key', function () {
    $owner = Users::inGroups(['admins']);
    $target = Users::inGroups(['members']);

    expect(fn () => app(AdminBundleService::class)->setSectionAccess($owner, $target, 'admin.security.access', true))
        ->toThrow(AdminBundleException::class); // security is the co-owner tier, never bundle-assignable
    expect(fn () => app(AdminBundleService::class)->setSectionAccess($owner, $target, 'not.a.key', true))
        ->toThrow(AdminBundleException::class);
});

it('the G10 fence: a restricted admin can neither assign a bundle nor mint an admin-tier section', function () {
    $owner = Users::inGroups(['admins']);
    $restricted = Users::inGroups(['members']);
    app(AdminBundleService::class)->assign($owner, $restricted, adminBundle('admin-bundle-community'));
    expect($restricted->fresh()->isAdmin())->toBeFalse(); // holds keys per-user, but is NOT a group admin

    $victim = Users::inGroups(['members']);

    // assertWithinCeiling rule 1 (Administration-tier keys require a FULL admin) throws for a restricted admin.
    expect(fn () => app(AdminBundleService::class)->assign($restricted->fresh(), $victim, adminBundle('admin-bundle-community')))
        ->toThrow(RoleException::class);
    expect(fn () => app(AdminBundleService::class)->setSectionAccess($restricted->fresh(), $victim, 'admin.forums.access', true))
        ->toThrow(RoleException::class);
    expect(app(AdminBundleService::class)->isRestrictedAdmin($victim))->toBeFalse(); // no escalation occurred
});

it('a restricted admin cannot grant a section beyond their own ceiling', function () {
    $owner = Users::inGroups(['admins']);
    // A restricted admin with only Community sections does not hold admin.appearance.access…
    $restricted = Users::inGroups(['members']);
    app(AdminBundleService::class)->assign($owner, $restricted, adminBundle('admin-bundle-community'));

    // …even setting aside the isAdmin fence, they could not grant a key they do not themselves hold (ceiling).
    // (isAdmin fires first here, but this documents the ceiling intent for any future full-but-restricted actor.)
    expect(fn () => app(AdminBundleService::class)->setSectionAccess($restricted->fresh(), Users::inGroups(['members']), 'admin.appearance.access', true))
        ->toThrow(RoleException::class);
});

it('a non-admin actor cannot REVOKE a restricted admin (the destructive backstop)', function () {
    $owner = Users::inGroups(['admins']);
    $target = Users::inGroups(['members']);
    app(AdminBundleService::class)->assign($owner, $target, adminBundle('admin-bundle-community'));

    $member = Users::inGroups(['members']); // isAdmin() === false

    expect(fn () => app(AdminBundleService::class)->revoke($member, $target))
        ->toThrow(AdminBundleException::class);
    expect(fn () => app(AdminBundleService::class)->setSectionAccess($member, $target, 'admin.forums.access', false))
        ->toThrow(AdminBundleException::class);

    // The restricted admin's access is fully intact — neither destructive call landed.
    expect(app(AdminBundleService::class)->isRestrictedAdmin($target))->toBeTrue();
    expect(can($target, 'admin.forums.access'))->toBeTrue();
});

it('a no-op section revoke writes nothing — no AclVersion bump, no phantom audit row', function () {
    $owner = Users::inGroups(['admins']);
    $target = Users::inGroups(['members']); // never granted any section

    $version = app(AclVersion::class)->current();
    app(AdminBundleService::class)->setSectionAccess($owner, $target, 'admin.forums.access', false); // no-op

    expect(app(AclVersion::class)->current())->toBe($version);
    expect(AuditLog::where('action', 'admin.section.revoked')->where('auditable_id', $target->id)->exists())->toBeFalse();
});
