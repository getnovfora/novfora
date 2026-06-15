<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Auth\Saml;

use App\Auth\Saml\Contracts\SamlProvider;
use App\Settings\Settings;
use Illuminate\Contracts\Container\Container;

/**
 * SAML availability detection (Phase 4 · M2.4 — SCAFFOLD). SAML is **OFF** unless ALL hold: the
 * `auth.saml.enabled` setting is on, a concrete {@see SamlProvider} is bound in the container (NovFora ships
 * none — an operator/module must provide one), and that provider reports itself configured. When SAML is
 * unavailable every SAML route 404s. This is the "behind detection" gate the brief requires; it guarantees the
 * unvalidated SAML surface is inert by default.
 */
class SamlManager
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Container $container,
    ) {}

    /** The bound provider, or null when none is registered (the default — no concrete impl ships). */
    public function provider(): ?SamlProvider
    {
        if (! $this->container->bound(SamlProvider::class)) {
            return null;
        }

        return $this->container->make(SamlProvider::class);
    }

    /** SAML is usable only when toggled on AND a configured provider is bound. */
    public function enabled(): bool
    {
        if (! $this->settings->bool('auth.saml.enabled')) {
            return false;
        }

        $provider = $this->provider();

        return $provider !== null && $provider->isConfigured();
    }
}
