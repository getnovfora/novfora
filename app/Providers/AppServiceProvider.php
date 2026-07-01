<?php

namespace App\Providers;

use App\AntiSpam\ContentScanner;
use App\AntiSpam\LocalHeuristicsScanner;
use App\Install\EnvWriter;
use App\Install\Installer;
use App\Listeners\AuditAuthEvents;
use App\Models\User;
use App\Permissions\PermissionResolver;
use App\Permissions\Scope;
use App\Services\Tier\Probes\MeilisearchProbe;
use App\Services\Tier\Probes\RedisProbe;
use App\Services\Tier\Probes\ReverbProbe;
use App\Services\Tier\Probes\S3Probe;
use App\Services\Tier\ServiceTier;
use App\Support\Http\BasePathDetector;
use App\Webhooks\WebhookEventSubscriber;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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
        // RH-4.2 (ADR-0070, APEX): make the app base-path aware for subdirectory installs BEFORE any URL is
        // generated, so the pre-`.env` installer wizard at /community/install renders styled with a working
        // Livewire endpoint. Conservative — a strict no-op at the root layout and whenever APP_URL is a real
        // (non-localhost) value, so it never touches the root/subdomain layout. Skipped on the console (CLI /
        // queue / scheduler use APP_URL, and there is no HTTP request to derive a prefix from).
        if (! $this->app->runningInConsole()) {
            app(BasePathDetector::class)->apply($this->app->make('request'), (string) config('app.url'));
        }

        $this->prepareForInstaller();

        //  (resources/views/vendor/pagination). Owning these
        // lets resources/css/app.css drop its @source on the framework's pagination Blade in vendor/, so the
        // asset build needs no Composer and stays deterministic (PART 5 / CONTRIBUTING.md).
        Paginator::defaultView('pagination::novfora');
        Paginator::defaultSimpleView('pagination::simple-novfora');

        // REST API rate limit (ADR-0033): 60 requests/minute, keyed by the authenticated user when known,
        // else the client IP. `throttle:api` runs ahead of token auth, so unauthenticated floods are bounded
        // by IP before they reach the token lookup.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by(
            $request->user()?->getAuthIdentifier() ?? $request->ip() ?? 'api'
        ));

        // Embed surface rate limit (U7, ADR-0103): the endpoints are anonymous by design, so the key is the
        // client IP. Config-read per request so tests/operators can tune it without a cache clear; the
        // fragment cache + public Cache-Control absorb legitimate hot embeds long before this trips.
        RateLimiter::for('embed', fn (Request $request) => Limit::perMinute(
            max(1, (int) config('novfora.embeds.rate_limit', 120))
        )->by('embed:'.($request->ip() ?? 'unknown')));

        // Audit-log authentication events (phase-1.5 F-I): login / logout / failed / lockout / reset / 2FA.
        // (A subscriber — needs explicit registration; plain handle()-listeners in app/Listeners, e.g.
        // SendReactionNotification for the P2-M2 reaction notification, are AUTO-DISCOVERED — do not re-list.)
        Event::subscribe(AuditAuthEvents::class);

        // Outbound webhooks (ADR-0033): bridge the core domain events to pending webhook deliveries. The bridge
        // only inserts rows (the HTTP POST is the cron runner's job) and swallows any error, so it never breaks
        // the triggering action.
        Event::subscribe(WebhookEventSubscriber::class);

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
     * Before NovFora is installed, harden the app so a freshly-uploaded tree boots with NO database and
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
            // Mint the one-time installer setup token (phase-1.5 F-A) so the unauthenticated wizard can be
            // gated on a value only someone with filesystem access (FTP/cPanel) can read.
            app(Installer::class)->ensureToken();
        } catch (\Throwable) {
            // Filesystem not writable yet — the installer's requirements step will report it; never
            // crash the boot of the very page that is meant to diagnose the problem.
        }
    }
}
