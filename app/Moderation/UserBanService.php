<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Moderation;

use App\Models\Ban;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The single source of truth for issuing / lifting a USER ban (security §3). Extracted from BanController so the
 * front-end mod CP (the bans page) and the ACP v4 per-member admin view (A2) share ONE ban code path — same
 * Ban row, same `users.status = banned/active` flip, same audit trail. The caller is responsible for the
 * authorization gate (bans.manage) + the rank guard + the no-self guard.
 *
 * This is the CHOKEPOINT every effective user ban flows through, so the OWNER-STRAND backstop (apex, ADR-0100)
 * lives here as an actor-independent guard: {@see OwnerStrandGuard::assertBanWontStrandOwnerTier} runs as the
 * FIRST act inside the ban transaction (the TOCTOU-safe locked re-read), so no caller — the bans page, the ACP
 * member view, or any future one — can ban the sole reachable owner and strand the panel. The throw rolls the
 * whole ban back; callers catch {@see OwnerStrandException} and surface it.
 */
final class UserBanService
{
    public function __construct(private readonly OwnerStrandGuard $ownerGuard) {}

    /** @throws OwnerStrandException when $target is the last reachable administrator / co-owner */
    public function ban(User $target, ?string $reason = null, ?Carbon $expiresAt = null): Ban
    {
        return DB::transaction(function () use ($target, $reason, $expiresAt): Ban {
            // Owner-strand backstop (ADR-0100), the FIRST act under the transaction — refuse + roll back before
            // stranding the sole owner; the Ban row + status flip then commit atomically with the locked re-read.
            $this->ownerGuard->assertBanWontStrandOwnerTier($target);

            $ban = Ban::create([
                'user_id' => $target->getKey(),
                'type' => 'user',
                'value' => null,
                'scope_type' => 'global',
                'reason' => $reason,
                'expires_at' => $expiresAt,
            ]);

            User::whereKey($target->getKey())->update(['status' => 'banned']);
            Audit::log('ban.created', $ban, ['type' => 'user']);

            return $ban;
        });
    }

    /** Lift any ban; for a user ban this also restores the account from `banned` → `active`. */
    public function lift(Ban $ban): void
    {
        if ($ban->type === 'user' && $ban->user_id) {
            User::whereKey($ban->user_id)->where('status', 'banned')->update(['status' => 'active']);
        }

        $ban->delete();
        Audit::log('ban.lifted', $ban);
    }

    /** The current live (unexpired) global user-ban for a member, if any. */
    public function activeBan(User $target): ?Ban
    {
        return Ban::query()
            ->where('user_id', $target->getKey())
            ->where('type', 'user')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest('id')
            ->first();
    }
}
