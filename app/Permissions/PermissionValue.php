<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Permissions;

/**
 * The three-state permission value (security §1.1).
 *  - ALLOW (+1): a positive grant.
 *  - NO    ( 0): NEUTRAL / unset — ALLOW beats it; a more-specific or higher-priority ALLOW lifts it.
 *                It does NOT hard-deny (to remove a group's grant from a user, use NEVER, not NO).
 *  - NEVER (-1): absolute — no ALLOW anywhere can override it.
 */
enum PermissionValue: int
{
    case Allow = 1;
    case No = 0;
    case Never = -1;
}
