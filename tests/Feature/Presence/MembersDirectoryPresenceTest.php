<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\User;
use App\Presence\OnlineMembers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| BUG-010: presence must be consistent across surfaces. The "Who's Online" header counts only members who
| opted in (OnlineMembers::baseQuery requires show_online_status=true), but the directory card badge gated
| only on isOnline() (last_active_at) — so an opted-out member who was recently active leaked a green
| "Online" badge while being correctly absent from the count. Both surfaces must now honour the opt-in.
| Scope each assertion to one card via the directory search so seeded members never pollute it.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

/** The admin viewer (sees the directory) — itself offline + opted-out so it never adds a badge of its own. */
function presenceAdmin(): User
{
    $admin = Users::inGroups(['admins']);
    $admin->forceFill(['show_online_status' => false, 'last_active_at' => null])->save();

    return $admin;
}

it('an opted-out but recently active member shows no badge and is not counted (BUG-010)', function () {
    $admin = presenceAdmin();
    $service = app(OnlineMembers::class);
    $baseline = $service->count();

    $hidden = User::factory()->create([
        'username' => 'hiddenhank',
        'status' => 'active',
        'show_online_status' => false,
        'last_active_at' => now(),
    ]);

    Livewire::actingAs($admin)->test('members-directory')
        ->set('search', 'hiddenhank')
        ->assertSee('hiddenhank')   // listed in the directory…
        ->assertDontSee('Online');  // …but flashes no presence badge

    expect($service->count())->toBe($baseline)                         // not added to the header count
        ->and($service->recent()->pluck('id'))->not->toContain($hidden->id);
});

it('an opted-in recently active member shows the badge and is counted — the surfaces agree (BUG-010)', function () {
    $admin = presenceAdmin();
    $service = app(OnlineMembers::class);
    $baseline = $service->count();

    $visible = User::factory()->create([
        'username' => 'onlineolga',
        'status' => 'active',
        'show_online_status' => true,
        'last_active_at' => now(),
    ]);

    Livewire::actingAs($admin)->test('members-directory')
        ->set('search', 'onlineolga')
        ->assertSee('onlineolga')
        ->assertSee('Online');

    expect($service->count())->toBe($baseline + 1)
        ->and($service->recent()->pluck('id'))->toContain($visible->id);
});
