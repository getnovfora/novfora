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
 * 2FA is MANDATORY for staff — admins & moderators (the brief's "Must"). A staff member without a
 * CONFIRMED authenticator is bounced to the 2FA setup page (which stays reachable so they can comply).
 * General users are unaffected — opt-in 2FA is a Phase 2 "Should". Apply this to privileged routes;
 * the permission engine still enforces authorization independently — this only hard-requires the
 * second factor before staff may use those privileges.
 *
 * ACP v3 · v3-a (ADR-0080): "staff" for this gate is anyone who can REACH the admin panel, not just the
 * admins/moderators GROUPS. A bundle-restricted admin holds admin.access as a per-user grant but is NOT in a
 * staff group (isStaff() === false); they are an admin in capability and so MUST carry the second factor too —
 * security-by-default. (isStaff() short-circuits first, so the resolver check only runs for the rarer
 * non-group-staff panel holder, and on admin routes the verdict is already warm from EnsureSystemPanelAccess.)
 */
class RequireTwoFactorForStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $privileged = $user instanceof User
            && ($user->isStaff() || $user->canDo('admin.access', Scope::global()));

        if ($privileged && $user->two_factor_confirmed_at === null) {
            return redirect()->route('settings.two-factor')->with('status', 'two-factor-required');
        }

        return $next($request);
    }
}
