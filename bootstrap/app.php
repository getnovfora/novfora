<?php

use App\Http\Middleware\EnsureNotInstalled;
use App\Http\Middleware\PreventRequestsDuringUpgrade;
use App\Http\Middleware\RedirectIfNotInstalled;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Until Hearth is installed, force every web request to the no-SSH installer (M5). Appended so it
        // runs after the session has started — the wizard is a Livewire component and needs the session.
        $middleware->web(append: [
            RedirectIfNotInstalled::class,
            // Emit the baseline security headers (CSP, nosniff, frame-ancestors, HSTS-on-TLS) on every
            // web response, before and after install (security §4).
            SecurityHeaders::class,
            // Serve a branded maintenance 503 — never a raw SQL error — while a no-SSH upgrade applies
            // pending migrations (RH-10). Appended AFTER SecurityHeaders so the 503 still carries them;
            // the decision is O(cache-read) and never touches the DB on the request path.
            PreventRequestsDuringUpgrade::class,
        ]);

        // The installer lock — applied to the installer routes so they 403 once installed.
        $middleware->alias([
            'hearth.not-installed' => EnsureNotInstalled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Render JSON errors for any request that asks for JSON (AJAX/fetch endpoints such as the editor
        // upload + mention typeahead), as well as future api/* routes. Web form posts (which do not
        // expectJson) still get the redirect-back-with-errors behaviour.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->expectsJson() || $request->is('api/*'),
        );
    })->create();
