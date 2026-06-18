<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Admin\GroupManager;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

// ── Visibility ────────────────────────────────────────────────────────────────────────────────────────────

it('lists a public open group on the directory page', function () {
    app(GroupManager::class)->create([
        'name' => 'OpenGroupName',
        'membership_model' => 'open',
        'is_public' => true,
    ]);

    $this->get(route('groups.index'))
        ->assertOk()
        ->assertSee('OpenGroupName');
});

it('never shows a hidden (non-public) group on the directory page', function () {
    app(GroupManager::class)->create([
        'name' => 'HiddenGroupName',
        'membership_model' => 'open',
        'is_public' => false,
    ]);

    $this->get(route('groups.index'))
        ->assertOk()
        ->assertDontSee('HiddenGroupName');
});

it('does not leak the roster — only the member count is shown', function () {
    $group = app(GroupManager::class)->create([
        'name' => 'RosterTestGroup',
        'membership_model' => 'open',
        'is_public' => true,
    ]);

    $member = Users::inGroups(['members'], ['email' => 'roster-member@m.test']);
    app(GroupManager::class)->addMembers($group, [(int) $member->getKey()]);

    $this->get(route('groups.index'))
        ->assertOk()
        ->assertDontSee($member->username);
});

// ── Join (open model) ─────────────────────────────────────────────────────────────────────────────────────

it('lets an eligible member join an open group through the join-button SFC', function () {
    $group = app(GroupManager::class)->create([
        'name' => 'JoinTestGroup',
        'membership_model' => 'open',
        'is_public' => true,
    ]);

    $member = Users::inGroups(['members'], ['email' => 'joiner-sfc@m.test']);

    Livewire::actingAs($member)
        ->test('groups.join-button', ['group' => $group])
        ->call('join')
        ->assertHasNoErrors();

    expect($group->users()->whereKey($member->getKey())->exists())->toBeTrue();
});

// ── Gated join (banned user) ──────────────────────────────────────────────────────────────────────────────

it('does not seat a banned user who tries to join an open group', function () {
    $group = app(GroupManager::class)->create([
        'name' => 'GatedGroup',
        'membership_model' => 'open',
        'is_public' => true,
    ]);

    $banned = Users::inGroups(['members'], ['email' => 'banned-user@m.test']);
    $banned->forceFill(['status' => 'banned'])->save();

    // The SFC catches the GroupException from the gate and stores it in $error — the user is NOT added.
    Livewire::actingAs($banned)
        ->test('groups.join-button', ['group' => $group])
        ->call('join');

    expect($group->users()->whereKey($banned->getKey())->exists())->toBeFalse();
});
