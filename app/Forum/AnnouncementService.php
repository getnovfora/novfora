<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Forum;

use App\Models\AnnouncementDismissal;
use App\Models\Topic;
use App\Models\User;
use App\Permissions\VisibleForumIds;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Announcements (U4, NOV-102) — the read/dismiss side of the `announcement` topic type. Every announcement
 * must clear THREE independent fences before it reaches a viewer; a miss on ANY of them hides it:
 *   1. audience  ({@see isVisibleTo}) — null audience = everyone; else the viewer must share a target group.
 *   2. forum     (VisibleForumIds)    — never leak a staff/club-forum announcement to someone who can't see it.
 *   3. lifecycle                      — approved, not expired, and not already dismissed by this viewer.
 * Fences 1 and 2 are the load-bearing pair: a targeted announcement must never render for a user outside its
 * audience, nor for anyone without access to its forum. Reads are bounded — the candidate scan is capped
 * before the in-PHP audience fence, so a pathological number of live announcements can never turn the
 * every-page banner query into an unbounded scan.
 */
final class AnnouncementService
{
    /** A viewer sees at most this many announcements at once (most prominent — pinned, then newest — first). */
    private const MAX = 5;

    /** Candidate-scan cap applied in SQL before the in-PHP audience fence — the query's hard upper bound. */
    private const SCAN = 50;

    /** Cache key for the hot-path existence gate. Forgotten by Topic's model hooks on any announcement mutation. */
    public const LIVE_FLAG = 'announcements:any-live';

    /** @return Collection<int, Topic> the announcements this viewer is permitted to see, most prominent first. */
    public function activeFor(?User $user): Collection
    {
        // Hot-path gate: the banner renders on EVERY page, but the fenced query below must not tax the common
        // case of a forum with no live announcements. A cached existence flag (forgotten on any announcement
        // mutation; self-healing on a short TTL so an expiry can't strand a stale `true`) short-circuits to an
        // empty result without touching the announcement query at all.
        if (! $this->anyLive()) {
            return collect();
        }

        $visibleForumIds = VisibleForumIds::for($user ?? User::guest());

        $candidates = Topic::query()
            ->announcements()
            ->where('approved_state', 'approved')
            ->where(fn ($q) => $q->whereNull('announcement_expires_at')->orWhere('announcement_expires_at', '>', now()))
            ->when($visibleForumIds !== null, fn ($q) => $q->whereIn('forum_id', $visibleForumIds))
            ->when($user !== null, fn ($q) => $q->whereNotExists(function ($sub) use ($user) {
                $sub->select(DB::raw('1'))->from('announcement_dismissals')
                    ->whereColumn('announcement_dismissals.topic_id', 'topics.id')
                    ->where('announcement_dismissals.user_id', $user->getKey());
            }))
            ->with('forum')
            ->orderByDesc('is_pinned')->latest()
            ->limit(self::SCAN)
            ->get();

        return $candidates
            ->filter(fn (Topic $topic) => $this->isVisibleTo($topic, $user))
            ->take(self::MAX)
            ->values();
    }

    /**
     * Are there ANY live (approved, unexpired) announcements at all? Cheap, cached, audience-agnostic gate.
     * Renders on EVERY page, so it must degrade gracefully: if the DB isn't queryable yet (mid-install, a
     * schema-less view-render test, a transient outage), treat it as "none" rather than throwing a
     * ViewException that 500s the whole page — the same fail-soft contract the cache/notifier layers use.
     */
    private function anyLive(): bool
    {
        try {
            return (bool) Cache::remember(self::LIVE_FLAG, now()->addSeconds(60), fn () => Topic::query()
                ->announcements()
                ->where('approved_state', 'approved')
                ->where(fn ($q) => $q->whereNull('announcement_expires_at')->orWhere('announcement_expires_at', '>', now()))
                ->exists());
        } catch (\Throwable) {
            return false; // DB not ready / table absent → no announcements; never break the render
        }
    }

    /** The audience fence: null/empty audience = everyone; else the viewer must belong to a targeted group. */
    public function isVisibleTo(Topic $topic, ?User $user): bool
    {
        $audience = is_array($topic->announcement_audience) ? ($topic->announcement_audience['groups'] ?? null) : null;

        if (empty($audience)) {
            return true; // untargeted → everyone, including guests
        }

        if (! $user instanceof User) {
            return false; // a targeted announcement is never shown to a guest
        }

        $groupIds = $user->groups->pluck('id')->map(fn ($id) => (int) $id)->all();

        return array_intersect(array_map('intval', $audience), $groupIds) !== [];
    }

    /** Idempotently record that a user dismissed an announcement (a no-op for a non-announcement topic). */
    public function dismiss(User $user, Topic $topic): void
    {
        if (! $topic->isAnnouncement()) {
            return;
        }

        AnnouncementDismissal::query()->firstOrCreate(
            ['user_id' => $user->getKey(), 'topic_id' => $topic->getKey()],
            ['dismissed_at' => now()],
        );
    }
}
