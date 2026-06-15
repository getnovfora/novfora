<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Clubs;

use App\Models\User;
use App\Permissions\Scope;
use App\Settings\Settings;

/**
 * The "who may create a club?" gate (Phase 4 · M1.1, made configurable in M1.6) — the single call site for the
 * controller + Livewire surfaces. Setting-driven (`clubs.creation_policy`):
 *   • any   — any verified member;
 *   • trust — a verified member at trust level ≥ `clubs.creation_min_trust_level` (default 2);
 *   • staff — administrators & moderators only.
 * Global staff may ALWAYS create, regardless of policy. The password/registration flow is untouched.
 */
class ClubCreation
{
    public const DEFAULT_MIN_TRUST_LEVEL = 2;

    public function canCreate(?User $user): bool
    {
        if (! $user || ! $user->exists || ! $user->hasVerifiedEmail()) {
            return false;
        }

        if ($user->isStaff()) {
            return true; // staff may always create clubs
        }

        $settings = app(Settings::class);

        $policyOk = match ($settings->string('clubs.creation_policy') ?: 'trust') {
            'any' => true,
            'staff' => false, // only staff (handled above)
            default => $user->trustLevel() >= max(0, (int) $settings->int('clubs.creation_min_trust_level')),
        };

        if (! $policyOk) {
            return false;
        }

        // Paid-clubs hook (Phase 4 · M5.4) — money-fenced. When enabled, creation ALSO requires the
        // `tier.create_clubs` membership perk, granted through the engine by an active subscription (manual or
        // Stripe — no new money path here). Off by default, so the baseline behaviour is unchanged.
        if ($settings->bool('clubs.require_membership')) {
            return $user->canDo('tier.create_clubs', Scope::global());
        }

        return true;
    }
}
