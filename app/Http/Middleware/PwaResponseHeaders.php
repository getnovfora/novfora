<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks a response as safe for the service worker to cache for offline read (Phase 4 · M3.1). The header is set
 * ONLY for GET, 200, GUEST responses on public content paths — so an authenticated/personal page (which may
 * carry PII) NEVER gets the flag and is therefore NEVER stored by the SW. This is the server-authoritative half
 * of the "never cache PII" guarantee; the SW only caches a navigation when it sees `X-PWA-Cacheable: 1`.
 */
class PwaResponseHeaders
{
    /** Path prefixes never marked cacheable even for guests (auth surfaces, machine endpoints, the installer). */
    private const NEVER = [
        'login', 'register', 'forgot-password', 'reset-password', 'two-factor', 'email', 'auth', 'install',
        'api', 'sitemap.xml', 'robots.txt', 'health', 'webhooks', 'unsubscribe', 'manifest.webmanifest', 'sw.js',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->isCacheable($request, $response)) {
            $response->headers->set('X-PWA-Cacheable', '1');
        }

        return $response;
    }

    private function isCacheable(Request $request, Response $response): bool
    {
        if (! $request->isMethod('GET') || $request->user() !== null || $response->getStatusCode() !== 200) {
            return false; // only guest GET 200 — an authenticated page may carry PII, so never flag it
        }

        $path = trim($request->path(), '/');
        foreach (self::NEVER as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return false;
            }
        }

        return true;
    }
}
