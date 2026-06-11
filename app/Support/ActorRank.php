<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

/**
 * Actor-vs-target rank check for staff moderation actions (phase-1.5 F-F). A staff member must not be able
 * to ban / warn / spam-clean a target of equal-or-higher rank: a moderator cannot action an admin (or, by
 * default, another moderator). Admins outrank everyone. "Equal rank may act" is configurable
 * (`novfora.moderation.rank.allow_equal`). This is an EXTRA gate layered on top of the `bans.manage`
 * permission — it does not replace it.
 */
final class ActorRank
{
    public static function canActOn(User $actor, User $target): bool
    {
        if ($actor->isAdmin()) {
            return true; // admins can action anyone, including other admins
        }

        return (bool) config('novfora.moderation.rank.allow_equal', false)
            ? $actor->rankPriority() >= $target->rankPriority()
            : $actor->rankPriority() > $target->rankPriority();
    }
}
