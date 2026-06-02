<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the System admin panels. M0: local/testing only (no auth exists yet). M1 replaces this with
 * a real admin permission-mask check. Secure-by-default: denied in production until then.
 */
class EnsureSystemPanelAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            app()->environment('local', 'testing'),
            403,
            'The system panel requires admin access (authorization lands in M1).',
        );

        return $next($request);
    }
}
