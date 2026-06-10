<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Deliverability\Unsubscribe;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Spike P2 / P2-M2 — 1-click unsubscribe (RFC 8058). The route carries Laravel's `signed` middleware, so the
 * HMAC in the URL is the authentication — no login, no CSRF token (the POST is exempt).
 *
 * GET-confirm / POST-apply split (memo §8 follow-up): a GET only RENDERS a confirm page — it applies nothing,
 * so an email scanner that prefetches the link can't silently unsubscribe the user. The opt-out (cadence →
 * 'off') is applied ONLY by a POST: the RFC 8058 one-click POST (List-Unsubscribe-Post), or the confirm form.
 * Idempotent: re-POSTing just re-applies 'off'.
 */
final class UnsubscribeController extends Controller
{
    public function __invoke(Request $request, User $user): View
    {
        if ($request->isMethod('POST')) {
            Unsubscribe::apply($user);

            return view('deliverability.unsubscribed', ['user' => $user]);
        }

        // GET: confirm page only. The form POSTs back to this same signed URL (HMAC preserved).
        return view('deliverability.unsubscribe-confirm', ['user' => $user, 'action' => $request->fullUrl()]);
    }
}
