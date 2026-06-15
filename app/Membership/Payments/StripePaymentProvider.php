<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Membership\Payments;

use App\Models\MembershipTier;
use App\Models\User;
use App\Settings\Settings;
use Illuminate\Support\Facades\Http;

/**
 * Stripe HOSTED Checkout provider (Phase 4 · M5.3). CHARGING IS DISABLED BY DEFAULT — `isEnabled()` is false
 * until an operator deliberately sets the secret key AND turns the provider on, so no online charge can ever
 * be initiated by this build. When enabled, card data NEVER touches our server: we create a Stripe-hosted
 * Checkout Session and redirect the member to Stripe (minimal PCI). The grant happens later, on the signed
 * `checkout.session.completed` webhook (StripeWebhookController) — never on this request.
 *
 * SSRF posture: the Stripe API base is a CONSTANT (never a payload-derived URL), and success/cancel URLs are
 * built from our own named routes — there is no attacker-controlled URL on any outbound request.
 *
 * ⚠ NOT VALIDATED against live Stripe (no keys in this build). The request SHAPE is asserted with a mocked
 * HTTP client; the real round-trip + the exact form-encoding must be validated before going live (see ADR-0065).
 */
final class StripePaymentProvider implements PaymentProvider
{
    private const CHECKOUT_API = 'https://api.stripe.com/v1/checkout/sessions';

    public function __construct(private readonly Settings $settings) {}

    public function key(): string
    {
        return 'stripe';
    }

    public function label(): string
    {
        return 'Stripe';
    }

    /** DISABLED by default: both the enable flag AND a configured secret key are required. */
    public function isEnabled(): bool
    {
        return $this->settings->bool('payments.stripe.enabled')
            && $this->settings->secretIsSet('payments.stripe.secret_key');
    }

    public function supportsSelfCheckout(): bool
    {
        return $this->isEnabled();
    }

    public function checkout(User $user, MembershipTier $tier): CheckoutResult
    {
        if (! $this->isEnabled()) {
            // Hard money fence: a disabled/unconfigured provider can NEVER reach the Stripe API.
            throw new PaymentException('Stripe checkout is not enabled on this site.');
        }

        $mode = $tier->interval === 'one_time' ? 'payment' : 'subscription';

        $priceData = [
            'currency' => mb_strtolower($tier->currency),
            'unit_amount' => (int) $tier->price_cents,
            'product_data' => ['name' => $tier->name],
        ];
        if ($mode === 'subscription') {
            $priceData['recurring'] = ['interval' => $tier->interval === 'yearly' ? 'year' : 'month'];
        }

        // Nested arrays form-encode to Stripe's bracket syntax (line_items[0][price_data][unit_amount]=…).
        $payload = [
            'mode' => $mode,
            'success_url' => route('membership.index').'?status=success',
            'cancel_url' => route('membership.index').'?status=cancel',
            'client_reference_id' => (string) $user->getKey(),
            'metadata' => ['user_id' => (int) $user->getKey(), 'tier_id' => (int) $tier->getKey()],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => $priceData,
            ]],
        ];

        $response = Http::withToken($this->settings->string('payments.stripe.secret_key'))
            ->asForm()
            ->post(self::CHECKOUT_API, $payload);

        if (! $response->successful()) {
            throw new PaymentException('Stripe checkout could not be created.');
        }

        $url = (string) ($response->json('url') ?? '');
        if ($url === '') {
            throw new PaymentException('Stripe returned no checkout URL.');
        }

        return CheckoutResult::redirect($url);
    }
}
