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
        $next = $this->current() + 1;

        try {
            Cache::forever(self::KEY, $next);
        } catch (\Throwable) {
            // graceful: an unavailable cache just means the feed isn't cached.
        }

        return $next;
    }
}
