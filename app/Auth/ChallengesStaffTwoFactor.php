<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Auth;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Grant the post-SSO session — but enforce mandatory staff 2FA on the SSO paths too (P5.1).
 *
 * RequireTwoFactorForStaff only checks whether an authenticator is *configured*, not whether a second factor
 * was *verified this session*. The password login is fine because Fortify challenges TOTP before the session
 * is granted; the OAuth/SAML callbacks, however, called Auth::login() directly, so a staff account with a
 * linked provider could reach the admin panels with no TOTP. This trait closes that gap on EVERY
 * session-granting SSO path: for staff with a confirmed authenticator it hands off to Fortify's existing
 * two-factor challenge (the same login.id stash the password pipeline uses) instead of granting a session,
 * so a privileged session never exists without a verified second factor. Non-staff — and staff without 2FA,
 * whom RequireTwoFactorForStaff routes to setup — sign in normally.
 */
trait ChallengesStaffTwoFactor
{
    protected function grantSsoSession(Request $request, User $user, string $intended = 'home'): RedirectResponse
    {
        if ($user->isStaff() && $user->two_factor_confirmed_at !== null) {
            // Defer to two-factor.login exactly as Fortify's password pipeline does: stash the pending login
            // and let the TOTP challenge verify the code before any authenticated session exists.
            $request->session()->put('login.id', $user->getKey());
            $request->session()->put('login.remember', true);

            return redirect()->route('two-factor.login');
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate(); // fresh session id post-auth (fixation defence)

        return redirect()->intended(route($intended));
    }
}
