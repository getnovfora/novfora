<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Clubs;

use App\Models\User;

/**
 * The "who may create a club?" gate (Phase 4 · M1.1; made fully configurable in M1.6).
 *
 * BASELINE POLICY (conservative default): a verified member at trust level ≥ 2, plus global staff always.
 * M1.6 replaces the body with a setting-driven policy (any / trust-threshold / admin-approved) — this class
 * stays the single call site so the controller/Livewire surfaces never change.
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

        return $user->trustLevel() >= self::DEFAULT_MIN_TRUST_LEVEL;
    }
}
