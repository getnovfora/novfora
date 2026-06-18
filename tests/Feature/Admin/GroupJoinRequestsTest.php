<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Admin\GroupManager;
use App\Groups\GroupMembershipService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

it('blocks a non-admin from mounting the component', function () {
    $this->actingAs(Users::inGroups(['members']));
    Livewire::test('admin.group-requests')->assertStatus(403);
});

it('approves a pending join request and seats the user', function () {
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));
    $this->actingAs($admin);

    $group = app(GroupManager::class)->create(['name' => 'Beta', 'membership_model' => 'request']);
    $member = Users::inGroups(['members']);
    $request = app(GroupMembershipService::class)->requestToJoin($group, $member);

    Livewire::test('admin.group-requests')
        ->call('approve', $request->id)
        ->assertHasNoErrors();

    expect($group->fresh()->users()->whereKey($member->id)->exists())->toBeTrue()
        ->and($request->fresh()->status)->toBe('approved');
});

it('denies a pending join request without adding membership', function () {
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));
    $this->actingAs($admin);

    $group = app(GroupManager::class)->create(['name' => 'Beta', 'membership_model' => 'request']);
    $member = Users::inGroups(['members']);
    $request = app(GroupMembershipService::class)->requestToJoin($group, $member);

    Livewire::test('admin.group-requests')
        ->call('deny', $request->id)
        ->assertHasNoErrors();

    expect($request->fresh()->status)->toBe('denied')
        ->and($group->fresh()->users()->whereKey($member->id)->exists())->toBeFalse();
});
