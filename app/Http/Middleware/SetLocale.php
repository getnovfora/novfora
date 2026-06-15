<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve the active UI locale for the request and hand it to the app.
 *
 * Precedence (first supported wins): the signed-in member's stored preference, then the session value the
 * language switcher writes, then the configured default. EVERY candidate is checked against the allowlist
 * (Locales::isSupported) before it can reach App::setLocale — an unknown/forged code is silently skipped,
 * never passed through. This is the only middleware that sets the locale on the web path.
 */
final class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $candidates = [];

        $user = $request->user();
        if ($user !== null && is_string($user->locale ?? null)) {
            $candidates[] = $user->locale;
        }

        $session = $request->session()->get('locale');
        if (is_string($session)) {
            $candidates[] = $session;
        }

        $locale = Locales::default();
        foreach ($candidates as $candidate) {
            if (Locales::isSupported($candidate)) {
                $locale = $candidate;
                break;
            }
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
