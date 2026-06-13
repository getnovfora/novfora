<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Api\ApiTokenService;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a REST API request by its bearer token (ADR-0033). A valid token resolves to its owning user
 * and is set as the request's authenticated user — so EVERY downstream check runs through the existing
 * permission engine (PermissionResolver / policies / services) on that user's behalf, and the API can never
 * exceed what the user could do in the web UI. An invalid / expired / inactive-owner token gets a clean JSON
 * 401, never a leak. There is no session and no CSRF here — the token IS the auth.
 */
final class AuthenticateApiToken
{
    public function __construct(private readonly ApiTokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->tokens->resolve((string) $request->bearerToken());
        if ($token === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $token->forceFill(['last_used_at' => now()])->saveQuietly();

        $user = $token->user;
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        auth()->setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
