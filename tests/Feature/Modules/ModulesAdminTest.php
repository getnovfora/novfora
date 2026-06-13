<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Module;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| The ACP modules page (ADR-0031). Installing a module loads in-process PHP, so it is admins-only (admin.access)
| PLUS staff-2FA, self-guarded in mount() and every action. A ModuleException surfaces as an inline error, not
| a 500.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('blocks a member and a moderator from the modules component (403)', function () {
    $this->actingAs(Users::inGroups(['members']));
    Livewire::test('admin.modules')->assertStatus(403);

    // A moderator holds bans.manage but NOT admin.access — module management is admins-only.
    $this->actingAs(Users::inGroups(['moderators']));
    Livewire::test('admin.modules')->assertStatus(403);
});

it('blocks an admin who has not confirmed staff 2FA (403)', function () {
    $this->actingAs(Users::inGroups(['admins']));
    Livewire::test('admin.modules')->assertStatus(403);
});

it('lets a 2FA admin list, install and enable the example plugin', function () {
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));
    $this->actingAs($admin);

    Livewire::test('admin.modules')
        ->assertStatus(200)
        ->assertSee('novfora/hello')
        ->call('install', 'novfora/hello')
        ->assertHasNoErrors()
        ->assertSet('error', null);
    expect(Module::where('slug', 'novfora/hello')->firstOrFail()->enabled)->toBeFalse();

    // First enable shows the full-trust consent step (H3); confirming it enables.
    Livewire::test('admin.modules')
        ->call('enable', 'novfora/hello')
        ->assertSet('pendingConsent', 'novfora/hello')
        ->call('confirmEnable')
        ->assertSet('pendingConsent', null);
    expect(Module::where('slug', 'novfora/hello')->firstOrFail()->enabled)->toBeTrue();
});

it('surfaces a ModuleException as an inline error, not a 500', function () {
    config(['novfora.modules.path' => base_path('tests/Fixtures/modules')]);
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));
    $this->actingAs($admin);

    Livewire::test('admin.modules')
        ->call('install', 'test/incompatible')
        ->assertStatus(200)
        ->assertSee('incompatible with core');
    expect(Module::where('slug', 'test/incompatible')->exists())->toBeFalse();
});
