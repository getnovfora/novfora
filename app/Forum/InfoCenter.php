<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Forum;

use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use App\Presence\OnlineMembers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The board-index "Info Center" read-model (ADR-0077): the classic phpBB/SMF statistics panel + the opt-in
 * who's-online panel that sit above the recent-activity feed.
 *
 * RH-9 CACHE DISCIPLINE (mirrors App\Community\ActivityFeed): the cache holds PRIMITIVES ONLY — the
 * board-wide aggregate counts plus the newest member's id (int|null) — under one 60s key. The newest member
 * is rehydrated with User::find() strictly AFTER the cache boundary, so no Eloquent model is ever serialised
 * into the store (which would 500 on a serialising host's cache hit). The reuse of App\Theme\Widgets\ForumStatsWidget's
 * count shape keeps the figures consistent with the existing board-statistics widget.
 *
 * PRIVACY: every statistic is an aggregate count (no post content, no titles), so exposure is identical to
 * the existing ForumStatsWidget — no new boundary and no hidden-forum leak. The who's-online panel delegates
 * to {@see OnlineMembers}, the single source of truth that enforces the opt-in (`show_online_status`) rule.
 */
final class InfoCenter
{
    /** RH-9: one key, primitives only. */
    private const CACHE_KEY = 'novfora:infocenter:stats';

    private const TTL_SECONDS = 60;

    public function __construct(private readonly OnlineMembers $online) {}

    /**
     * Board statistics for the panel: the cached primitives plus the newest member rehydrated AFTER the
     * cache boundary (never a model in the cache).
     *
     * @return array{posts:int, topics:int, members:int, postsToday:int, newestMember:?User}
     */
    public function statistics(): array
    {
        $stats = $this->cachedPrimitives();

        return [
            'posts' => $stats['posts'],
            'topics' => $stats['topics'],
            'members' => $stats['members'],
            'postsToday' => $stats['postsToday'],
            // Re-assert the active filter at rehydration: if the cached newest member was banned/deactivated
            // within the 60s window, the cached id is stale — don't surface a now-hidden member (the members
            // directory + who's-online already exclude them). Falls back to "—" until the cache refreshes.
            'newestMember' => $stats['newestMemberId'] !== null
                ? User::query()->where('status', 'active')->find($stats['newestMemberId'])
                : null,
        ];
    }

    /**
     * The opt-in who's-online panel data, straight from the presence source of truth (its own surfaces cache
     * as needed; here we read it live, exactly as the live widget's poll does).
     *
     * @return array{members: Collection<int, User>, count:int, windowMinutes:int}
     */
    public function whosOnline(int $limit = 30): array
    {
        return [
            'members' => $this->online->recent($limit),
            'count' => $this->online->count(),
            'windowMinutes' => $this->online->windowMinutes(),
        ];
    }

    /**
     * The cached window of PRIMITIVES (RH-9): scalars only, never a model. Correctness never depends on the
     * cache — a store failure falls straight through to a live load.
     *
     * @return array{posts:int, topics:int, members:int, postsToday:int, newestMemberId:?int}
     */
    private function cachedPrimitives(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, now()->addSeconds(self::TTL_SECONDS), fn (): array => $this->loadPrimitives());
        } catch (\Throwable) {
            return $this->loadPrimitives();
        }
    }

    /** @return array{posts:int, topics:int, members:int, postsToday:int, newestMemberId:?int} */
    private function loadPrimitives(): array
    {
        return [
            'posts' => (int) Post::query()->count(),
            'topics' => (int) Topic::query()->count(),
            'members' => (int) User::query()->where('status', 'active')->count(),
            // "Posts today" counts from local midnight in the app timezone: Carbon::today() honours
            // config('app.timezone'), matching how created_at is stamped, so the day boundary is the
            // board's own midnight rather than UTC's.
            'postsToday' => (int) Post::query()->where('created_at', '>=', Carbon::today())->count(),
            'newestMemberId' => $this->newestActiveMemberId(),
        ];
    }

    /** The most-recently-registered ACTIVE member's id (highest id wins; banned/inactive excluded), or null. */
    private function newestActiveMemberId(): ?int
    {
        $id = User::query()->where('status', 'active')->max('id');

        return $id !== null ? (int) $id : null;
    }
}
