<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 2FA is MANDATORY for staff — admins & moderators (the brief's "Must"). A staff member without a
 * CONFIRMED authenticator is bounced to the 2FA setup page (which stays reachable so they can comply).
 * General users are unaffected — opt-in 2FA is a Phase 2 "Should". Apply this to privileged routes;
 * the permission engine still enforces authorization independently — this only hard-requires the
 * second factor before staff may use those privileges.
 */
class RequireTwoFactorForStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->isStaff() && $user->two_factor_confirmed_at === null) {
            return redirect()->route('settings.two-factor')->with('status', 'two-factor-required');
        }

        return $next($request);
    }
}
