<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Admin\AdminBundleService;
use App\Admin\AdminCoOwnerService;
use App\Models\AclEntry;
use App\Models\Group;
use App\Models\User;
use App\Permissions\PermissionResolver;
use App\Permissions\PermissionValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| ACP v3 · v3-a (ADR-0080) — the Security section SFCs (Co-owners + Admin Manager). Every page render and every
| Livewire action re-asserts admin.security.access (co-owner) in mount(), because Livewire actions reach the
| component with NO route middleware. The panels are thin wiring over AdminCoOwnerService / AdminBundleService
| (both apex-tested); these tests pin the GATE and the wiring, not the engine.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    app(PermissionResolver::class)->flushMemo();
    $this->seed();
});

/** A 2FA co-owner (the operator): admins membership + is_co_owner + the admin.security.access grant. */
function panelCoOwner(): User
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

it('renders the Security pages for a co-owner', function () {
    $co = panelCoOwner();
    $this->actingAs($co)->get(route('admin.security.co-owners'))->assertOk()->assertSee('Co-owners');
    $this->actingAs($co)->get(route('admin.security.accounts'))->assertOk()->assertSee('Admin Manager');
});

it('forbids the Security pages to a 2FA admin who is NOT a co-owner', function () {
    $admin = Users::withTwoFactor(Users::inGroups(['admins'])); // a full admin, but not a co-owner

    $this->actingAs($admin)->get(route('admin.security.co-owners'))->assertForbidden();
    $this->actingAs($admin)->get(route('admin.security.accounts'))->assertForbidden();
});

it('forbids mounting either SFC for a non-co-owner (the action-level gate)', function () {
    foreach (['admin.security.co-owners', 'admin.security.admin-accounts'] as $component) {
        Livewire::actingAs(Users::withTwoFactor(Users::inGroups(['admins'])))->test($component)->assertForbidden();
        Livewire::actingAs(Users::inGroups(['members']))->test($component)->assertForbidden();
    }
});

it('a co-owner appoints and then removes another co-owner through the SFC', function () {
    $co = panelCoOwner();
    $admin = Users::inGroups(['admins']); // a plain admin to promote

    Livewire::actingAs($co)->test('admin.security.co-owners')->call('appoint', $admin->id)->assertHasNoErrors();
    expect(app(AdminCoOwnerService::class)->isCoOwner($admin->fresh()))->toBeTrue();

    Livewire::actingAs($co)->test('admin.security.co-owners')->call('remove', $admin->id)->assertHasNoErrors();
    expect(app(AdminCoOwnerService::class)->isCoOwner($admin->fresh()))->toBeFalse();
});

it('the co-owners SFC surfaces the last-owner guard instead of stranding the tier', function () {
    $co = panelCoOwner(); // the SOLE co-owner

    Livewire::actingAs($co)->test('admin.security.co-owners')
        ->call('remove', $co->id)
        ->assertSee('last co-owner'); // the guard message is flashed, not a 500

    expect(app(AdminCoOwnerService::class)->isCoOwner($co->fresh()))->toBeTrue(); // still a co-owner — not stranded
});

it('a co-owner makes a restricted admin via the Admin Manager SFC', function () {
    $co = panelCoOwner();
    $member = Users::inGroups(['members']);

    Livewire::actingAs($co)->test('admin.security.admin-accounts')
        ->call('applyBundle', $member->id, 'admin-bundle-community')
        ->assertHasNoErrors();

    $member = $member->fresh();
    expect(app(AdminBundleService::class)->isRestrictedAdmin($member))->toBeTrue();
    expect(app(AdminBundleService::class)->grantedSections($member))->toContain('admin.forums.access');

    // …and a per-section toggle off works too.
    Livewire::actingAs($co)->test('admin.security.admin-accounts')
        ->call('toggleSection', $member->id, 'admin.forums.access')
        ->assertHasNoErrors();
    expect(app(AdminBundleService::class)->grantedSections($member->fresh()))->not->toContain('admin.forums.access');
});
