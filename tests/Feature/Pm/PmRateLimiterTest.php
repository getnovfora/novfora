<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\AntiSpam\PmRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

/*
| The post-gate abuse control: a per-trust messages-per-minute cap (cache RateLimiter, tier-graceful). Mirrors
| ReactionRateLimiter — a non-positive limit disables it; otherwise the (N+1)th attempt within the window fails.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('caps a TL1 sender at their per-minute limit', function () {
    config(['novfora.pm.rate_limits' => ['tl1' => 3, 'default' => 30]]);
    $user = Users::inGroups(['members', 'tl1']);
    $limiter = new PmRateLimiter;

    expect($limiter->attempt($user))->toBeTrue()
        ->and($limiter->attempt($user))->toBeTrue()
        ->and($limiter->attempt($user))->toBeTrue()
        ->and($limiter->attempt($user))->toBeFalse(); // the 4th exceeds the cap of 3
});

it('treats a non-positive limit as disabled (always within cap)', function () {
    config(['novfora.pm.rate_limits' => ['tl1' => 0, 'default' => 30]]);
    $user = Users::inGroups(['members', 'tl1']);
    $limiter = new PmRateLimiter;

    foreach (range(1, 5) as $ignored) {
        expect($limiter->attempt($user))->toBeTrue();
    }
});
