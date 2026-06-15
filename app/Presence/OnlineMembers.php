<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Presence;

use App\Models\Club;
use App\Models\ClubMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The single source of truth for "who's online" (Phase 4 · M4.3). BASELINE-SAFE by construction: it reads
 * the existing `last_active_at` heuristic (stamped by ThrottledLastActive) — no websocket/presence daemon
 * required — and every surface (the theme widget, the live Livewire widget, the club page) reads it, so the
 * privacy rule is enforced in ONE place.
 *
 * PRIVACY (opt-in, security-by-default): a member appears ONLY if they have opted in (`show_online_status`),
 * are `active`, and were active inside the recent window. The default is invisible. Club presence is further
 * restricted to active members of that club, so a non-member can never enumerate a private club's roster.
 */
final class OnlineMembers
{
    /** The "recently active" window, in minutes. Mirrors User::isOnline()'s 15-minute heuristic by default. */
    public function windowMinutes(): int
    {
        return max(1, (int) config('novfora.presence.window_minutes', 15));
    }

    /**
     * @param  int|null  $minutes  override the recent window (the theme widget passes its configured value)
     * @return Collection<int, User> opted-in, active, recently-active members (most-recent first).
     */
    public function recent(int $limit = 30, ?int $minutes = null): Collection
    {
        return $this->baseQuery($minutes)
            ->orderByDesc('last_active_at')
            ->limit($limit)
            ->get(['id', 'username', 'last_active_at']);
    }

    /** The opted-in online count (for the widget header / badge). */
    public function count(): int
    {
        return $this->baseQuery()->count();
    }

    /**
     * Online members of a specific club: the {@see recent()} rule intersected with that club's ACTIVE
     * roster. The caller is responsible for gating WHO may call this (the club page already enforces
     * Club::isContentVisibleTo); this method never reveals a non-member, and an opted-out member never appears.
     *
     * @return Collection<int, User>
     */
    public function inClub(Club $club, int $limit = 30): Collection
    {
        $memberIds = ClubMembership::query()
            ->where('club_id', $club->getKey())
            ->where('status', 'active')
            ->pluck('user_id');

        if ($memberIds->isEmpty()) {
            return collect();
        }

        return $this->baseQuery()
            ->whereIn('id', $memberIds)
            ->orderByDesc('last_active_at')
            ->limit($limit)
            ->get(['id', 'username', 'last_active_at']);
    }

    /**
     * The shared opt-in + active + recent filter.
     *
     * @return Builder<User>
     */
    private function baseQuery(?int $minutes = null): Builder
    {
        $window = max(1, $minutes ?? $this->windowMinutes());

        return User::query()
            ->where('status', 'active')
            ->where('show_online_status', true)
            ->where('last_active_at', '>=', now()->subMinutes($window));
    }
}
