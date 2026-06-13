<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Community;

use Illuminate\Support\Facades\Cache;

/**
 * The activity-feed cache version (mirrors App\Permissions\AclVersion). A single forever-stored integer,
 * bumped on every new Activity, so the version-keyed feed cache entry is simply never read again once a new
 * activity lands. Correctness never depends on the cache — a missing/throwing store just means the feed
 * isn't cached, never that it is wrong.
 */
final class ActivityVersion
{
    private const KEY = 'novfora.activities.version';

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
            // increment. This replaces a read-modify-write (current() + 1, then Cache::forever) whose two
            // concurrent callers could both read N and both write N+1 — losing a bump and serving a stale,
            // version-keyed feed entry to other readers. Cache::increment is atomic on every store that
            // supports it (array/database/redis/memcached).
            Cache::add(self::KEY, 1);
            $next = Cache::increment(self::KEY);

            return is_int($next) ? $next : $this->current();
        } catch (\Throwable) {
            // graceful: an unavailable cache just means the feed isn't cached, never that it is wrong.
            return $this->current() + 1;
        }
    }
}
