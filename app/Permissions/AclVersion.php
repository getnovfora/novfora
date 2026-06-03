<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Permissions;

use Illuminate\Support\Facades\Cache;

/**
 * A global ACL version counter (security §1.3). Resolved-permission caches are keyed by this; any
 * group/role/ACL change bumps it (event-driven), invalidating all stale resolved sets at once.
 * If the cache is unavailable the version simply reads as 1 — correctness never depends on it.
 */
final class AclVersion
{
    private const KEY = 'hearth.acl.version';

    public function current(): int
    {
        try {
            return (int) (Cache::get(self::KEY) ?? 1);
        } catch (\Throwable) {
            return 1;
        }
    }

    public function bump(): int
    {
        $next = $this->current() + 1;
        try {
            Cache::forever(self::KEY, $next);
        } catch (\Throwable) {
            // graceful: a missing cache just means resolved sets aren't cached, not incorrect.
        }

        return $next;
    }
}
