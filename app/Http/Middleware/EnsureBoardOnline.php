<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Permissions\Scope;
use App\Settings\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The "board offline" switch (ACP v1, General settings). When an admin takes the board offline, guests and
 * regular members see a branded maintenance-style notice (HTTP 503 so monitors know), while ADMINS pass
 * straight through so they can keep working and flip the switch back. Auth, the installer, /health, the
 * ACP, and Livewire's endpoints stay reachable so an admin can always sign in and manage the site.
 *
 * The decision is one O(cache-read) settings lookup; pre-install it is never reached (RedirectIfNotInstalled
 * short-circuits to the wizard first).
 */
class EnsureBoardOnline
{
    public function handle(Request $request, Closure $next): Response
    {
        $settings = app(Settings::class);

        if (! $settings->bool('general.board_offline')) {
            return $next($request);
        }

        // Always-reachable surfaces: health, the installer, auth, the admin panel, and Livewire's update/
        // asset endpoints (hashed prefix included, like RedirectIfNotInstalled) so those pages keep working.
        if ($this->isAllowlisted($request)) {
            return $next($request);
        }

        // Admins (anywhere) pass — they manage the site and lift the switch.
        $user = $request->user();
        if ($user instanceof User && $user->canDo('admin.access', Scope::global())) {
            return $next($request);
        }

        return response()->view('maintenance.offline', [
            'message' => $settings->string('general.board_offline_message'),
        ], 503);
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
