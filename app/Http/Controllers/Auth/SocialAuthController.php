<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Auth\Social\SocialAuthException;
use App\Auth\Social\SocialLogin;
use App\Auth\Social\SocialProviders;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

/**
 * OAuth social SIGN-IN (Phase 4 · M2.1). The password login path is untouched; this is an alternative.
 * Socialite runs STATEFUL (a `state` nonce in the session, validated on callback) — the CSRF defence for the
 * round-trip. The account-resolution rules (no silent merge on email collision) live in {@see SocialLogin}.
 * Account LINKING (authenticated) is handled separately in M2.2.
 */
class SocialAuthController extends Controller
{
    public function __construct(private readonly SocialProviders $providers) {}

    /** Kick off the OAuth dance: redirect the visitor to the provider's consent screen. */
    public function redirect(Request $request, string $provider): SymfonyRedirect
    {
        abort_unless($this->providers->isAvailable($provider), 404);

        return $this->providers->driver($provider)->redirect();
    }

    /** Handle the provider callback: validate state, resolve the local account, and sign in. */
    public function callback(Request $request, string $provider, SocialLogin $login): RedirectResponse
    {
        abort_unless($this->providers->isAvailable($provider), 404);

        // The user declined consent at the provider.
        if ($request->has('error')) {
            return redirect()->route('login')->with('error', __('Sign-in was cancelled.'));
        }

        try {
            $socialUser = $this->providers->driver($provider)->user();
        } catch (InvalidStateException) {
            // State nonce mismatch — an expired session or a forged callback. Fail closed.
            return redirect()->route('login')->with('error', __('Your sign-in session expired. Please try again.'));
        } catch (\Throwable $e) {
            Log::warning('OAuth callback failed', ['provider' => $provider, 'error' => $e->getMessage()]);

            return redirect()->route('login')->with('error', __('We could not complete sign-in with :provider.', ['provider' => $this->providers->label($provider)]));
        }

        try {
            $user = $login->resolveForLogin($provider, $socialUser);
        } catch (SocialAuthException $e) {
            return redirect()->route('login')->with('error', $e->getMessage());
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate(); // fresh session id post-auth (fixation defence)

        // Staff must still satisfy the 2FA gate (RequireTwoFactorForStaff) before reaching the ACP — SSO does
        // not bypass it. A normal member lands on the home feed.
        return redirect()->intended(route('home'));
    }
}
