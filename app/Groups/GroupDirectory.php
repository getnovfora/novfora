<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Groups;

use App\Models\Group;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The public Groups directory query (ACP v3 · v3-e, ADR-0083). The SINGLE source of truth for "which groups
 * are visible on the public /groups page" — used by the controller, the public-nav link gate, and the tests.
 *
 * PRIVACY: only groups an admin has explicitly flagged public (`is_public`, OFF by default) are ever listed.
 * A hidden group never appears, and only the aggregate member COUNT is exposed — never the roster/membership
 * of any group (a non-member can't enumerate who belongs to a group from this surface).
 */
final class GroupDirectory
{
    /** Cache flag for `isEnabled()` so the public-nav gate costs no per-render DB query (mirrors the cheap
     *  settings-backed `MembersDirectory` gate). Busted by GroupManager whenever a group's `is_public` may change. */
    private const ENABLED_KEY = 'novfora.groups.public_directory_enabled';

    /** @return Collection<int, Group> public groups, with `users_count`, ordered for display (rank then name). */
    public static function publicGroups(): Collection
    {
        return Group::query()
            ->where('is_public', true)
            ->withCount('users')
            ->orderByDesc('priority')
            ->orderBy('name')
            ->get();
    }

    /**
     * Whether the directory has anything to show (gates the public-nav link so it never points at an empty page).
     * Cached: the public-nav link renders on EVERY page (twice — mobile + desktop), so a raw EXISTS query here
     * would add per-render DB load and blow the page query budgets. The flag changes only when an admin toggles a
     * group's `is_public` (rare), so it is cached with a long TTL and busted by GroupManager on group writes.
     */
    public static function isEnabled(): bool
    {
        // Defensive (mirrors Settings::all() / AclVersion): this renders in the shared layout on EVERY page, so
        // it must degrade gracefully when the DB/cache isn't ready — pre-install, mid-migration, or a minimal
        // render context with no `groups` table. On any failure, report "no public directory" and don't cache it.
        try {
            return (bool) Cache::remember(
                self::ENABLED_KEY,
                now()->addHours(6),
                fn (): bool => Group::query()->where('is_public', true)->exists(),
            );
        } catch (\Throwable) {
            return false;
        }
    }

    /** Invalidate the cached `isEnabled()` flag (called by GroupManager after any group create/update/delete). */
    public static function forgetEnabled(): void
    {
        Cache::forget(self::ENABLED_KEY);
    }
}
