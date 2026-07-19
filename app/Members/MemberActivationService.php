<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Members;

use App\AntiSpam\TrustLevelManager;
use App\Models\Ban;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * The single authority that clears a `pending` (registration-flagged) member back to `active` — the
 * pending-member exit-ramp (U14 / NOV-112, ADR-0119). Activation is anti-spam-sensitive: it LIFTS the
 * `NewUserModeration` condition-A hold (every post no longer auto-held) and unfreezes trust promotion, so it
 * must happen ONLY via an explicit admin action or the vetted, config-gated auto-threshold — never as a
 * silent side effect — and NEVER for a banned/blocked account (an actor-independent guard).
 *
 * "Activate" is exactly: `status pending→active`, one audit row naming the path, and a trust recompute (so a
 * long-time held poster promotes out of TL0 immediately). It never touches `acl_entries` — activation cannot
 * grant a permission, only lift the pending hold. Idempotent: activating an already-active user is a no-op.
 */
final class MemberActivationService
{
    public function __construct(private readonly TrustLevelManager $trust) {}

    /**
     * Activate a pending member. Returns true when it flipped `pending → active`, false on a no-op (already
     * active, or refused because the account is banned/blocked).
     *
     * @param  'manual'|'auto'  $path  which exit ramp triggered this (recorded in the audit row)
     */
    public function activate(User $target, ?User $actor, string $path): bool
    {
        if (! $target->getKey()) {
            return false;
        }

        return (bool) DB::transaction(function () use ($target, $actor, $path): bool {
            // Lock the row so a concurrent ban/activate can't race the status flip.
            $fresh = User::query()->whereKey($target->getKey())->lockForUpdate()->first();
            if (! $fresh instanceof User) {
                return false;
            }

            // Only a pending account is activatable; active/suspended/banned are all no-ops here.
            if (($fresh->status ?? 'active') !== 'pending') {
                return false;
            }

            // ACTOR-INDEPENDENT GUARD: never "activate" a banned/blocked account — not by an admin's slip, not
            // by the auto-threshold. A live global user-ban row is the authority (a banned user's status is
            // 'banned', but guard the ban row too in case the two ever drift).
            if ($this->isBanned($fresh)) {
                return false;
            }

            $fresh->forceFill(['status' => 'active'])->save(); // status is not mass-assignable — forceFill only

            Audit::log('member.activated', $fresh, ['path' => $path, 'by' => $actor?->getKey()]);

            // Promote out of TL0 immediately if earned — the same per-user authority the post-approval path uses.
            $this->trust->recompute($fresh);

            return true;
        });
    }

    /** True when the account is banned/blocked (status or a live global user-ban row). */
    private function isBanned(User $user): bool
    {
        if (in_array($user->status ?? 'active', ['banned', 'blocked'], true)) {
            return true;
        }

        return Ban::query()
            ->where('type', 'user')
            ->where('user_id', $user->getKey())
            ->where('scope_type', 'global')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();
    }
}
