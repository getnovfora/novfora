<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Community\FollowService;
use App\Events\Followed;
use App\Models\UserRelationship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\Users;

/*
| FollowService (P2-M5 ⚙): idempotent edge creation on the DB UNIQUE, the self-follow hard refuse,
| unfollow, and the count/id helpers the profile + following-feed consume.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
});

it('creates one follow edge and fires Followed exactly once — a repeat follow is a no-op', function () {
    Event::fake([Followed::class]);

    $follower = Users::inGroups(['members', 'tl1']);
    $followee = Users::inGroups(['members', 'tl1']);
    $service = app(FollowService::class);

    expect($service->follow($follower, $followee))->toBeTrue()
        ->and($service->follow($follower, $followee))->toBeFalse(); // idempotent: UNIQUE-keyed insertOrIgnore

    expect(UserRelationship::where('user_id', $follower->id)
        ->where('related_user_id', $followee->id)
        ->where('type', UserRelationship::TYPE_FOLLOW)
        ->count())->toBe(1);

    Event::assertDispatchedTimes(Followed::class, 1); // the no-op repeat never re-fires the notification
});

it('refuses self-follow as a hard invariant, independent of any ACL state', function () {
    $user = Users::inGroups(['admins']); // even the top group cannot self-follow

    expect(fn () => app(FollowService::class)->follow($user, $user))
        ->toThrow(InvalidArgumentException::class);

    expect(UserRelationship::where('type', UserRelationship::TYPE_FOLLOW)->count())->toBe(0);
});

it('unfollows an existing edge and reports a missing one as a no-op', function () {
    Event::fake([Followed::class]);
    $follower = Users::inGroups(['members', 'tl1']);
    $followee = Users::inGroups(['members', 'tl1']);
    $service = app(FollowService::class);

    $service->follow($follower, $followee);

    expect($service->unfollow($follower, $followee))->toBeTrue()
        ->and($service->unfollow($follower, $followee))->toBeFalse()
        ->and(UserRelationship::where('type', UserRelationship::TYPE_FOLLOW)->count())->toBe(0);
});

it('keeps the follow and ignore halves of user_relationships independent', function () {
    Event::fake([Followed::class]);
    $a = Users::inGroups(['members', 'tl1']);
    $b = Users::inGroups(['members', 'tl1']);
    UserRelationship::factory()->ignore()->create(['user_id' => $a->id, 'related_user_id' => $b->id]);

    $service = app(FollowService::class);
    expect($service->follow($a, $b))->toBeTrue(); // the ignore edge does not occupy the follow slot
    expect($service->unfollow($a, $b))->toBeTrue();

    // The ignore edge survives follow/unfollow untouched.
    expect(UserRelationship::where('user_id', $a->id)->where('type', UserRelationship::TYPE_IGNORE)->count())->toBe(1);
});

it('reports follower/following counts and the followed-id set', function () {
    Event::fake([Followed::class]);
    $a = Users::inGroups(['members', 'tl1']);
    $b = Users::inGroups(['members', 'tl1']);
    $c = Users::inGroups(['members', 'tl1']);
    $service = app(FollowService::class);

    $service->follow($a, $b);
    $service->follow($a, $c);
    $service->follow($c, $b);

    expect($service->followingCount($a))->toBe(2)
        ->and($service->followerCount($b))->toBe(2)
        ->and($service->followerCount($a))->toBe(0)
        ->and($service->follows($a, $b))->toBeTrue()
        ->and($service->follows($b, $a))->toBeFalse();

    $ids = $service->followingIds($a);
    sort($ids);
    expect($ids)->toBe(collect([$b->id, $c->id])->map(fn ($i) => (int) $i)->sort()->values()->all());
});
