<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * REST API request rate, layer 2 of 2 (NF-06): a per-MEMBER ceiling that runs AFTER AuthenticateApiToken and
 * keys on the token's owner, so a member's allowance is the same from every IP and across all their tokens.
 * (Layer 1, `throttle:api`, is the coarse per-IP ceiling ahead of token auth.)
 *
 * Deliberately NOT Laravel's ThrottleRequests: that class is on the framework's middleware priority list, and
 * the priority sort hoists it ahead of non-priority middleware — i.e. ahead of AuthenticateApiToken, where no
 * user is known yet and every member would share one bucket. A plain middleware keeps its place in the route
 * stack. If it ever ran without a resolved user it keys by IP, never one global bucket.
 */
final class ThrottleApiMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $max = max(1, (int) config('novfora.api.rate_limits.user', 60));
        $id = $request->user()?->getAuthIdentifier();
        $key = 'api-member:'.($id !== null ? 'user:'.$id : 'ip:'.($request->ip() ?? 'unknown'));

        if (RateLimiter::tooManyAttempts($key, $max)) {
            $retryAfter = RateLimiter::availableIn($key);

            throw new ThrottleRequestsException('Too Many Attempts.', null, [
                'Retry-After' => $retryAfter,
                'X-RateLimit-Limit' => $max,
                'X-RateLimit-Remaining' => 0,
            ]);
        }

        RateLimiter::hit($key, 60);
        $response = $next($request);
        $response->headers->set('X-RateLimit-Limit', (string) $max);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, $max - RateLimiter::attempts($key)));

        return $response;
    }
}
