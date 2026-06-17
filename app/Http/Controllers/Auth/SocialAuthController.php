<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Auth\ChallengesStaffTwoFactor;
use App\Auth\Social\SocialAuthException;
use App\Auth\Social\SocialLogin;
use App\Auth\Social\SocialProviders;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

/**
 * OAuth social SIGN-IN + account LINKING (Phase 4 · M2.1/M2.2). The password login path is untouched; this is
 * an alternative + an opt-in link from the user's settings. Socialite runs STATEFUL (a `state` nonce in the
 * session, validated on callback) — the CSRF defence for the round-trip. The shared callback disambiguates a
 * LINK (an authenticated user who started from settings — a `oauth.link_intent` session flag) from a LOGIN.
 * Resolution rules (no silent merge on email collision; identity-already-linked-elsewhere) live in
 * {@see SocialLogin}.
 */
class SocialAuthController extends Controller
{
    use ChallengesStaffTwoFactor;

    public function __construct(private readonly SocialProviders $providers) {}

    /** Kick off a LOGIN: redirect the visitor to the provider's consent screen. */
    public function redirect(Request $request, string $provider): SymfonyRedirect
    {
        abort_unless($this->providers->isAvailable($provider), 404);

        return $this->providers->driver($provider)->redirect();
    }

    /** Handle the provider callback: validate state, then either LINK (authed, from settings) or SIGN IN. */
    public function callback(Request $request, string $provider, SocialLogin $login): RedirectResponse
    {
        abort_unless($this->providers->isAvailable($provider), 404);

        $linking = $request->session()->pull('oauth.link_intent') === $provider && Auth::check();

        if ($request->has('error')) {
            return $this->back($linking)->with('error', $linking ? __('Linking was cancelled.') : __('Sign-in was cancelled.'));
        }

        try {
            $socialUser = $this->providers->driver($provider)->user();
        } catch (InvalidStateException) {
            return $this->back($linking)->with('error', __('Your session expired. Please try again.'));
        } catch (\Throwable $e) {
            Log::warning('OAuth callback failed', ['provider' => $provider, 'error' => $e->getMessage()]);

            return $this->back($linking)->with('error', __('We could not complete the request with :provider.', ['provider' => $this->providers->label($provider)]));
        }

        // ── LINK flow (authenticated user, started from settings) ──
        if ($linking) {
            $user = $request->user();
            if (! $user instanceof User) {
                return redirect()->route('login');
            }
            try {
                $login->link($user, $provider, $socialUser);
            } catch (SocialAuthException $e) {
                return redirect()->route('settings.linked-accounts')->with('error', $e->getMessage());
            }

            return redirect()->route('settings.linked-accounts')->with('status', __(':provider linked.', ['provider' => $this->providers->label($provider)]));
        }

        // ── LOGIN flow ──
        try {
            $user = $login->resolveForLogin($provider, $socialUser);
        } catch (SocialAuthException $e) {
            return redirect()->route('login')->with('error', $e->getMessage());
        }

        // Grant the session — but staff with a confirmed authenticator are stepped up to the TOTP challenge
        // (P5.1): SSO must not mint a privileged session without a verified second factor. A member lands home.
        return $this->grantSsoSession($request, $user);
    }

    /** Settings → Linked accounts: the user's linked identities + the providers they can still link. */
    public function linkedAccounts(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return view('settings.linked-accounts', [
            'linked' => $user->socialAccounts()->get()->keyBy('provider'),
            'available' => $this->providers->available(),
            'providers' => SocialProviders::PROVIDERS,
        ]);
    }

    /** Begin linking a provider to the authenticated account (POST → provider consent). */
    public function startLink(Request $request, string $provider): SymfonyRedirect
    {
        abort_unless($this->providers->isAvailable($provider), 404);

        $request->session()->put('oauth.link_intent', $provider);

        return $this->providers->driver($provider)->redirect();
    }

    /** Unlink a provider from the authenticated account. Always safe (password/email remain). */
    public function unlink(Request $request, string $provider, SocialLogin $login): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $login->unlink($user, $provider);

        return redirect()->route('settings.linked-accounts')->with('status', __('Account unlinked.'));
    }

    /** Where a failure/cancel returns to — the settings page when linking, the login page when signing in. */
    private function back(bool $linking): RedirectResponse
    {
        return redirect()->route($linking ? 'settings.linked-accounts' : 'login');
    }
}
