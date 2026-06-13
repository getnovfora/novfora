<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Community;

use App\Models\User;
use App\Settings\Settings;

/**
 * The single source of truth for who may see the public members directory (/members). Admins control the
 * tier from the panel (Admin → Members → Directory); this helper resolves the setting and is consulted by
 * the route gate, the directory component's self-guard, AND the header nav link — so the three can never
 * drift. The tiers nest: everyone ⊃ members ⊃ staff ⊃ (disabled = nobody).
 */
final class MembersDirectory
{
    public const MODES = ['disabled', 'staff', 'members', 'everyone'];

    public static function mode(): string
    {
        $mode = app(Settings::class)->string('members.directory_visibility');

        return in_array($mode, self::MODES, true) ? $mode : 'everyone';
    }

    public static function visibleTo(?User $user): bool
    {
        return match (self::mode()) {
            'everyone' => true,
            'members' => $user instanceof User,
            'staff' => $user instanceof User && $user->isStaff(),
            default => false, // disabled
        };
    }
}
