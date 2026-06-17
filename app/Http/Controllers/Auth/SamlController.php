<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Auth\ChallengesStaffTwoFactor;
use App\Auth\Saml\Contracts\SamlProvider;
use App\Auth\Saml\SamlException;
use App\Auth\Saml\SamlManager;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

/**
 * SAML SSO endpoints (Phase 4 · M2.4 — SCAFFOLD, NOT validated against a real IdP). Every action is gated by
 * {@see SamlManager::enabled()} and 404s when SAML is unavailable (the default — no concrete provider ships).
 * The protocol work (AuthnRequest, response-signature validation, metadata) lives behind the SamlProvider
 * contract, which an operator/module binds. Account resolution reuses the `social_accounts` table
 * (provider='saml'): a PRE-LINKED subject is signed in; just-in-time provisioning is deliberately NOT
 * implemented here (it would carry the same no-silent-merge rule as OAuth — ADR-0056).
 */
class SamlController extends Controller
{
    use ChallengesStaffTwoFactor;

    public function __construct(private readonly SamlManager $saml) {}

    /** Begin SAML login: redirect to the IdP's SSO URL. */
    public function login(): SymfonyRedirect
    {
        $provider = $this->available();

        return redirect()->away($provider->loginUrl());
    }

    /** Assertion Consumer Service: validate the IdP response and sign in a pre-linked account. CSRF-exempt. */
    public function consume(Request $request): RedirectResponse
    {
        $provider = $this->available();

        try {
            $assertion = $provider->consume((string) $request->input('SAMLResponse'));
        } catch (SamlException $e) {
            return redirect()->route('login')->with('error', __('We could not verify the SAML response.'));
        }

        $link = SocialAccount::query()
            ->where('provider', 'saml')
            ->where('provider_user_id', $assertion->nameId)
            ->first();

        if (! $link instanceof SocialAccount || ! $link->user instanceof User) {
            // SCAFFOLD: no just-in-time provisioning. Only a subject already linked to an account signs in.
            return redirect()->route('login')->with('error', __('No NovFora account is linked to this SAML identity.'));
        }

        // Grant the session — staff with a confirmed authenticator step up to the TOTP challenge (P5.1).
        return $this->grantSsoSession($request, $link->user);
    }

    /** SP metadata XML for the operator to register at the IdP. */
    public function metadata(): Response
    {
        $provider = $this->available();

        return response($provider->metadata(), 200, ['Content-Type' => 'application/samlmetadata+xml']);
    }

    /** 404 unless SAML is enabled AND a configured provider is bound. */
    private function available(): SamlProvider
    {
        abort_unless($this->saml->enabled(), 404);
        $provider = $this->saml->provider();
        abort_unless($provider !== null, 404);

        return $provider;
    }
}
