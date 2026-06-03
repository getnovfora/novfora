<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Providers;

use App\Theme\ThemeManager;
use Illuminate\Support\ServiceProvider;

/**
 * Boots the active child theme (ADR-0009 §3.2) so its view overrides resolve ahead of core. A no-op when no
 * theme is configured — the default experience is core's mobile-first views with the a11y floor baked in.
 */
class ThemeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ThemeManager::class);
    }

    public function boot(): void
    {
        $this->app->make(ThemeManager::class)->boot();
    }
}
