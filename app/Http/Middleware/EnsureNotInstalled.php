<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Install\Installer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The installer lock (M5, security-critical). Guards every installer route: once Hearth is installed the
 * unauthenticated installer is sealed — a re-visit gets a hard 403, never a chance to re-run migrations,
 * rewrite `.env`, or create another admin. The only way to re-open the installer is a deliberate
 * filesystem action on the host (removing the marker via the CLI), which already implies shell trust.
 */
final class EnsureNotInstalled
{
    public function __construct(private readonly Installer $installer) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_if($this->installer->isInstalled(), 403, 'Hearth is already installed.');

        return $next($request);
    }
}
