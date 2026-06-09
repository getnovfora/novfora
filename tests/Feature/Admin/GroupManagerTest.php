<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Admin\GroupException;
use App\Admin\GroupManager;
use App\Models\AclEntry;
use App\Models\AuditLog;
use App\Models\Group;
use App\Models\Role;
use App\Permissions\PermissionResolver;
use App\Permissions\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function gm(): GroupManager
{
    return app(GroupManager::class);
}

it('creates a custom group and a member gains its permissions THROUGH the engine', function () {
    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));

    $moderator = Role::where('slug', 'moderator')->firstOrFail();
    $group = gm()->create(['name' => 'VIP Mods', 'color' => 'indigo', 'priority' => 60, 'role_id' => $moderator->id]);

    expect($group->type)->toBe('custom')
        ->and($group->is_system)->toBeFalse()
        ->and($group->color)->toBe('indigo');

    // A plain member added to the group resolves topic.moderate as ALLOWED — the moderator role expanded
    // onto the group into acl_entries, which is all the resolver reads (no second permission system).
    $member = Users::inGroups(['members', 'tl1']);
    expect($member->canDo('topic.moderate', Scope::global()))->toBeFalse();

    gm()->addMembers($group, [$member->id]);

    // The membership change and the permission check are separate requests in production; flush the
    // per-request resolver memo (which keys by user id) to model that — the cross-request cache is already
    // signature-keyed, so it self-invalidates.
    app(PermissionResolver::class)->flushMemo();
    expect($member->fresh()->canDo('topic.moderate', Scope::global()))->toBeTrue();
});

it('refuses to delete a system group', function () {
    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));
    $admins = Group::where('slug', 'admins')->firstOrFail();

    expect(fn () => gm()->delete($admins))->toThrow(GroupException::class);
    expect(Group::where('slug', 'admins')->exists())->toBeTrue();
});

it('enforces delete-safety: a non-empty custom group must reassign its members first', function () {
    $group = gm()->create(['name' => 'Temp']);
    $dest = gm()->create(['name' => 'Keepers']);
    $member = Users::inGroups(['members']);
    gm()->addMembers($group, [$member->id]);

    // No destination → refused, group + membership intact.
    expect(fn () => gm()->delete($group))->toThrow(GroupException::class);
    expect(Group::find($group->id))->not->toBeNull();

    // With a destination → members are reassigned, then the group is removed.
    $moved = gm()->delete($group, $dest);
    expect($moved)->toBe(1)
        ->and(Group::find($group->id))->toBeNull()
        ->and($member->fresh()->groups->pluck('slug'))->toContain($dest->slug);
});

it('deleting a custom group removes its role assignment + expanded acl entries', function () {
    $moderator = Role::where('slug', 'moderator')->firstOrFail();
    $group = gm()->create(['name' => 'Disposable', 'role_id' => $moderator->id]);

    expect(AclEntry::where('holder_type', 'group')->where('holder_id', $group->id)->exists())->toBeTrue();

    gm()->delete($group);

    expect(AclEntry::where('holder_type', 'group')->where('holder_id', $group->id)->exists())->toBeFalse();
});

it('protects system-group structure: only label/colour change, never slug/type/priority/role', function () {
    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));
    $mods = Group::where('slug', 'moderators')->firstOrFail();
    [$slug, $type, $priority] = [$mods->slug, $mods->type, $mods->priority];
    $guestRole = Role::where('slug', 'guest')->firstOrFail();

    gm()->update($mods, ['name' => 'Staff', 'color' => 'red', 'priority' => 3, 'role_id' => $guestRole->id]);

    $mods->refresh();
    expect($mods->name)->toBe('Staff')        // label editable
        ->and($mods->color)->toBe('red')      // colour editable
        ->and($mods->slug)->toBe($slug)       // identity protected
        ->and($mods->type)->toBe($type)
        ->and($mods->priority)->toBe($priority); // priority NOT changed for a system group
    // The moderator role assignment is untouched — a staff member still moderates.
    expect(Users::inGroups(['moderators'])->canDo('topic.moderate', Scope::global()))->toBeTrue();
});

it('keeps the membership boundary: trust and base groups cannot be hand-edited', function () {
    $member = Users::inGroups(['members']);

    foreach (['tl1', 'guests', 'members'] as $slug) {
        $group = Group::where('slug', $slug)->firstOrFail();
        expect(fn () => gm()->addMembers($group, [$member->id]))->toThrow(GroupException::class);
    }

    // …but a custom or staff group is fine.
    $custom = gm()->create(['name' => 'Helpers']);
    expect(gm()->addMembers($custom, [$member->id]))->toBe(1);
    gm()->removeMember($custom, $member->id);
    expect($custom->users()->count())->toBe(0);
});

it('renders the groups page for a 2FA admin and lists groups; blocks a non-admin', function () {
    $this->get(route('admin.members.groups'))->assertRedirect(route('login')); // guest

    $member = Users::inGroups(['members', 'tl4']);
    $this->actingAs($member)->get(route('admin.members.groups'))->assertForbidden();

    $admin = Users::withTwoFactor(Users::inGroups(['admins']));
    $this->actingAs($admin)->get(route('admin.members.groups'))
        ->assertOk()
        ->assertSee('Member groups')
        ->assertSee('Moderators');
});

it('self-guards the Livewire component and creates a group through the UI', function () {
    // A non-admin reaching the component directly is 403'd (Livewire actions carry no route middleware).
    $this->actingAs(Users::inGroups(['members', 'tl0']));
    Livewire::test('admin.groups')->assertStatus(403);

    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));
    Livewire::test('admin.groups')
        ->call('newGroup')
        ->set('name', 'Beta Testers')
        ->set('color', 'green')
        ->set('priority', 55)
        ->call('save')
        ->assertHasNoErrors();

    $group = Group::where('name', 'Beta Testers')->first();
    expect($group)->not->toBeNull()
        ->and($group->type)->toBe('custom')
        ->and($group->color)->toBe('green');
});

it('ignores an out-of-palette colour (falls back to no colour)', function () {
    $group = gm()->create(['name' => 'X', 'color' => 'rainbow']);
    expect($group->color)->toBeNull();
});

it('caps custom-group priority below Moderators so the rank guard still holds', function () {
    $group = gm()->create(['name' => 'Loud', 'priority' => 99]);
    expect($group->priority)->toBe(79); // clamped strictly below Moderators (80)

    $member = Users::inGroups(['members']);
    gm()->addMembers($group, [$member->id]);

    // A member in even the highest custom group still ranks below a moderator → F-F guard preserved.
    expect($member->fresh()->rankPriority())->toBeLessThan(Users::inGroups(['moderators'])->rankPriority());
});

it('delete-with-reassign honours the membership boundary (refuses trust / base destinations)', function () {
    $group = gm()->create(['name' => 'Temp']);
    $member = Users::inGroups(['tl0']); // deliberately NOT in any tested destination group
    gm()->addMembers($group, [$member->id]);

    foreach (['tl4', 'tl1', 'guests', 'members'] as $slug) {
        $dest = Group::where('slug', $slug)->firstOrFail();
        expect(fn () => gm()->delete($group, $dest))->toThrow(GroupException::class);
        expect(Group::find($group->id))->not->toBeNull()
            ->and($member->fresh()->groups->pluck('slug'))->not->toContain($slug);
    }

    // …a custom destination is still accepted (the boundary only blocks trust/base groups).
    $ok = gm()->create(['name' => 'Keepers']);
    expect(gm()->delete($group, $ok))->toBe(1);
});

it('audit-logs a group permission-preset change (from/to), not just the name', function () {
    $moderator = Role::where('slug', 'moderator')->firstOrFail();
    gm()->create(['name' => 'Helpers', 'role_id' => $moderator->id]);

    $entry = AuditLog::where('action', 'group.role.assigned')->latest('id')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->changes['to_role'])->toBe('Moderator');
});
