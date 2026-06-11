<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Ban;
use App\Models\Warning;
use App\Models\WarningType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

/*
| Phase-1.5 F-F: actor-vs-target rank check. A staff member (bans.manage) cannot ban/warn/spam-clean a
| target of equal-or-higher rank — a moderator can't action an admin, nor (by default) another moderator.
| Admins outrank everyone. Layered ON TOP of the bans.manage permission, not a replacement.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('forbids a moderator from banning an admin', function () {
    $mod = Users::inGroups(['moderators']);
    $admin = Users::inGroups(['admins']);

    $this->actingAs($mod)->post(route('bans.store'), ['type' => 'user', 'user_id' => $admin->id])->assertForbidden();
    expect(Ban::where('user_id', $admin->id)->exists())->toBeFalse();
});

it('forbids a moderator from banning another moderator (no equal-rank action by default)', function () {
    $mod = Users::inGroups(['moderators']);
    $peer = Users::inGroups(['moderators']);

    $this->actingAs($mod)->post(route('bans.store'), ['type' => 'user', 'user_id' => $peer->id])->assertForbidden();
    expect(Ban::where('user_id', $peer->id)->exists())->toBeFalse();
});

it('lets a moderator ban a member they outrank', function () {
    $mod = Users::inGroups(['moderators']);
    $member = Users::inGroups(['members']);

    $this->actingAs($mod)->post(route('bans.store'), ['type' => 'user', 'user_id' => $member->id])->assertRedirect();
    expect(Ban::where('user_id', $member->id)->exists())->toBeTrue();
});

it('lets an admin ban a moderator (admins outrank everyone)', function () {
    $admin = Users::inGroups(['admins']);
    $mod = Users::inGroups(['moderators']);

    $this->actingAs($admin)->post(route('bans.store'), ['type' => 'user', 'user_id' => $mod->id])->assertRedirect();
    expect(Ban::where('user_id', $mod->id)->exists())->toBeTrue();
});

it('forbids a moderator from warning an admin', function () {
    $mod = Users::inGroups(['moderators']);
    $admin = Users::inGroups(['admins']);

    $this->actingAs($mod)
        ->post(route('warnings.store', $admin), ['warning_type_id' => WarningType::firstOrFail()->id])
        ->assertForbidden();

    expect(Warning::where('user_id', $admin->id)->exists())->toBeFalse();
});

it('forbids a moderator from spam-cleaning an admin', function () {
    $mod = Users::inGroups(['moderators']);
    $admin = Users::inGroups(['admins']);

    $this->actingAs($mod)->post(route('moderation.spam-clean', $admin))->assertForbidden();
    expect($admin->fresh()->status)->not->toBe('banned');
});

it('respects allow_equal so a moderator may then action a peer', function () {
    config(['novfora.moderation.rank.allow_equal' => true]);
    $mod = Users::inGroups(['moderators']);
    $peer = Users::inGroups(['moderators']);

    $this->actingAs($mod)->post(route('bans.store'), ['type' => 'user', 'user_id' => $peer->id])->assertRedirect();
    expect(Ban::where('user_id', $peer->id)->exists())->toBeTrue();
});
