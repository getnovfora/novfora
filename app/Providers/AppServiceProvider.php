<?php

namespace App\Providers;

use App\AntiSpam\ContentScanner;
use App\AntiSpam\LocalHeuristicsScanner;
use App\Install\EnvWriter;
use App\Install\Installer;
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

        // Content-scanning contract (ADR-0007 §2.4): local heuristics now; an Akismet provider swaps in
        // here in Phase 2 with no change to the moderation pipeline.
        $this->app->bind(ContentScanner::class, LocalHeuristicsScanner::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->prepareForInstaller();

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

    /**
     * Before Hearth is installed, harden the app so a freshly-uploaded tree boots with NO database and
     * NO pre-set APP_KEY: force zero-dependency drivers (file session/cache, synchronous queue) and
     * generate + persist an APP_KEY so sessions/encryption — and thus the Livewire installer wizard —
     * work. Runs in boot(), before StartSession reads the session driver. A no-op once installed, and in
     * environments that opt out (the test suite), via Installer::shouldEnforce().
     */
    private function prepareForInstaller(): void
    {
        if (! app(Installer::class)->shouldEnforce()) {
            return;
        }

        config([
            'session.driver' => 'file',
            'cache.default' => 'file',
            'queue.default' => 'sync',
            // The pre-install surface is unauthenticated and reachable by anyone who finds a freshly
            // uploaded site. A fresh `.env` (copied from `.env.example`) ships APP_DEBUG=true, which would
            // expose stack traces (paths, config) to that anonymous visitor. Force it off until the
            // installer writes the real production `.env` (APP_DEBUG=false). Defence in depth alongside the
            // wizard's own try/catch and the DatabaseVerifier's sanitised messages.
            'app.debug' => false,
        ]);

        try {
            app(EnvWriter::class)->ensureAppKey();
        } catch (\Throwable) {
            // Filesystem not writable yet — the installer's requirements step will report it; never
            // crash the boot of the very page that is meant to diagnose the problem.
        }
    }
}
