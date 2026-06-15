<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Membership\Payments;

/**
 * The registry of available payment providers (Phase 4 · M5.2). The member surface and the ACP ask this which
 * providers are enabled and which support member self-checkout. The MANUAL provider is always present (the
 * baseline grant path); Stripe (M5.3) is added here behind its enabled flag — disabled by default, so until an
 * operator deliberately configures + enables it, no online checkout is offered anywhere.
 */
final class PaymentProviders
{
    /** @return array<string, PaymentProvider> enabled providers, keyed by key() */
    public function enabled(): array
    {
        $providers = [];

        foreach ($this->candidates() as $provider) {
            if ($provider->isEnabled()) {
                $providers[$provider->key()] = $provider;
            }
        }

        return $providers;
    }

    public function get(string $key): ?PaymentProvider
    {
        return $this->enabled()[$key] ?? null;
    }

    /** @return array<string, PaymentProvider> enabled providers offering member self-checkout (e.g. Stripe). */
    public function selfCheckout(): array
    {
        return array_filter($this->enabled(), static fn (PaymentProvider $p) => $p->supportsSelfCheckout());
    }

    /** @return list<PaymentProvider> every known provider implementation (filtered by isEnabled in enabled()). */
    private function candidates(): array
    {
        return [
            app(ManualPaymentProvider::class),
            app(StripePaymentProvider::class), // disabled by default (charging off until an operator enables it)
        ];
    }
}
