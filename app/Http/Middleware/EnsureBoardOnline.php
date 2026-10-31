<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Settings\BoardState;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * The "board offline" switch (ACP v1, General settings). When an admin takes the board offline, guests and
 * regular members see a branded maintenance-style notice (HTTP 503 so monitors know), while ADMINS pass
 * straight through so they can keep working and flip the switch back. Auth, the installer, /health, the
 * ACP, and Livewire's endpoints stay reachable so an admin can always sign in and manage the site.
 *
 * NF-07 — the same gate on every way a member can act, not just page loads:
 *   • Livewire: the update endpoint itself is allowlisted, but this middleware is registered as Livewire
 *     PERSISTENT middleware, so Livewire re-runs it for every component update against the component's
 *     ORIGINATING route (its checksummed snapshot memo). A composer opened on a topic page before the switch
 *     flipped is refused; components on allowlisted pages (sign-in, the ACP) keep working. Livewire ignores a
 *     non-redirect response from persistent middleware, so here the refusal is thrown, not returned.
 *   • REST API: it runs after token auth (so an admin token passes) and refuses with a JSON 503.
 *   • Scheduled replies: PostScheduler defers them while offline (see BoardState).
 *
 * The decision is one O(cache-read) settings lookup; pre-install it is never reached (RedirectIfNotInstalled
 * short-circuits to the wizard first).
 */
class EnsureBoardOnline
{
    public function handle(Request $request, Closure $next): Response
    {
        $board = app(BoardState::class);

        if (! $board->isOffline()) {
            return $next($request);
        }

        // Always-reachable surfaces: health, the installer, auth, the admin panel, and Livewire's update/
        // asset endpoints (hashed prefix included, like RedirectIfNotInstalled) so those pages keep working.
        if ($this->isAllowlisted($request)) {
            return $next($request);
        }

        // Admins (anywhere) pass — they manage the site and lift the switch.
        if ($board->allows($request->user() instanceof User ? $request->user() : null)) {
            return $next($request);
        }

        if ($request->is('api/*')) {
            abort(503, $board->message()); // JSON for api/*, enveloped for api/admin/*
        }

        $offline = response()->view('maintenance.offline', ['message' => $board->message()], 503);

        // Re-applied by Livewire to a component update (persistent middleware): only a thrown response stops it.
        if (app(HandleRequests::class)->isLivewireRoute()) {
            throw new HttpResponseException($offline);
        }

        return $offline;
    }

    private function isAllowlisted(Request $request): bool
    {
        if ($request->is('health', 'up', 'install', 'install/*', 'admin', 'admin/*', 'login', 'logout', 'two-factor-challenge', 'forgot-password', 'reset-password', 'reset-password/*', 'livewire/*', 'livewire-*/*')) {
            return true;
        }

        // Cover Livewire's per-install hashed update prefix exactly (derived at runtime; guarded).
        $update = ltrim((string) app('livewire')->getUpdateUri(), '/');

        return $update !== '' && $request->is($update);
    }
}
