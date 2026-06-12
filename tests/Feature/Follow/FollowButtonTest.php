<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Community\FollowService;
use App\Models\UserRelationship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| The profile ⚡follow-button (P2-M5): follow/unfollow wiring, the TL0 soft gate + rate limit enforced at
| the action, self/guest refusals, and the own-profile/incapable-viewer render rules.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
});

it('follows and unfollows from the profile button', function () {
    $viewer = Users::inGroups(['members', 'tl1']);
    $target = Users::inGroups(['members', 'tl1']);
    $this->actingAs($viewer);

    Livewire::test('community.follow-button', ['userId' => $target->id])
        ->assertSet('following', false)
        ->call('toggle')
        ->assertSet('following', true)
        ->assertSet('followers', 1)
        ->call('toggle')
        ->assertSet('following', false)
        ->assertSet('followers', 0);

    expect(UserRelationship::where('type', UserRelationship::TYPE_FOLLOW)->count())->toBe(0);
});

it('blocks a TL0 member at the action (soft gate) and hides their button', function () {
    $viewer = Users::inGroups(['members', 'tl0']);
    $target = Users::inGroups(['members', 'tl1']);
    $this->actingAs($viewer);

    Livewire::test('community.follow-button', ['userId' => $target->id])
        ->assertSet('canCreate', false)
        ->assertDontSeeHtml('dusk="follow-button"')
        ->call('toggle')
        ->assertForbidden();
});

it('forbids a guest and a self-follow at the action', function () {
    $target = Users::inGroups(['members', 'tl1']);

    Livewire::test('community.follow-button', ['userId' => $target->id])
        ->call('toggle')
        ->assertForbidden();

    $this->actingAs($target);
    Livewire::test('community.follow-button', ['userId' => $target->id])
        ->assertDontSeeHtml('dusk="follow-button"') // own profile renders counts, never the button
        ->call('toggle')
        ->assertForbidden();
});

it('lets a demoted TL0 user unfollow but not re-follow (follow.delete is ungated)', function () {
    $viewer = Users::inGroups(['members', 'tl0']);
    $target = Users::inGroups(['members', 'tl1']);
    // The edge predates the demotion (created while the viewer was trusted).
    UserRelationship::factory()->follow()->create(['user_id' => $viewer->id, 'related_user_id' => $target->id]);
    $this->actingAs($viewer);

    Livewire::test('community.follow-button', ['userId' => $target->id])
        ->assertSet('following', true)
        ->assertSeeHtml('dusk="follow-button"') // Unfollow renders via follow.delete
        ->call('toggle')
        ->assertSet('following', false);

    // …but with the edge gone, follow.create (TL0-soft-gated) blocks a re-follow.
    Livewire::test('community.follow-button', ['userId' => $target->id])
        ->call('toggle')
        ->assertForbidden();
});

it('rate-limits rapid follows at the action', function () {
    config(['novfora.follow.rate_limits' => ['tl1' => 1, 'default' => 30]]);
    $viewer = Users::inGroups(['members', 'tl1']);
    $first = Users::inGroups(['members', 'tl1']);
    $second = Users::inGroups(['members', 'tl1']);
    $this->actingAs($viewer);

    Livewire::test('community.follow-button', ['userId' => $first->id])
        ->call('toggle')
        ->assertSet('following', true);

    Livewire::test('community.follow-button', ['userId' => $second->id])
        ->call('toggle')
        ->assertHasErrors('follow')   // over the cap → friendly error, no edge
        ->assertSet('following', false);

    expect(app(FollowService::class)->follows($viewer, $second))->toBeFalse();
});

it('shows follower and following counts to everyone on the profile page', function () {
    $target = Users::inGroups(['members', 'tl1']);
    $fan = Users::inGroups(['members', 'tl1']);
    UserRelationship::factory()->follow()->create(['user_id' => $fan->id, 'related_user_id' => $target->id]);

    $this->get(route('profiles.show', $target))
        ->assertOk()
        ->assertSeeHtml('dusk="follower-count"')
        ->assertSee('follower');
});
