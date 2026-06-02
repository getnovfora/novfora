<?php

namespace App\Providers;

use App\Services\Tier\Probes\MeilisearchProbe;
use App\Services\Tier\Probes\RedisProbe;
use App\Services\Tier\Probes\ReverbProbe;
use App\Services\Tier\Probes\S3Probe;
use App\Services\Tier\ServiceTier;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Service-tier detection (ADR-0003): one aggregator wired with the optional-service probes.
        // Probes never throw, so resolving/using this is always safe on the baseline tier.
        $this->app->singleton(ServiceTier::class, fn () => new ServiceTier([
            new RedisProbe(),
            new MeilisearchProbe(),
            new ReverbProbe(),
            new S3Probe(),
        ]));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
