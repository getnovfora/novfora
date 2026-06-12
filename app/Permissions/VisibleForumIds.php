<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Permissions;

use App\Models\Forum;
use App\Models\User;

/**
 * The flat set of forum ids a viewer may see, for query-level permission filtering (P2-M3 activity feed; the
 * forward seam for M4 search facets). Generalises ForumController's per-row `$viewer->canDo('forum.view', …)`
 * check into one array, reusing PermissionResolver — whose request-scoped memo + 30-minute cache make the
 * per-forum checks cheap on a warm request (no N+1 on the hot path).
 *
 * Returns NULL when the viewer can see EVERY forum — the "no restriction" sentinel — so the consumer omits
 * the WHERE filter entirely rather than build a forum-wide IN list. An EMPTY array means the viewer can see
 * no forum at all; the consumer must then short-circuit to an empty result and NEVER run an `IN ()` on it.
 */
final class VisibleForumIds
{
    /** @var array<int, list<int>|null> per-request memo keyed by viewer id (0 = guest); null = no restriction */
    private static array $memo = [];

    /** @return list<int>|null  null = no restriction (sees all); [] = sees none; otherwise the visible ids */
    public static function for(User $viewer): ?array
    {
        $key = (int) ($viewer->getKey() ?? 0);
        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }

        $resolver = app(PermissionResolver::class);

        // Every forum/category node — each node's own scope is checked against forum.view. One query; the
        // per-node can() calls resolve through the memo + ACL cache (0 DB on a warm request).
        $forums = Forum::query()->get();

        $visible = [];
        foreach ($forums as $forum) {
            if ($resolver->can($viewer, 'forum.view', $forum->permissionScope())) {
                $visible[] = (int) $forum->getKey();
            }
        }

        // Sees every forum → no-restriction sentinel (null) instead of a full IN list; else the visible set.
        $result = count($visible) === $forums->count() ? null : $visible;

        return self::$memo[$key] = $result;
    }

    /** Clear the per-request memo (tests, or after a mid-request permission change). */
    public static function flush(): void
    {
        self::$memo = [];
    }
}
