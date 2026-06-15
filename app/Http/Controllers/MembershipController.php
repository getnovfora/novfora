<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MembershipTier;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The member-facing membership / upgrade surface (Phase 4 · M5.1). Lists the active tiers and the member's
 * current subscription. The actual purchase flow is the PaymentProvider's (M5.2 manual / M5.3 Stripe) — this
 * page never charges money.
 */
class MembershipController extends Controller
{
    public function index(Request $request): View
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
        ]);
    }
}
