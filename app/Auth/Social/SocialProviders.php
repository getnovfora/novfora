<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Auth\Social;

use App\Settings\Settings;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;

/**
 * The supported OAuth providers and the bridge to Socialite (Phase 4 · M2). A provider is "available" only
 * when an admin has BOTH toggled it on AND supplied a client id + secret (secrets stored encrypted; ADR-0053).
 * The Socialite driver is configured PER REQUEST from the encrypted settings — no env / config/services.php
 * entries are required, and a disabled provider never reaches a redirect.
 */
class SocialProviders
{
    /** @var array<string, array{label: string, scopes: list<string>}> */
    public const PROVIDERS = [
        'google' => ['label' => 'Google', 'scopes' => ['openid', 'profile', 'email']],
        'github' => ['label' => 'GitHub', 'scopes' => ['read:user', 'user:email']],
        'discord' => ['label' => 'Discord', 'scopes' => ['identify', 'email']],
    ];

    public function __construct(private readonly Settings $settings) {}

    public function isSupported(string $provider): bool
    {
        return array_key_exists($provider, self::PROVIDERS);
    }

    /** Toggled on AND fully configured (id + secret present). */
    public function isAvailable(string $provider): bool
    {
        return $this->isSupported($provider)
            && $this->settings->bool("oauth.{$provider}.enabled")
            && $this->settings->string("oauth.{$provider}.client_id") !== ''
            && $this->settings->secretIsSet("oauth.{$provider}.client_secret");
    }

    /** The providers a visitor may use right now (drives the login-page buttons). @return list<string> */
    public function available(): array
    {
        return array_values(array_filter(array_keys(self::PROVIDERS), fn (string $p): bool => $this->isAvailable($p)));
    }

    public function label(string $provider): string
    {
        return self::PROVIDERS[$provider]['label'] ?? ucfirst($provider);
    }

    /**
     * A Socialite driver for $provider, configured from the encrypted settings + our callback URL. The caller
     * MUST gate on isAvailable() first.
     */
    public function driver(string $provider): Provider
    {
        config(["services.{$provider}" => [
            'client_id' => $this->settings->string("oauth.{$provider}.client_id"),
            'client_secret' => (string) $this->settings->get("oauth.{$provider}.client_secret"),
            'redirect' => route('oauth.callback', $provider),
        ]]);

        /** @var Provider $driver */
        $driver = Socialite::driver($provider);

        // scopes() lives on the OAuth2 base driver (all three providers extend it), not the bare contract.
        if ($driver instanceof AbstractProvider && ! empty(self::PROVIDERS[$provider]['scopes'])) {
            $driver->scopes(self::PROVIDERS[$provider]['scopes']);
        }

        return $driver;
    }
}
