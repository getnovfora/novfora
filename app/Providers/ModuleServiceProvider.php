<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Providers;

use App\Modules\HookRegistry;
use App\Modules\ModuleLoader;
use App\Modules\ModuleManager;
use App\Modules\SlotRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the module/plugin foundation (ADR-0031): the hook + slot registries and the lifecycle manager are
 * singletons, and enabled modules are booted (their providers registered) on every request via ModuleLoader.
 * This provider is itself listed in bootstrap/providers.php; module providers are registered dynamically from
 * here so the core never references a module by name.
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HookRegistry::class);
        $this->app->singleton(SlotRegistry::class);
        $this->app->singleton(ModuleManager::class);
        $this->app->singleton(ModuleLoader::class);
    }

    public function boot(): void
    {
        $this->app->make(ModuleLoader::class)->boot($this->app);
    }
}
