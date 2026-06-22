<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\AclEntry;
use App\Models\Group;
use App\Models\User;
use App\Permissions\PermissionValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

/** A 2FA co-owner (admins membership + is_co_owner + admin.security.access) — reaches the Security panes. */
function discoverabilityCoOwner(): User
{
    $u = Users::withTwoFactor(Users::inGroups(['admins']));
    $adminsId = (int) Group::where('slug', 'admins')->value('id');
    $u->groups()->updateExistingPivot($adminsId, ['is_co_owner' => true]);
    AclEntry::updateOrCreate(
        ['permission_key' => 'admin.security.access', 'holder_type' => 'user', 'holder_id' => (int) $u->id,
            'scope_type' => 'global', 'scope_id' => null],
        ['value' => PermissionValue::Allow->value],
    );

    return $u->fresh();
}

it('links the Admin Accounts pane to the Groups manager so adding a full admin is discoverable', function () {
    $this->actingAs(discoverabilityCoOwner());

    Livewire::test('admin.security.admin-accounts')
        ->assertSeeHtml('href="'.route('admin.members.groups').'"');
});

it('links the Co-owners empty state to the Groups manager', function () {
    // The lone admin is already a co-owner → the appoint list is empty and shows the Groups link.
    $this->actingAs(discoverabilityCoOwner());

    Livewire::test('admin.security.co-owners')
        ->assertSeeHtml('href="'.route('admin.members.groups').'"');
});

it('gives the admins group row a clear add/manage-members affordance', function () {
    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));

    // Icon-only affordance now; the intent rides the title + the dusk hook (click selector unchanged).
    Livewire::test('admin.groups')
        ->assertSeeHtml('dusk="acp-admins-members"')
        ->assertSee('Add or remove administrators');
});
