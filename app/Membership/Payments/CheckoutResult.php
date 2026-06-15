<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Membership\Payments;

/**
 * The outcome of starting a checkout (Phase 4 · M5.2). For a hosted provider it carries the URL the member is
 * redirected to (Stripe Checkout). It never carries card data.
 */
final class CheckoutResult
{
    public function __construct(
        public readonly ?string $redirectUrl = null,
        public readonly string $message = '',
    ) {}

    public static function redirect(string $url): self
    {
        return new self(redirectUrl: $url);
    }
}
