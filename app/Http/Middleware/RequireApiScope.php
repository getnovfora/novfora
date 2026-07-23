<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the Admin-API scope half of the trust rule (E1 / NOV-135, ADR-0115). Each admin route declares the
 * scope it needs (`->middleware('api.scope:admin:settings.write')`); this checks the presented token carries it.
 * The OTHER half — the underlying `canDo` capability — is enforced in the controller, so the effective ability is
 * always `token scopes ∩ user canDo`: a token can never do more than its owner, and a scope alone grants nothing.
 * Runs AFTER AuthenticateApiToken (which stashes the resolved token on the request).
 */
final class RequireApiScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $token = $request->attributes->get('api_token');
        if (! $token instanceof ApiToken || ! $token->hasScope($scope)) {
            return response()->json([
                'error' => [
                    'code' => 'insufficient_scope',
                    'message' => 'This token lacks the required scope: '.$scope.'.',
                ],
            ], 403);
        }

        return $next($request);
    }
}
