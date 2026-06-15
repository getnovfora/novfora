<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Membership\Payments;

use App\Membership\MembershipService;
use App\Models\MembershipTier;
use App\Models\MemberSubscription;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The offline / MANUAL payment provider (Phase 4 · M5.2) — the ONLY path that actually grants a membership in
 * this build. An admin records that a member has paid (cash, bank transfer, comp, etc.) and {@see grant()}
 * activates the subscription through {@see MembershipService}; an expiry is swept by the hourly cron, or an
 * admin {@see revoke()}s. There is no online checkout — `supportsSelfCheckout()` is false — so it never appears
 * as a member "buy" button; it is driven entirely from the admin membership surface.
 */
final class ManualPaymentProvider implements PaymentProvider
{
    public function __construct(private readonly MembershipService $memberships) {}

    public function key(): string
    {
        return 'manual';
    }

    public function label(): string
    {
        return 'Offline / manual';
    }

    /** Always available — it is the baseline grant path and requires no external service or secret. */
    public function isEnabled(): bool
    {
        return true;
    }

    public function supportsSelfCheckout(): bool
    {
        return false;
    }

    public function checkout(User $user, MembershipTier $tier): CheckoutResult
    {
        throw new PaymentException('Manual memberships are granted by an administrator, not via online checkout.');
    }

    /** Admin grant: activate the member on the tier (optionally with an expiry). Returns the subscription. */
    public function grant(User $user, MembershipTier $tier, ?Carbon $expiresAt = null): MemberSubscription
    {
        return $this->memberships->activate($user, $tier, 'manual', 'manual:'.$user->getKey().':'.now()->timestamp, $expiresAt);
    }

    /** Admin revoke: cancel the subscription now and revoke its perks. */
    public function revoke(MemberSubscription $subscription): void
    {
        $this->memberships->cancel($subscription);
    }
}
