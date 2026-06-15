<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Membership\Payments\PaymentException;
use App\Membership\Payments\PaymentProviders;
use App\Models\MembershipTier;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The member-facing membership / upgrade surface (Phase 4 · M5.1 + M5.3). Lists the active tiers and the
 * member's current subscription, and — IF a self-checkout provider is enabled (Stripe, off by default) —
 * starts a hosted checkout. It never handles card data; Stripe hosts the payment page.
 */
class MembershipController extends Controller
{
    public function index(Request $request, PaymentProviders $providers): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $tiers = MembershipTier::query()
            ->where('is_active', true)
            ->orderBy('sort')
            ->orderBy('price_cents')
            ->get();

        return view('membership.index', [
            'tiers' => $tiers,
            'current' => $user->activeSubscription(),
            'canCheckout' => $providers->selfCheckout() !== [],
        ]);
    }

    /** Start a hosted checkout for a tier. 404s unless a self-checkout provider is enabled (Stripe off by default). */
    public function checkout(Request $request, MembershipTier $tier, PaymentProviders $providers): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        abort_unless($tier->is_active, 404);

        $provider = array_values($providers->selfCheckout())[0] ?? null;
        abort_if($provider === null, 404); // no online checkout configured

        try {
            $result = $provider->checkout($user, $tier);
        } catch (PaymentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $result->redirectUrl !== null
            ? redirect()->away($result->redirectUrl)
            : back()->with('error', 'Checkout could not be started.');
    }
}
