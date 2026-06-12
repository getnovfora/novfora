<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\AntiSpam\FollowRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

/*
| FollowRateLimiter (P2-M5): the post-gate mass-follow control — per-trust follows/minute via the
| cache-backed RateLimiter (tier-graceful). Mirrors PmRateLimiterTest.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
});

it('caps a TL1 follower at their per-minute limit', function () {
    config(['novfora.follow.rate_limits' => ['tl1' => 3, 'default' => 30]]);
    $user = Users::inGroups(['members', 'tl1']);
    $limiter = new FollowRateLimiter;

    expect($limiter->attempt($user))->toBeTrue()
        ->and($limiter->attempt($user))->toBeTrue()
        ->and($limiter->attempt($user))->toBeTrue()
        ->and($limiter->attempt($user))->toBeFalse(); // 4th exceeds cap of 3
});

it('falls through to the default cap for an untiered user and disables at 0', function () {
    config(['novfora.follow.rate_limits' => ['default' => 1]]);
    $mod = Users::inGroups(['moderators']);
    $limiter = new FollowRateLimiter;

    expect($limiter->attempt($mod))->toBeTrue()
        ->and($limiter->attempt($mod))->toBeFalse();

    config(['novfora.follow.rate_limits' => ['default' => 0]]); // 0 = limiting disabled
    expect($limiter->attempt($mod))->toBeTrue();
});
