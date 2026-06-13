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
    private const KEY = 'novfora.acl.version';

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
        try {
            // Atomic (A6): seed the counter once if absent (Cache::add is SETNX-style), then atomically
            // increment — replacing a read-modify-write (current() + 1, then Cache::forever) whose two
            // concurrent callers could both read N and both write N+1, losing a bump and leaving a stale
            // resolved-permission set cached. Cache::increment is atomic on every store that supports it.
            Cache::add(self::KEY, 1);
            $next = Cache::increment(self::KEY);

            return is_int($next) ? $next : $this->current();
        } catch (\Throwable) {
            // graceful: a missing cache just means resolved sets aren't cached, not incorrect.
            return $this->current() + 1;
        }
    }
}
