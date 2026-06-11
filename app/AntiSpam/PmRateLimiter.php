<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\AntiSpam;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Per-trust private-message send-rate limiting (P2-M2 Half-B). The hard gate is pm.send (NEVER at TL0); this is
 * the post-gate abuse control on senders who ARE allowed to PM — it caps the mass-PM rate so a trusted-but-
 * compromised account cannot blast the forum. Backed by Laravel's cache RateLimiter, so it is DB-backed on the
 * baseline tier and Redis on enhanced with no code change (tier-graceful, ADR-0011). Mirrors ReactionRateLimiter.
 */
final class PmRateLimiter
{
    /** Register a message-send attempt; returns true when the user is WITHIN their per-minute cap. */
    public function attempt(User $user): bool
    {
        $limit = $this->limitFor($user);
        if ($limit <= 0) {
            return true; // limiting disabled
        }

        return RateLimiter::attempt('pm-rate:'.$user->getKey(), $limit, fn () => true, 60) !== false;
    }

    private function limitFor(User $user): int
    {
        $limits = (array) config('novfora.pm.rate_limits', []);
        $slugs = $user->groups->pluck('slug')->all(); // one read of the (usually eager-loaded) relation, not a query per level

        foreach (['tl0', 'tl1', 'tl2', 'tl3'] as $slug) {
            if (isset($limits[$slug]) && in_array($slug, $slugs, true)) {
                return (int) $limits[$slug];
            }
        }

        return (int) ($limits['default'] ?? 30);
    }
}
