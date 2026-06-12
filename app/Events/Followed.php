<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A user started following another (P2-M5). Dispatched by FollowService ONLY when a new edge was actually
 * inserted (the UNIQUE-keyed insertOrIgnore is the idempotency guard), so a double-submit never double-fires.
 */
final class Followed
{
    use Dispatchable;

    public function __construct(
        public readonly User $follower,
        public readonly User $followee,
    ) {}
}
