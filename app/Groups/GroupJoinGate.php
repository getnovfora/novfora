<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Groups;

use App\Models\User;
use App\Permissions\BanChecker;
use App\Permissions\Scope;

/**
 * The anti-spam / trust floor for SELF-SERVICE group joins (ACP v3 · v3-e, ADR-0083). A user must not be able
 * to bypass new-user / abuse restrictions by self-joining (open) or requesting to join (request) a group — a
 * permissioned group could otherwise become an escalation path for a banned/restricted/unverified account.
 *
 * This is the single source of truth for "may this user self-join a group", used BOTH by GroupMembershipService
 * (enforcement) and by the public Groups page / join button (whether to show the Join control + why not). The
 * floor: signed in, email verified, account `active` (not pending/suspended/banned), and not under a global ban
 * (defence-in-depth — the status check already catches a `banned` status, the BanChecker also catches scoped
 * bans on the global chain). Admin-assigned membership and system auto-promotion do NOT pass through this gate;
 * it governs only what a user can initiate themselves.
 */
final class GroupJoinGate
{
    /** A human reason the user may not self-join, or null when they may. */
    public static function reasonBlocked(User $user): ?string
    {
        if (! $user->exists) {
            return 'You must be signed in to join a group.';
        }
        if (! $user->hasVerifiedEmail()) {
            return 'Verify your email address before joining a group.';
        }
        if (((string) ($user->status ?? 'active')) !== 'active') {
            return 'Your account is not eligible to join groups right now.';
        }
        if (app(BanChecker::class)->isBanned($user, Scope::global())) {
            return 'Your account is not eligible to join groups right now.';
        }

        return null;
    }

    public static function allows(User $user): bool
    {
        return self::reasonBlocked($user) === null;
    }
}
