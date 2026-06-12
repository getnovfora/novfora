<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stamps users.last_active_at for the "online" heuristic (P2-M3), throttled to at most one write per user
 * per 5 minutes. A raw DB update (no model hydration → no observers/events fire), so it stays off the hot
 * path. The 15-minute online window (User::isOnline) is wider than this 5-minute write throttle, so a user
 * never flickers offline between throttled writes.
 */
final class ThrottledLastActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $last = $user->last_active_at;
            if ($last === null || Carbon::parse($last)->lt(now()->subMinutes(5))) {
                DB::table('users')->where('id', $user->getKey())->update(['last_active_at' => now()]);
            }
        }

        return $next($request);
    }
}
