<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Auth\Saml\Contracts;

use App\Auth\Saml\SamlAssertion;
use App\Auth\Saml\SamlException;

/**
 * The SAML SSO provider contract (Phase 4 · M2.4 — SCAFFOLD). NovFora ships NO concrete implementation: a real
 * one needs a SAML toolkit (e.g. onelogin/php-saml) and a live IdP to validate the XML-signature / metadata
 * handling — neither exists in this environment, and shipping an unvalidated crypto path would be worse than
 * shipping the seam. An operator (or a future module) binds a real implementation of this interface in the
 * container; until then `SamlManager` reports SAML as unavailable and every SAML route 404s. See ADR-0056.
 */
interface SamlProvider
{
    /** Is the provider fully configured (IdP entity id, SSO URL, signing certificate)? */
    public function isConfigured(): bool;

    /** The IdP SSO redirect URL to begin authentication (carrying the SP's AuthnRequest + RelayState). */
    public function loginUrl(?string $relayState = null): string;

    /**
     * Validate an IdP SAML Response (the ACS POST body) and return the asserted identity.
     *
     * @throws SamlException on an invalid/forged/expired response
     */
    public function consume(string $samlResponse): SamlAssertion;

    /** The SP metadata XML, for the operator to register NovFora at the IdP. */
    public function metadata(): string;
}
