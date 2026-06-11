<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security response headers (security §4). Adds a Content-Security-Policy plus the standard
 * hardening headers (nosniff, X-Frame-Options, Referrer-Policy, Permissions-Policy) to every web
 * response, and HSTS on TLS. All values come from config/novfora.php 'security' and the whole thing is
 * toggleable, so an operator  the policy without editing code.
 *
 * The default CSP is deliberately NON-BREAKING: script/style keep 'unsafe-inline'/'unsafe-eval' because
 * Livewire + Alpine + the inline-styled core views + JSON-LD need them today. It still removes the
 * high-value sinks — plugin objects (object-src 'none'), <base> hijacking (base-uri 'self'), and
 * cross-origin framing/form-posting (frame-ancestors/form-action 'self'). A strict nonce-based CSP is
 * the documented follow-up (docs/SECURITY-REVIEW.md).
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Strict (nonce-based) CSP is opt-in (phase-1.5 F-M3). Generate the per-request nonce BEFORE the view
        // renders so @vite, Livewire (both read Vite::cspNonce()), and the nonced inline scripts share it.
        $strict = config('novfora.security.csp.enabled', true) && config('novfora.security.csp.strict', false);
        if ($strict) {
            Vite::useCspNonce();
        }

        $response = $next($request);

        if (! config('novfora.security.headers.enabled', true)) {
            return $response;
        }

        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'browsing-topics=(), geolocation=(), microphone=(), camera=()');

        if (config('novfora.security.csp.enabled', true)) {
            $policy = $strict
                ? str_replace('{nonce}', (string) Vite::cspNonce(), trim((string) config('novfora.security.csp.strict_policy', '')))
                : trim((string) config('novfora.security.csp.policy', ''));
            // Set only when a route hasn't already declared its own policy (allows per-route overrides).
            if ($policy !== '' && ! $headers->has('Content-Security-Policy')) {
                $headers->set('Content-Security-Policy', $policy);
            }
        }

        // HSTS only over TLS — never over plain http (ignored by browsers there, and emitting it could
        // strand a non-TLS baseline host that later can't serve https).
        $maxAge = (int) config('novfora.security.headers.hsts_max_age', 0);
        if ($maxAge > 0 && $request->isSecure()) {
            $headers->set('Strict-Transport-Security', "max-age={$maxAge}; includeSubDomains");
        }

        return $response;
    }
}
