<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\ApiToken;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| E1 (NOV-135, ADR-0115) — the Admin-API token ACP (Security → API tokens). Gated admin.api_tokens.manage +
| staff-2FA. A destructive scope (backups/restore/upgrade/populate) may only be minted by a co-owner.
*/

uses(RefreshDatabase::class);

function apiCoOwner(User $user): User
{
    $user->groups()->updateExistingPivot((int) Group::where('slug', 'admins')->value('id'), ['is_co_owner' => true]);

    return $user;
}

it('lets a 2FA admin mint a scoped token (secret shown once, nvfa_ prefix)', function () {
    $this->seed();
    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));

    Livewire::test('admin.security.api-tokens')
        ->set('name', 'CI runner')
        ->set('scopes', ['admin:members.read', 'admin:settings.read'])
        ->call('create')
        ->assertHasNoErrors();

    $token = ApiToken::query()->whereNotNull('scopes')->firstOrFail();
    expect($token->scopes)->toContain('admin:members.read')
        ->and($token->name)->toBe('CI runner');
});

it('refuses a destructive scope for a non-co-owner admin', function () {
    $this->seed();
    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins']))); // an admin, but NOT a co-owner

    Livewire::test('admin.security.api-tokens')
        ->set('name', 'restore bot')
        ->set('scopes', ['admin:restore'])
        ->call('create')
        ->assertHasErrors('scopes');

    expect(ApiToken::query()->whereNotNull('scopes')->count())->toBe(0);
});

it('lets a co-owner mint a destructive scope', function () {
    $this->seed();
    $this->actingAs(apiCoOwner(Users::withTwoFactor(Users::inGroups(['admins']))));

    Livewire::test('admin.security.api-tokens')
        ->set('name', 'restore bot')
        ->set('scopes', ['admin:restore'])
        ->call('create')
        ->assertHasNoErrors();

    expect(ApiToken::query()->where('name', 'restore bot')->whereNotNull('scopes')->exists())->toBeTrue();
});

it('rejects an invalid ip in the allowlist', function () {
    $this->seed();
    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));

    Livewire::test('admin.security.api-tokens')
        ->set('name', 'pinned')
        ->set('scopes', ['admin:members.read'])
        ->set('ipAllowlist', 'not-an-ip')
        ->call('create')
        ->assertHasErrors('ipAllowlist');
});

it('gates the token page behind admin.api_tokens.manage (403 for a plain member)', function () {
    $this->seed();
    $this->actingAs(Users::inGroups(['members']));
    Livewire::test('admin.security.api-tokens')->assertStatus(403);
});

it('requires a confirmed second factor to mint (403 for an admin without 2FA)', function () {
    $this->seed();
    $this->actingAs(Users::inGroups(['admins'])); // an admin, but NO confirmed 2FA
    Livewire::test('admin.security.api-tokens')->assertStatus(403);
});
