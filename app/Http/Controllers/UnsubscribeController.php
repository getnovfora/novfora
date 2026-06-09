<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Deliverability\Unsubscribe;
use App\Models\User;
use Illuminate\Contracts\View\View;

/**
 * Spike P2 — 1-click unsubscribe (RFC 8058). The route carries Laravel's `signed` middleware, so the HMAC
 * in the URL is the authentication — no login, no CSRF token (the POST is exempt). Following or one-click-
 * POSTing it sets the user's digest cadence to 'off'; the send gate honours that at the next assembly/send.
 * Idempotent: re-visiting the link just re-applies 'off'.
 */
final class UnsubscribeController extends Controller
{
    public function __invoke(User $user): View
    {
        Unsubscribe::apply($user);

        return view('deliverability.unsubscribed', ['user' => $user]);
    }
}
