<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\AntiSpam;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Per-trust reaction-rate limiting (P2-M1) — the abuse control for reactions, which are otherwise ungated
 * (react.create has no trust NEVER, since reacting is not a durable spam vector). Backed by Laravel's
 * cache RateLimiter, so it is DB-backed on the baseline tier and Redis on enhanced with no code change
 * (tier-graceful, ADR-0011). New (TL0) accounts get the tightest cap. Mirrors PostRateLimiter.
 */
final class ReactionRateLimiter
{
    /** Register a reaction attempt; returns true when the user is WITHIN their per-minute cap. */
    public function attempt(User $user): bool
    {
        $limit = $this->limitFor($user);
        if ($limit <= 0) {
            return true; // limiting disabled
        }

        return RateLimiter::attempt('reaction-rate:'.$user->getKey(), $limit, fn () => true, 60) !== false;
    }

    private function limitFor(User $user): int
    {
        $limits = (array) config('novfora.reactions.rate_limits', []);
        $slugs = $user->groups->pluck('slug')->all(); // one read of the (usually eager-loaded) relation, not a query per level

        foreach (['tl0', 'tl1', 'tl2', 'tl3'] as $slug) {
            if (isset($limits[$slug]) && in_array($slug, $slugs, true)) {
                return (int) $limits[$slug];
            }
        }

        return (int) ($limits['default'] ?? 60);
    }
}
