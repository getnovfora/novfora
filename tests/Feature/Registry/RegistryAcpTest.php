<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| Registry v1 ACP surface (NOV-125) — the Browse page is admin+staff-2FA gated in mount() AND every action.
*/

uses(RefreshDatabase::class);

it('blocks a non-admin from the registry SFC (403)', function () {
    $this->seed();
    $this->actingAs(Users::inGroups(['members']));
    Livewire::test('admin.registry')->assertStatus(403);
});

it('renders for a 2FA admin and shows the not-configured notice when no root key is pinned', function () {
    $this->seed();
    config()->set('novfora.registry.root_public_key', '');
    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));

    Livewire::test('admin.registry')
        ->assertStatus(200)
        ->assertSee('not configured');
});
