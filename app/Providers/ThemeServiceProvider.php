<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Providers;

use App\Theme\ThemeManager;
use App\Theme\WidgetRegistry;
use App\Theme\Widgets\FeaturedWidget;
use App\Theme\Widgets\ForumStatsWidget;
use App\Theme\Widgets\HtmlBlockWidget;
use App\Theme\Widgets\OnlineUsersWidget;
use App\Theme\Widgets\RecentTopicsWidget;
use App\Theme\Widgets\SearchWidget;
use Illuminate\Support\ServiceProvider;

/**
 * Boots the active child theme (ADR-0009 §3.2) so its view overrides resolve ahead of core, and wires the
 * layout-widget registry (ADR-0032) — the built-in widgets are registered here; modules add their own to the
 * same singleton registry. A no-op theme is fine: the default experience is core's mobile-first views with the
 * a11y floor baked in, and empty regions render nothing.
 */
class ThemeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ThemeManager::class);
        $this->app->singleton(WidgetRegistry::class);
    }

    public function boot(): void
    {
        $this->app->make(ThemeManager::class)->boot();
        $this->registerBuiltInWidgets();
    }

    private function registerBuiltInWidgets(): void
    {
        $registry = $this->app->make(WidgetRegistry::class);
        $registry->register($this->app->make(HtmlBlockWidget::class));
        $registry->register($this->app->make(ForumStatsWidget::class));
        // Theme Studio 1.3 — the fuller first-party widget set.
        $registry->register($this->app->make(RecentTopicsWidget::class));
        $registry->register($this->app->make(OnlineUsersWidget::class));
        $registry->register($this->app->make(SearchWidget::class));
        $registry->register($this->app->make(FeaturedWidget::class));
    }
}
