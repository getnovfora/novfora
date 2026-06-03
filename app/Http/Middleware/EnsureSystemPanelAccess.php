<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Permissions\Scope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the System admin panels on the `admin.access` permission, resolved through the permission-mask
 * engine at global scope (ADR-0006), deny-by-default. Pairs with the upstream `auth` middleware, which
 * redirects unauthenticated visitors to login before this runs.
 */
class EnsureSystemPanelAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User && $user->canDo('admin.access', Scope::global()),
            403,
            'You do not have admin access.',
        );

        return $next($request);
    }
}
