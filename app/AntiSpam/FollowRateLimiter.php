<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\AntiSpam;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Per-trust follow-rate limiting (P2-M5) — the post-gate abuse control for follows. Each follow notifies
 * the followee, so mass-follow is a NOTIFICATION-spam vector: follow.create is already soft-gated at TL0
 * (deny-by-default, admin-liftable — config/novfora.php trust_gates), and this limiter bounds the blast
 * radius once a user may follow at all. Backed by Laravel's cache RateLimiter, so it is DB-backed on the
 * baseline tier and Redis on enhanced with no code change (tier-graceful, ADR-0011). Mirrors
 * ReactionRateLimiter / PmRateLimiter.
 */
final class FollowRateLimiter
{
    /** Register a follow attempt; returns true when the user is WITHIN their per-minute cap. */
    public function attempt(User $user): bool
    {
        $limit = $this->limitFor($user);
        if ($limit <= 0) {
            return true; // limiting disabled
        }

        return RateLimiter::attempt('follow-rate:'.$user->getKey(), $limit, fn () => true, 60) !== false;
    }

    private function limitFor(User $user): int
    {
        $limits = (array) config('novfora.follow.rate_limits', []);
        $slugs = $user->groups->pluck('slug')->all(); // one read of the (usually eager-loaded) relation, not a query per level

        foreach (['tl0', 'tl1', 'tl2', 'tl3'] as $slug) {
            if (isset($limits[$slug]) && in_array($slug, $slugs, true)) {
                return (int) $limits[$slug];
            }
        }

        return (int) ($limits['default'] ?? 30);
    }
}
