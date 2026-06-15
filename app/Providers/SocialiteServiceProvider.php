<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Discord\Provider as DiscordProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;

/**
 * Registers the non-core OAuth providers (Phase 4 · M2). Google + GitHub are built into Laravel Socialite;
 * Discord is contributed by socialiteproviders/discord and wired here via the SocialiteWasCalled event.
 * Credentials are configured per request from encrypted settings in App\Auth\Social\SocialProviders.
 */
class SocialiteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(SocialiteWasCalled::class, function (SocialiteWasCalled $event): void {
            $event->extendSocialite('discord', DiscordProvider::class);
        });
    }
}
