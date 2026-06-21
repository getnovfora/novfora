<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cookie;

/**
 * Resolve + persist the Simple/Advanced permission-editor mode (ADR-0089). The choice now survives navigation
 * via a year-long cookie read SERVER-SIDE (so it works with no JS — the switch is plain ?mode= links):
 *   • an explicit `?mode=` in the query wins AND is remembered;
 *   • otherwise the saved cookie decides;
 *   • the default is Simple.
 * This replaces the prior localStorage+redirect dance, which reset to Simple on every fresh visit.
 */
final class PermMode
{
    public const COOKIE = 'novfora_perm_mode';

    /** @return 'simple'|'advanced' */
    public static function resolve(): string
    {
        $requested = request()->query('mode');

        if ($requested !== null) {
            $mode = $requested === 'advanced' ? 'advanced' : 'simple';
            Cookie::queue(self::COOKIE, $mode, 60 * 24 * 365); // remember the explicit choice for a year

            return $mode;
        }

        return request()->cookie(self::COOKIE) === 'advanced' ? 'advanced' : 'simple';
    }
}
