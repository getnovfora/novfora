<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Membership\Payments;

use App\Membership\MembershipService;
use App\Models\MembershipTier;
use App\Models\User;

/**
 * The payment-provider contract (Phase 4 · M5.2). A provider abstracts HOW a membership is paid for; the
 * GRANT itself always flows through {@see MembershipService} so capabilities resolve the same
 * way regardless of provider. Two implementations: the offline/MANUAL provider (admin marks a member paid —
 * the only live-granting path in this build) and Stripe (M5.3, hosted checkout, charging DISABLED).
 *
 * This is a semver'd public contract — a breaking change is a major-version event.
 */
interface PaymentProvider
{
    /** Stable machine key, e.g. 'manual' | 'stripe'. */
    public function key(): string;

    /** Human label for the ACP / member surfaces. */
    public function label(): string;

    /** Whether the provider is configured AND turned on. A disabled provider is never offered. */
    public function isEnabled(): bool;

    /** Whether a MEMBER can self-checkout online with this provider (manual = false; an admin grants instead). */
    public function supportsSelfCheckout(): bool;

    /**
     * Begin a member-initiated checkout and return where to send them (a hosted-checkout URL). Providers that
     * do not support self-checkout (or are disabled) MUST throw — this is never reached for them because the
     * member surface only offers self-checkout providers.
     *
     * @throws PaymentException
     */
    public function checkout(User $user, MembershipTier $tier): CheckoutResult;
}
