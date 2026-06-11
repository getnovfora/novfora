<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Upgrade\UpgradeResult;
use App\Upgrade\UpgradeRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

uses(RefreshDatabase::class);

/*
| Admin → System → Upgrade (RH-10 / ADR-0021 §5): the operator surface for manual mode. Authorization is
| enforced both on the route group (admin.access + 2FA) and IN the Livewire component (Livewire actions
| bypass route middleware), and the "Apply pending migrations" action runs the same UpgradeRunner pipeline.
*/

beforeEach(fn () => $this->seed());

it('lets a 2FA admin view the upgrade panel; blocks guests and non-admins', function () {
    // Guest → bounced to login by the route group.
    $this->get(route('admin.system.upgrade'))->assertRedirect();

    // Authenticated non-admin → 403 (EnsureSystemPanelAccess).
    $member = Users::inGroups(['members', 'tl4']);
    $this->actingAs($member)->get(route('admin.system.upgrade'))->assertForbidden();

    // 2FA-confirmed admin → 200.
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));
    $this->actingAs($admin)->get(route('admin.system.upgrade'))
        ->assertOk()
        ->assertSee('Upgrade');
});

it('enforces admin access inside the component, not only on the route', function () {
    $this->actingAs(Users::inGroups(['members', 'tl0']));

    // Livewire captures the mount-time abort_unless as a 403 response (actions reach the component via the
    // livewire/update endpoint, which does not carry the admin route middleware — so the component re-checks).
    Livewire::test('admin.upgrade')->assertStatus(403);
});

it('applies pending migrations from the panel and reports the result', function () {
    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));

    $this->mock(UpgradeRunner::class)
        ->shouldReceive('runManual')
        ->once()
        ->andReturn(UpgradeResult::success(3, 120, 'novfora-20260607-120000.zip'));

    Livewire::test('admin.upgrade')
        ->call('apply')
        ->assertSet('messageVariant', 'success')
        ->assertSee('Applied 3 migration(s)');
});

it('surfaces a failed apply with the recovery hint', function () {
    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));

    $this->mock(UpgradeRunner::class)
        ->shouldReceive('runManual')
        ->once()
        ->andReturn(UpgradeResult::failed('migrate', 'boom', 'novfora-20260607-120000.zip'));

    Livewire::test('admin.upgrade')
        ->call('apply')
        ->assertSet('messageVariant', 'danger')
        ->assertSee('held in maintenance');
});
