<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Membership;

use App\Models\MembershipTier;
use App\Models\MemberSubscription;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;

/**
 * The grant/revoke lifecycle for membership subscriptions (Phase 4 · M5.1). It is the ONLY thing that flips a
 * subscription's status, and every transition re-projects the member's perks through {@see TierProjector}, so
 * capabilities and the roster can never drift. It does NOT take money — a PaymentProvider (M5.2 manual / M5.3
 * Stripe) calls {@see activate()} once payment is settled (or an admin does, for the manual path).
 */
final class MembershipService
{
    public function __construct(private readonly TierProjector $projector) {}

    /**
     * Activate (or re-activate) a member on a tier and grant its perks. Idempotent-ish: it creates a fresh
     * active row; callers dedupe upstream. `expiresAt` null = no expiry (e.g. a one-time/lifetime grant).
     */
    public function activate(User $user, MembershipTier $tier, string $provider = 'manual', ?string $ref = null, ?Carbon $expiresAt = null): MemberSubscription
    {
        $subscription = MemberSubscription::create([
            'user_id' => $user->getKey(),
            'tier_id' => $tier->getKey(),
            'status' => 'active',
            'provider' => $provider,
            'provider_ref' => $ref,
            'started_at' => now(),
            'expires_at' => $expiresAt,
        ]);

        $this->projector->syncUser($user);
        Audit::log('membership.activated', $subscription, ['tier' => $tier->slug, 'provider' => $provider]);

        return $subscription;
    }

    /** Cancel a subscription now and revoke its perks (re-derived from any remaining active rows). */
    public function cancel(MemberSubscription $subscription): void
    {
        $subscription->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();

        $user = $subscription->user;
        if ($user instanceof User) {
            $this->projector->syncUser($user);
        }
        Audit::log('membership.cancelled', $subscription, []);
    }

    /**
     * Cron sweep (`novfora:tiers:expire`): expire every active subscription past its expiry and revoke its
     * perks. Baseline-safe (hourly cron); returns the number expired.
     */
    public function expireDue(): int
    {
        $due = MemberSubscription::query()
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->with('user')
            ->get();

        foreach ($due as $subscription) {
            $subscription->forceFill(['status' => 'expired'])->save();
            $user = $subscription->user;
            if ($user instanceof User) {
                $this->projector->syncUser($user);
            }
            Audit::log('membership.expired', $subscription, []);
        }

        return $due->count();
    }
}
