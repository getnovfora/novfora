<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Install\Installer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Until Hearth is installed, send every browser request to the web installer (M5). This is what makes a
 * no-SSH upload "just work": the operator visits the site and is taken straight to the wizard.
 *
 * A short allowlist keeps the wizard itself reachable — the installer pages, Livewire's update endpoint
 * (the wizard is a Livewire component), built assets, and the health endpoints (so uptime monitoring
 * works even pre-install). Disabled once installed, and in environments that opt out (the test suite),
 * via {@see Installer::shouldEnforce()}.
 */
final class RedirectIfNotInstalled
{
    /** Path patterns reachable before install. */
    private const ALLOW = ['install', 'install/*', 'livewire/*', 'build/*', 'vendor/*', 'up', 'health', 'favicon.ico'];

    public function __construct(private readonly Installer $installer) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->installer->shouldEnforce()) {
            return $next($request);
        }

        if ($request->is(...self::ALLOW)) {
            return $next($request);
        }

        return redirect()->route('install');
    }
}
