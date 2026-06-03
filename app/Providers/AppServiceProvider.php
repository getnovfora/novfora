<?php

namespace App\Providers;

use App\Models\User;
use App\Permissions\PermissionResolver;
use App\Permissions\Scope;
use App\Services\Tier\Probes\MeilisearchProbe;
use App\Services\Tier\Probes\RedisProbe;
use App\Services\Tier\Probes\ReverbProbe;
use App\Services\Tier\Probes\S3Probe;
use App\Services\Tier\ServiceTier;
use Illuminate\Support\Facades\Gate;
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
            new RedisProbe,
            new MeilisearchProbe,
            new ReverbProbe,
            new S3Probe,
        ]));

        // Permission-mask engine (ADR-0006). Singleton so the per-request resolution memo
        // (and not just the cross-request cache) survives across many checks in one request.
        $this->app->singleton(PermissionResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Route scope-based authorization through the permission-mask engine, deny-by-default.
        // Usage: $user->can('forum.post.create', $scope) or Gate::allows('...', $scope).
        // Any check whose argument is a Scope is answered by the resolver; everything else
        // falls through (returns null) to Laravel's normal Gate/policy pipeline.
        Gate::before(function (User $user, string $ability, array $arguments) {
            $scope = $arguments[0] ?? null;

            return $scope instanceof Scope
                ? app(PermissionResolver::class)->can($user, $ability, $scope)
                : null;
        });
    }
}
