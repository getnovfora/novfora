<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Admin\GroupManager;
use App\Models\Role;
use App\Models\User;
use App\Support\PermMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function uxGm(): GroupManager
{
    return app(GroupManager::class);
}

it('filters the group list by name', function () {
    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));
    uxGm()->create(['name' => 'Zebra Club']);

    Livewire::test('admin.groups')
        ->set('groupSearch', 'Zebra')
        ->assertSee('Zebra Club')
        ->assertDontSee('Moderators');
});

it('caps the member list at 50 and reveals all on demand', function () {
    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));
    $group = uxGm()->create(['name' => 'Crowd']);
    $users = User::factory()->count(51)->create();
    $group->users()->attach($users->pluck('id')->mapWithKeys(fn ($id) => [(int) $id => ['is_primary' => false]])->all());

    $component = Livewire::test('admin.groups')->call('manageMembers', $group->id);
    $component->assertSee('Show all 51'); // capped at 50 → offer to reveal the rest

    $component->call('revealAllMembers')->assertDontSee('Show all 51'); // now listing every member
});

it('offers an "Edit roles" link when the edited group has a role selected', function () {
    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));
    $moderator = Role::where('slug', 'moderator')->firstOrFail();
    $group = uxGm()->create(['name' => 'VIPs', 'role_id' => $moderator->id]);

    Livewire::test('admin.groups')
        ->call('edit', $group->id)
        ->assertSee(route('admin.groups.roles'));
});

it('PermMode resolves query, then cookie, then defaults to simple', function () {
    app()->instance('request', Request::create('/x', 'GET'));
    expect(PermMode::resolve())->toBe('simple');

    app()->instance('request', Request::create('/x', 'GET', ['mode' => 'advanced']));
    expect(PermMode::resolve())->toBe('advanced');

    $req = Request::create('/x', 'GET');
    $req->cookies->set(PermMode::COOKIE, 'advanced');
    app()->instance('request', $req);
    expect(PermMode::resolve())->toBe('advanced');

    $req2 = Request::create('/x', 'GET', ['mode' => 'simple']);
    $req2->cookies->set(PermMode::COOKIE, 'advanced');
    app()->instance('request', $req2);
    expect(PermMode::resolve())->toBe('simple'); // an explicit query overrides the saved cookie
});

it('persists the chosen editor mode as a cookie on the permissions page', function () {
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));

    $this->actingAs($admin)
        ->get(route('admin.groups.permissions', ['mode' => 'advanced']))
        ->assertOk()
        ->assertCookie(PermMode::COOKIE, 'advanced');
});
