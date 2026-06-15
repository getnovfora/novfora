<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Contracts\Factory;
use Laravel\Socialite\SocialiteManager;
use SocialiteProviders\Discord\Provider as DiscordProvider;

/**
 * Registers the non-core OAuth providers (Phase 4 · M2). Google + GitHub are built into Laravel Socialite;
 * Discord is contributed by socialiteproviders/discord and registered here as a custom Socialite driver. We
 * use Socialite::extend() directly (rather than the SocialiteWasCalled event) so the driver resolves without
 * depending on the SocialiteProviders manager replacing Socialite's binding. Credentials are configured per
 * request from encrypted settings in App\Auth\Social\SocialProviders (so config('services.discord') is set
 * before driver('discord') is called).
 */
class SocialiteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        /** @var SocialiteManager $socialite */
        $socialite = $this->app->make(Factory::class);

        $socialite->extend('discord', function () use ($socialite): DiscordProvider {
            /** @var array<string, mixed> $config */
            $config = (array) config('services.discord', []);

            /** @var DiscordProvider $provider */
            $provider = $socialite->buildProvider(DiscordProvider::class, $config);

            return $provider;
        });
    }
}
