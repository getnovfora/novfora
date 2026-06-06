<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Install\Installer;
use Closure;
use Illuminate\Http\Request;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * Until Hearth is installed, send every browser request to the web installer (M5). This is what makes a
 * no-SSH upload "just work": the operator visits the site and is taken straight to the wizard.
 *
 * A short allowlist keeps the wizard itself reachable — the installer pages, Livewire's update endpoint
 * (the wizard is a Livewire component), built assets, and the health endpoints (so uptime monitoring
 * works even pre-install). Disabled once installed, and in environments that opt out (the test suite),
 * via {@see Installer::shouldEnforce()}.
 *
 * Livewire 4 serves its update/asset endpoints under a per-install HASHED prefix — `/livewire-<hash>/...`
 * where the hash derives from APP_KEY ({@see EndpointResolver::prefix()}).
 * A bare `livewire/*` pattern therefore does NOT match `livewire-<hash>/update`, so the wizard's own AJAX
 * POST would fall through the allowlist and be redirected to /install — breaking the browser install (RH-7).
 * We allow the hashed shape two ways: a hash-agnostic static pattern (`livewire-*` spanning the slash) AND
 * the live update path derived from Livewire at runtime, so it stays correct if the hash/route ever changes.
 */
final class RedirectIfNotInstalled
{
    /**
     * Path patterns reachable before install. `livewire/*` covers a custom un-hashed update route;
     * `livewire-*` (matched across the slash) covers Livewire 4's hashed endpoints — `livewire-<hash>/update`
     * and the `livewire.js` asset.
     */
    private const ALLOW = ['install', 'install/*', 'livewire/*', 'livewire-*/*', 'build/*', 'vendor/*', 'up', 'health', 'favicon.ico'];

    public function __construct(private readonly Installer $installer) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->installer->shouldEnforce()) {
            return $next($request);
        }

        $allow = self::ALLOW;
        if (($livewireUpdate = $this->livewireUpdatePath()) !== '') {
            $allow[] = $livewireUpdate;
        }

        if ($request->is(...$allow)) {
            return $next($request);
        }

        return redirect()->route('install');
    }

    /**
     * Livewire's actual update path, hash-agnostic and derived at runtime, with no leading slash so it
     * matches {@see Request::is()}. Empty string when it can't be resolved (e.g. routes not yet booted) —
     * the static `livewire-*` pattern still covers the hashed endpoint, and an empty pattern is never
     * passed to `is()` (it would spuriously match the site root).
     */
    private function livewireUpdatePath(): string
    {
        try {
            return ltrim((string) app('livewire')->getUpdateUri(), '/');
        } catch (\Throwable) {
            return '';
        }
    }
}
