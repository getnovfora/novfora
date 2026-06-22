<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\User;
use App\Models\WarningType;
use App\Permissions\PermissionValue as V;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Acl;
use Tests\Support\Users;

/*
| ACP v4 · A3 (ADR-0096) — the ⚡warning-types CRUD surface. Surfaces the existing warning_types engine (no
| engine change); gated by admin.access + bans.manage + staff-2FA. Plus the read-only consequence thresholds.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function wtAdmin(): User
{
    return Users::withTwoFactor(Users::inGroups(['admins'])); // full admin → bans.manage
}

it('forbids guests + non-admins from the warning-types route', function () {
    $this->get(route('admin.moderation.warning-types'))->assertRedirect();
    $this->actingAs(Users::inGroups(['members']))->get(route('admin.moderation.warning-types'))->assertForbidden();
});

it('403s the component for an admin without bans.manage', function () {
    $acl = Acl::make();
    $grp = $acl->group('nobans', ['priority' => 40]);
    $acl->grant($grp, 'admin.access', $acl->global, V::Allow); // admin.access but NOT bans.manage
    Livewire::actingAs($acl->user(['nobans']))->test('admin.warning-types')->assertForbidden();
});

it('loads + shows the consequence thresholds for a 2FA admin', function () {
    $this->actingAs(wtAdmin())->get(route('admin.moderation.warning-types'))->assertOk()->assertSee('Consequence thresholds');
});

it('creates a warning type with an auto slug', function () {
    Livewire::actingAs(wtAdmin())->test('admin.warning-types')
        ->call('create')
        ->set('label', 'Trolling')
        ->set('points', 8)
        ->set('decayDays', 45)
        ->set('action', 'moderate')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Trolling');

    $t = WarningType::where('label', 'Trolling')->firstOrFail();
    expect($t->slug)->toBe('trolling')
        ->and((int) $t->default_points)->toBe(8)
        ->and((int) $t->decay_days)->toBe(45)
        ->and($t->default_action['action'])->toBe('moderate');
});

it('stores temp-ban days only for the temp_ban action', function () {
    Livewire::actingAs(wtAdmin())->test('admin.warning-types')
        ->call('create')->set('label', 'Cooldown')->set('points', 15)->set('action', 'temp_ban')->set('actionDays', 14)
        ->call('save')->assertHasNoErrors();

    $t = WarningType::where('label', 'Cooldown')->firstOrFail();
    expect($t->default_action['action'])->toBe('temp_ban')->and((int) $t->default_action['days'])->toBe(14);
});

it('edits a warning type', function () {
    $t = WarningType::create(['slug' => 'oldslug', 'label' => 'Old', 'default_points' => 1, 'is_active' => true]);
    Livewire::actingAs(wtAdmin())->test('admin.warning-types')
        ->call('edit', $t->id)->set('label', 'Renamed')->set('points', 12)->call('save')->assertHasNoErrors();
    expect($t->fresh()->label)->toBe('Renamed')->and((int) $t->fresh()->default_points)->toBe(12);
});

it('toggles active + deletes a warning type', function () {
    $t = WarningType::create(['slug' => 'tmp', 'label' => 'Temp', 'default_points' => 1, 'is_active' => true]);
    Livewire::actingAs(wtAdmin())->test('admin.warning-types')->call('toggleActive', $t->id);
    expect($t->fresh()->is_active)->toBeFalse();
    Livewire::actingAs(wtAdmin())->test('admin.warning-types')->call('delete', $t->id);
    expect(WarningType::find($t->id))->toBeNull();
});

it('generates a unique slug when the base is taken', function () {
    WarningType::create(['slug' => 'collide', 'label' => 'Collide', 'default_points' => 1, 'is_active' => true]);
    Livewire::actingAs(wtAdmin())->test('admin.warning-types')
        ->call('create')->set('label', 'Collide')->set('points', 5)->call('save')->assertHasNoErrors();
    expect(WarningType::pluck('slug')->contains('collide-2'))->toBeTrue();
});
