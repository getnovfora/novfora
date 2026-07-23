<?php

use App\Http\Middleware\EnsureBoardOnline;
use App\Http\Middleware\EnsureNotInstalled;
use App\Http\Middleware\PreventRequestsDuringUpgrade;
use App\Http\Middleware\PwaResponseHeaders;
use App\Http\Middleware\RedirectIfNotInstalled;
use App\Http\Middleware\RequireApiScope;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\ThrottledLastActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',   // versioned REST API (ADR-0033) — token-auth, engine-authorized
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Embed surface (U7, ADR-0103): deliberately OUTSIDE the web group — stateless (no session/cookies,
        // no CSRF surface), GET-only, rate-limited; each response owns its security headers (EmbedController).
        then: function (): void {
            Route::middleware([
                RedirectIfNotInstalled::class,
                PreventRequestsDuringUpgrade::class,
                EnsureBoardOnline::class,
                'throttle:embed',
            ])->prefix('embed/v1')->name('embed.')->group(__DIR__.'/../routes/embed.php');
        },
    )
    // Realtime broadcast channel authorization (Phase 4 · M4.2). Registers the session-authenticated
    // /broadcasting/auth endpoint and the private-channel callbacks (routes/channels.php → ChannelAuthorizer).
    // Runs on every tier — the no-leak boundary holds even when the broadcaster is null/log on the baseline.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['web']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Until NovFora is installed, force every web request to the no-SSH installer (M5). Appended so it
        // runs after the session has started — the wizard is a Livewire component and needs the session.
        $middleware->web(append: [
            RedirectIfNotInstalled::class,
            // Emit the baseline security headers (CSP, nosniff, frame-ancestors, HSTS-on-TLS) on every
            // web response, before and after install (security §4).
            SecurityHeaders::class,
            // Serve a branded maintenance 503 — never a raw SQL error — while a no-SSH upgrade applies
            // pending migrations (RH-10). Appended AFTER SecurityHeaders so the 503 still carries them;
            // the decision is O(cache-read) — no DB-heavy migrator/schema check on the request path.
            PreventRequestsDuringUpgrade::class,
            // The "board offline" switch (ACP v1, General settings): a branded 503 for guests/members,
            // admins pass. One O(cache-read) settings lookup; never reached pre-install.
            EnsureBoardOnline::class,
            // Stamp last_active_at for the online heuristic (P2-M3), throttled to ≤1 raw write / user / 5 min.
            ThrottledLastActive::class,
            // Resolve the UI locale (Wave 8.1) from the member preference / session switcher / default,
            // validated against the allowlist. Appended so the session is already started when it reads.
            SetLocale::class,
            // PWA (Phase 4 · M3.1): flag guest, no-PII GET pages as safe for the service worker to cache for
            // offline read. Authenticated pages never get the flag, so the SW never stores PII. Appended LAST
            // so auth is resolved when it checks. Cheap (one guest + path check).
            PwaResponseHeaders::class,
        ]);

        // Trusted reverse proxies (E1 hardening / NOV-135). Behind nginx / a load balancer / Cloudflare,
        // `request()->ip()` — used by the API-token ip_allowlist, the audit ip_address, and the rate-limit key —
        // otherwise resolves to the PROXY, silently defeating IP pinning. Set TRUSTED_PROXIES (a comma list, or
        // `*` for a fully trusted edge like a container network) so the real client IP is read from
        // X-Forwarded-For. UNSET = trust nothing (REMOTE_ADDR only), which fails CLOSED against XFF spoofing.
        $trustedProxies = env('TRUSTED_PROXIES');
        if (is_string($trustedProxies) && trim($trustedProxies) !== '') {
            $middleware->trustProxies(
                at: trim($trustedProxies) === '*' ? '*' : array_map('trim', explode(',', $trustedProxies)),
                headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST
                    | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
            );
        }

        // The installer lock — applied to the installer routes so they 403 once installed.
        $middleware->alias([
            'novfora.not-installed' => EnsureNotInstalled::class,
            // Admin-API scope gate (E1 / NOV-135): `->middleware('api.scope:admin:settings.write')`.
            'api.scope' => RequireApiScope::class,
        ]);

        // Spike P2 (deliverability): the inbound provider bounce/complaint webhook and the RFC 8058
        // one-click unsubscribe POST are machine-to-machine endpoints with no session/CSRF token — their
        // auth is an HMAC (webhook) / a Laravel signed URL (unsubscribe), so they are CSRF-exempt. Static
        // patterns; they simply match nothing while the (dormant-by-default) webhook route is unregistered.
        $middleware->validateCsrfTokens(except: [
            'webhooks/mail/*',
            // Stripe webhook (Phase 4 · M5.3): machine-to-machine, no session/CSRF token — authenticated by the
            // Stripe-Signature HMAC inside StripeWebhookVerifier. Inert (404) until Stripe is enabled.
            'webhooks/stripe',
            'unsubscribe/*',
            // SAML ACS (Phase 4 · M2.4 SCAFFOLD): the IdP POSTs the assertion cross-site with no session/CSRF
            // token; it is authenticated by the IdP's XML signature inside the provider. Inert by default
            // (the route 404s unless SAML is enabled AND a provider is bound).
            'auth/saml/acs',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Render JSON errors for any request that asks for JSON (AJAX/fetch endpoints such as the editor
        // upload + mention typeahead), as well as future api/* routes. Web form posts (which do not
        // expectJson) still get the redirect-back-with-errors behaviour.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->expectsJson() || $request->is('api/*'),
        );

        // Admin-API consistent error envelope (E1 / NOV-135, ADR-0115): `{error: {code, message, fields?}}` for
        // every `api/admin/*` failure — validation, aborts (401/403/404), rate-limit. Other routes keep Laravel's
        // default JSON shape, so the member API (api/v1) is untouched.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/admin/*')) {
                return null;
            }
            if ($e instanceof ValidationException) {
                return response()->json(['error' => [
                    'code' => 'validation_failed',
                    'message' => 'The request is invalid.',
                    'fields' => $e->errors(),
                ]], 422);
            }
            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();
                $message = $e->getMessage();

                return response()->json(['error' => [
                    'code' => match ($status) {
                        401 => 'unauthenticated', 403 => 'forbidden', 404 => 'not_found',
                        405 => 'method_not_allowed', 429 => 'rate_limited', default => 'error',
                    },
                    'message' => $message !== '' ? $message : 'Request failed.',
                ]], $status);
            }

            return null; // any other exception falls through to the default renderer
        });
    })->create();
