<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\AntiSpam;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Per-trust post-rate limiting (ADR-0007 §2.4). Backed by Laravel's RateLimiter over the cache store, so it
 * is DB-backed on the baseline tier and Redis on enhanced with no code change (tier-graceful, ADR-0011).
 * New (TL0) accounts get the tightest cap; trusted accounts get more.
 */
final class PostRateLimiter
{
    /** Register a posting attempt; returns true if the user is WITHIN their per-minute cap. */
    public function attempt(User $user): bool
    {
        $limit = $this->limitFor($user);
        if ($limit <= 0) {
            return true; // limiting disabled
        }

        // attempt() runs the callback (→ true) when under the cap, else returns false. 60s window.
        return RateLimiter::attempt('post-rate:'.$user->getKey(), $limit, fn () => true, 60) !== false;
    }

    private function limitFor(User $user): int
    {
        $limits = (array) config('hearth.antispam.rate_limits', []);

        foreach (['tl0', 'tl1', 'tl2', 'tl3'] as $slug) {
            if (isset($limits[$slug]) && $user->groups()->where('slug', $slug)->exists()) {
                return (int) $limits[$slug];
            }
        }

        return (int) ($limits['default'] ?? 20);
    }
}
