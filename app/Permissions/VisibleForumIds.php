<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Permissions;

use App\Models\Club;
use App\Models\ClubMembership;
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

        // Empty universe (no forum rows — e.g. all soft-deleted) must resolve to sees-NONE, not collapse into
        // the sees-all sentinel below (count([]) === count([]) would wrongly return null and drop every filter).
        if ($forums->isEmpty()) {
            return self::$memo[$key] = [];
        }

        // Club content visibility (Phase 4 · M1.5): forum.view alone cannot hide a private club from a
        // logged-in non-member (the board grants forum.view at global to everyone; ADR-0047), so club forums
        // are additionally gated here — visible iff the club is public, the viewer is an active member, or the
        // viewer is global staff. Two upfront queries (no per-forum N+1); null = no club restriction.
        $clubIds = $forums->pluck('club_id')->filter()->unique();
        $visibleClubIds = null;
        if ($clubIds->isNotEmpty() && ! ($viewer->exists && $viewer->isStaff())) {
            $publicIds = Club::query()->whereIn('id', $clubIds)->where('privacy', 'public')->pluck('id');
            $memberIds = $viewer->exists ? ClubMembership::activeClubIdsFor($viewer) : [];
            $visibleClubIds = $publicIds->map(fn ($id): int => (int) $id)->merge($memberIds)->unique()->all();
        }

        $visible = [];
        foreach ($forums as $forum) {
            if (! $resolver->can($viewer, 'forum.view', $forum->permissionScope())) {
                continue;
            }
            if ($forum->club_id !== null && $visibleClubIds !== null
                && ! in_array((int) $forum->club_id, $visibleClubIds, true)) {
                continue; // a club forum whose content the viewer may not see
            }
            $visible[] = (int) $forum->getKey();
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
