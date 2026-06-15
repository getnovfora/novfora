<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Clubs\ClubService;
use App\Models\Club;
use App\Models\ClubMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

// ── Creation policy gate (M1.1 baseline: verified member at TL ≥ 2; M1.6 makes it configurable) ──────────

it('lets a trust-level-2 member reach the create form', function () {
    $user = Users::inGroups(['members', 'tl2'], ['email' => 'tl2@clubs.test']);

    $this->actingAs($user)->get(route('clubs.create'))->assertOk();
});

it('forbids a trust-level-1 member from creating a club', function () {
    $user = Users::inGroups(['members', 'tl1'], ['email' => 'tl1@clubs.test']);

    $this->actingAs($user)->get(route('clubs.create'))->assertForbidden();
});

it('redirects a guest away from the create form', function () {
    $this->get(route('clubs.create'))->assertRedirect();
});

it('always lets staff create clubs regardless of trust level', function () {
    $admin = Users::inGroups(['admins'], ['email' => 'admin@clubs.test']);

    $this->actingAs($admin)->get(route('clubs.create'))->assertOk();
});

// ── Create flow (Livewire SFC) ───────────────────────────────────────────────────────────────────────────

it('creates a club and seats the founder as owner', function () {
    $user = Users::inGroups(['members', 'tl2'], ['email' => 'founder@clubs.test']);

    Livewire::actingAs($user)
        ->test('clubs.create')
        ->set('name', 'Astronomy Club')
        ->set('tagline', 'Stargazers welcome')
        ->set('privacy', 'public')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $club = Club::where('name', 'Astronomy Club')->firstOrFail();
    expect($club->slug)->toBe('astronomy-club');
    expect((int) $club->member_count)->toBe(1);
    expect($club->roleOf($user))->toBe('owner');
    expect($club->isActiveMember($user))->toBeTrue();
});

it('rejects creation through the SFC for an under-trust user', function () {
    $user = Users::inGroups(['members', 'tl1'], ['email' => 'low@clubs.test']);

    Livewire::actingAs($user)
        ->test('clubs.create')
        ->assertStatus(403);
});

it('generates distinct slugs for clubs with the same name', function () {
    $owner = Users::inGroups(['members', 'tl3'], ['email' => 'dup@clubs.test']);
    $service = app(ClubService::class);

    $a = $service->create($owner, ['name' => 'Chess', 'privacy' => 'public']);
    $b = $service->create($owner, ['name' => 'Chess', 'privacy' => 'public']);

    expect($a->slug)->toBe('chess');
    expect($b->slug)->toBe('chess-2');
});

it('forces a public club to be listed', function () {
    $owner = Users::inGroups(['members', 'tl2'], ['email' => 'pub@clubs.test']);

    $club = app(ClubService::class)->create($owner, ['name' => 'Open Door', 'privacy' => 'public', 'is_listed' => false]);

    expect($club->is_listed)->toBeTrue();
});

// ── Directory + listing visibility (the privacy fence, M1.1 slice) ───────────────────────────────────────

it('shows public and listed clubs in the directory to a guest', function () {
    Club::factory()->public()->create(['name' => 'Public Club']);
    Club::factory()->closed()->create(['name' => 'Closed Listed Club']);
    Club::factory()->hidden()->create(['name' => 'Hidden Club']);

    $this->get(route('clubs.index'))
        ->assertOk()
        ->assertSee('Public Club')
        ->assertSee('Closed Listed Club')
        ->assertDontSee('Hidden Club');
});

it('404s a hidden club home for a non-member (no disclosure)', function () {
    $club = Club::factory()->hidden()->create(['name' => 'Secret Society']);

    // Guest
    $this->get(route('clubs.show', $club))->assertNotFound();

    // Logged-in non-member
    $outsider = Users::inGroups(['members', 'tl1'], ['email' => 'outsider@clubs.test']);
    $this->actingAs($outsider)->get(route('clubs.show', $club))->assertNotFound();
});

it('shows a hidden club home to its active member and to staff', function () {
    $club = Club::factory()->hidden()->create(['name' => 'Inner Circle']);
    $member = Users::inGroups(['members', 'tl1'], ['email' => 'member@clubs.test']);
    ClubMembership::create(['club_id' => $club->id, 'user_id' => $member->id, 'role' => 'member', 'status' => 'active', 'joined_at' => now()]);

    $this->actingAs($member)->get(route('clubs.show', $club))->assertOk()->assertSee('Inner Circle');

    $admin = Users::inGroups(['admins'], ['email' => 'staff@clubs.test']);
    $this->actingAs($admin)->get(route('clubs.show', $club))->assertOk();
});

it('lists a hidden club in the directory only for its members and staff', function () {
    $club = Club::factory()->hidden()->create(['name' => 'Cloaked']);
    $member = Users::inGroups(['members', 'tl1'], ['email' => 'cloak-member@clubs.test']);
    ClubMembership::create(['club_id' => $club->id, 'user_id' => $member->id, 'role' => 'member', 'status' => 'active', 'joined_at' => now()]);
    $outsider = Users::inGroups(['members', 'tl1'], ['email' => 'cloak-out@clubs.test']);

    $this->actingAs($member)->get(route('clubs.index'))->assertOk()->assertSee('Cloaked');
    $this->actingAs($outsider)->get(route('clubs.index'))->assertOk()->assertDontSee('Cloaked');
});

// ── Manage / edit / delete ───────────────────────────────────────────────────────────────────────────────

it('lets the owner edit the club', function () {
    $owner = Users::inGroups(['members', 'tl2'], ['email' => 'owner-edit@clubs.test']);
    $club = app(ClubService::class)->create($owner, ['name' => 'Edit Me', 'privacy' => 'public']);

    Livewire::actingAs($owner)
        ->test('clubs.edit', ['club' => $club])
        ->set('name', 'Edited Name')
        ->set('tagline', 'New tagline')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect($club->fresh()->name)->toBe('Edited Name');
});

it('forbids a non-owner non-staff member from managing the club', function () {
    $owner = Users::inGroups(['members', 'tl2'], ['email' => 'real-owner@clubs.test']);
    $club = app(ClubService::class)->create($owner, ['name' => 'Not Yours', 'privacy' => 'public']);
    $stranger = Users::inGroups(['members', 'tl2'], ['email' => 'stranger@clubs.test']);

    $this->actingAs($stranger)->get(route('clubs.edit', $club))->assertForbidden();
    Livewire::actingAs($stranger)->test('clubs.edit', ['club' => $club])->assertStatus(403);
});

it('lets staff manage any club', function () {
    $owner = Users::inGroups(['members', 'tl2'], ['email' => 'owner-staff@clubs.test']);
    $club = app(ClubService::class)->create($owner, ['name' => 'Staff Manageable', 'privacy' => 'public']);
    $admin = Users::inGroups(['admins'], ['email' => 'admin-manage@clubs.test']);

    $this->actingAs($admin)->get(route('clubs.edit', $club))->assertOk();
});

it('soft-deletes a club when the owner confirms', function () {
    $owner = Users::inGroups(['members', 'tl2'], ['email' => 'owner-del@clubs.test']);
    $club = app(ClubService::class)->create($owner, ['name' => 'Delete Me', 'privacy' => 'public']);

    Livewire::actingAs($owner)
        ->test('clubs.edit', ['club' => $club])
        ->call('deleteClub')  // arms
        ->call('deleteClub')  // confirms
        ->assertRedirect();

    expect(Club::withTrashed()->find($club->id)->trashed())->toBeTrue();
});
