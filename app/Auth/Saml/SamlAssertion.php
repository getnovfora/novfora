<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Auth\Saml;

/**
 * The validated identity a SAML IdP asserts about a user (Phase 4 · M2.4 — SCAFFOLD). A real
 * {@see Contracts\SamlProvider} produces this after verifying the response signature; the protocol layer that
 * does that verification is intentionally NOT shipped (see ADR-0056) — there is no IdP here to validate it.
 *
 * @property-read array<string, list<string>> $attributes
 */
final readonly class SamlAssertion
{
    /**
     * @param  string  $nameId  the IdP's stable subject identifier (the SAML NameID)
     * @param  array<string, list<string>>  $attributes  any additional asserted attributes
     */
    public function __construct(
        public string $nameId,
        public ?string $email = null,
        public array $attributes = [],
    ) {}
}
