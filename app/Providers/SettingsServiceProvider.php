<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Providers;

use App\Install\Installer;
use App\Settings\Settings;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the site-settings service (ACP v1, PART 0). Singleton so the per-request memo of the settings
 * bag is shared across the request. On boot — and only once the site is installed and not in the
 * pre-install enforcement window — it pushes every DB-overridden, config-backed setting into the live
 * config(), so the mailer, the anti-spam pipeline, app.name, and the theme honour panel overrides with
 * no change to their own code. Pre-install it is a no-op (no DB touch), mirroring AppServiceProvider's
 * installer guard.
 */
class SettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Settings::class);
    }

    public function boot(): void
    {
        // Pre-install (enforce on, not yet installed): no settings table, no DB — keep boot DB-free.
        if ($this->app->make(Installer::class)->shouldEnforce()) {
            return;
        }

        $this->app->make(Settings::class)->applyToConfig();

        // Note: the display-only siteView() bag is resolved inline by the few views that need it
        // (layouts.app, forum.topic, forum.show) via `app(Settings::class)->siteView()` — memoised, so it
        // is one cache read per request. We deliberately do NOT register a global view composer: firing on
        // every component/partial render is needless work (and pushed the full test run past its memory cap).
    }
}
