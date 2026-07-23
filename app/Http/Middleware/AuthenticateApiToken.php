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
            abort(401, 'Unauthenticated.'); // rendered as JSON for api/* (enveloped for api/admin/*)
        }

        // ip_allowlist (E1): an admin token may be pinned to specific addresses; a request from elsewhere is
        // refused. Member tokens carry no allowlist and skip this. (Exact-IP match, canonicalised via inet_pton;
        // CIDR ranges land once U13's CidrMatcher is on main.)
        $allowlist = $token->ip_allowlist;
        if (is_array($allowlist) && $allowlist !== [] && ! $this->ipAllowed((string) $request->ip(), $allowlist)) {
            abort(403, 'This token may not be used from this address.');
        }

        $user = $token->user;
        if (! $user instanceof User) {
            abort(401, 'Unauthenticated.');
        }

        $this->tokens->markUsed($token); // throttled last_used_at write
        $request->attributes->set('api_token_id', $token->getKey()); // audit provenance (via_token)
        $request->attributes->set('api_token', $token); // for RequireApiScope (no re-query)
        auth()->setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }

    /** Exact-IP allowlist test: the request IP must equal one allowed IP after inet_pton canonicalisation. */
    private function ipAllowed(string $requestIp, array $allowlist): bool
    {
        $reqBin = @inet_pton($requestIp);
        if ($reqBin === false) {
            return false;
        }
        foreach ($allowlist as $allowed) {
            if (is_string($allowed) && @inet_pton($allowed) === $reqBin) {
                return true;
            }
        }

        return false;
    }
}
