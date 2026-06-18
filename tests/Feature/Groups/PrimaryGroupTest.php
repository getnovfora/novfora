<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Admin\GroupException;
use App\Admin\GroupManager;
use App\Groups\PrimaryGroupService;
use App\Models\Group;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

// ── Helpers ────────────────────────────────────────────────────────────────────────────────────────────

function pgs(): PrimaryGroupService
{
    return app(PrimaryGroupService::class);
}

function pgGroup(string $name = 'Alpha'): Group
{
    return app(GroupManager::class)->create(['name' => $name.' '.uniqid()]);
}

function pgUser(Group ...$groups): User
{
    $user = User::factory()->create();
    $mgr = app(GroupManager::class);
    foreach ($groups as $g) {
        $mgr->addMembers($g, [$user->id]);
    }

    return $user->fresh();
}

// ── Service — setByUser ───────────────────────────────────────────────────────────────────────────────

it('a user in two groups can choose their primary', function () {
    $a = pgGroup('Alpha');
    $b = pgGroup('Beta');
    $user = pgUser($a, $b);

    pgs()->setByUser($user->fresh(), $b);

    expect((int) $user->fresh()->primaryGroup()?->id)->toBe((int) $b->id);
});

it('setByUser refuses a group the user does not belong to', function () {
    $a = pgGroup('Alpha');
    $user = pgUser($a);
    $c = pgGroup('Outsider');           // user NOT added to this one

    expect(fn () => pgs()->setByUser($user->fresh(), $c))->toThrow(GroupException::class);
});

// ── Service — setByAdmin / lock ───────────────────────────────────────────────────────────────────────

it('setByAdmin sets the primary and locks it', function () {
    $a = pgGroup('Alpha');
    $b = pgGroup('Beta');
    $user = pgUser($a, $b);
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));

    pgs()->setByAdmin($user->fresh(), $b, $admin);

    $fresh = $user->fresh();
    expect((int) $fresh->primaryGroup()?->id)->toBe((int) $b->id);
    expect(pgs()->isAdminLocked($fresh))->toBeTrue();
});

it('setByUser throws while an admin lock is active and does not change the primary', function () {
    $a = pgGroup('Alpha');
    $b = pgGroup('Beta');
    $user = pgUser($a, $b);
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));

    // Admin locks to group B
    pgs()->setByAdmin($user->fresh(), $b, $admin);

    // User tries to switch back to A — must throw
    expect(fn () => pgs()->setByUser($user->fresh(), $a))->toThrow(GroupException::class);

    // Primary must still be B
    expect((int) $user->fresh()->primaryGroup()?->id)->toBe((int) $b->id);
});

// ── Service — clearLock ───────────────────────────────────────────────────────────────────────────────

it('clearLock removes the admin lock but keeps the current primary', function () {
    $a = pgGroup('Alpha');
    $b = pgGroup('Beta');
    $user = pgUser($a, $b);
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));

    pgs()->setByAdmin($user->fresh(), $b, $admin);
    expect(pgs()->isAdminLocked($user->fresh()))->toBeTrue();

    pgs()->clearLock($user->fresh(), $admin);

    $fresh = $user->fresh();
    expect(pgs()->isAdminLocked($fresh))->toBeFalse();
    // Current primary is still B (unlock keeps it)
    expect((int) $fresh->primaryGroup()?->id)->toBe((int) $b->id);
});

it('setByUser works again after clearLock', function () {
    $a = pgGroup('Alpha');
    $b = pgGroup('Beta');
    $user = pgUser($a, $b);
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));

    pgs()->setByAdmin($user->fresh(), $b, $admin);
    pgs()->clearLock($user->fresh(), $admin);

    // Now user can freely switch
    pgs()->setByUser($user->fresh(), $a);

    expect((int) $user->fresh()->primaryGroup()?->id)->toBe((int) $a->id);
});

// ── User SFC — settings.primary-group ────────────────────────────────────────────────────────────────

it('unauthenticated access to the SFC is refused (403)', function () {
    Livewire::test('settings.primary-group')->assertStatus(403);
});

it('the SFC mounts and shows the user\'s groups', function () {
    $a = pgGroup('Alpha');
    $b = pgGroup('Beta');
    $user = pgUser($a, $b);

    $this->actingAs($user);

    Livewire::test('settings.primary-group')
        ->assertStatus(200)
        ->assertSee($a->name)
        ->assertSee($b->name);
});

it('the user SFC can switch primary with the save action and updates the primary', function () {
    $a = pgGroup('Alpha');
    $b = pgGroup('Beta');
    $user = pgUser($a, $b);

    $this->actingAs($user);

    Livewire::test('settings.primary-group')
        ->set('primaryGroupId', (int) $b->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Primary group updated');

    expect((int) $user->fresh()->primaryGroup()?->id)->toBe((int) $b->id);
});

it('the user SFC reports an error when the admin lock is active', function () {
    $a = pgGroup('Alpha');
    $b = pgGroup('Beta');
    $user = pgUser($a, $b);
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));

    pgs()->setByAdmin($user->fresh(), $b, $admin);

    $this->actingAs($user);

    Livewire::test('settings.primary-group')
        ->assertStatus(200)
        ->assertSee('administrator has set your primary group');
});

it('the user SFC returns an error on invalid group id', function () {
    $a = pgGroup('Alpha');
    $user = pgUser($a);

    $this->actingAs($user);

    Livewire::test('settings.primary-group')
        ->set('primaryGroupId', 0)
        ->call('save')
        ->assertSee('Please select a group');
});
