<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Embeds;

use App\Models\Forum;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use App\Permissions\VisibleForumIds;
use Illuminate\Support\Facades\Cache;

/**
 * Assembles the embed widget payloads (U7, ADR-0103). Every read is fenced to the GUEST principal —
 * `User::guest()` through the permission engine + the club-privacy gates, exactly like FeedController /
 * SitemapController — so no embed parameter can widen visibility. The visibility verdict runs LIVE on
 * every request; only the resulting viewer-independent payload is cached (short TTL, plain arrays).
 */
final class WidgetData
{
    private const TOPICS_TTL_SECONDS = 60;

    private const STATS_TTL_SECONDS = 300;

    private const MAX_LIMIT = 20;

    private const DEFAULT_LIMIT = 5;

    /**
     * Latest guest-visible topics, optionally scoped to one forum. Returns null when the forum doesn't
     * exist or the guest fence denies it — callers 404 (never 403; no existence oracle, the feeds idiom).
     *
     * @return array{title:string,url:string,items:list<array{title:string,url:string,posted_at:?string}>}|null
     */
    public function topics(?int $forumId, int $limit): ?array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit ?: self::DEFAULT_LIMIT));
        $guest = User::guest();

        $forum = null;
        if ($forumId !== null) {
            $forum = Forum::query()->whereKey($forumId)->where('type', 'forum')->first();
            if (! $forum instanceof Forum
                || ! $guest->canDo('forum.view', $forum->permissionScope())
                || ! $forum->clubContentVisibleTo(null)) {
                return null;
            }
        }

        // Resolve the guest-visible forum set LIVE (outside the cache) for the board-wide path, and fold a
        // signature of it into the cache key — so the instant an admin restricts a forum/club the visible set
        // changes, the key changes, and the next request runs a fresh, correctly-fenced query instead of
        // serving a stale payload for the rest of the TTL. This keeps the board-wide path consistent with the
        // live-fenced scoped path above (U7 review, ADR-0104-adjacent hardening).
        $visible = $forum instanceof Forum ? null : VisibleForumIds::for(User::guest());
        $scopeKey = $forum instanceof Forum
            ? (string) $forum->getKey()
            : 'all-'.($visible === null ? 'x' : substr(hash('sha256', implode(',', $visible)), 0, 16));
        $cacheKey = 'novfora.embed.topics.'.$scopeKey.'.'.$limit;

        return Cache::remember($cacheKey, self::TOPICS_TTL_SECONDS, function () use ($forum, $visible, $limit): array {
            $query = Topic::query()
                ->where('approved_state', 'approved')
                ->whereNull('moved_to_topic_id')
                ->whereNotNull('last_posted_at')
                ->orderByDesc('last_posted_at')
                ->limit($limit);

            if ($forum instanceof Forum) {
                $query->where('forum_id', $forum->getKey());
            } elseif ($visible === []) {
                $query->whereRaw('1 = 0');
            } elseif (is_array($visible)) {
                // Board-wide: VisibleForumIds already layers club privacy over guest forum.view.
                $query->whereIn('forum_id', $visible);
            }

            return [
                'title' => $forum instanceof Forum ? (string) $forum->title : (string) config('app.name', 'NovFora'),
                'url' => $forum instanceof Forum ? route('forums.show', $forum) : route('forums.index'),
                'items' => $query->get(['id', 'slug', 'title', 'last_posted_at'])
                    ->map(fn (Topic $t): array => [
                        'title' => (string) $t->title,
                        'url' => route('topics.show', $t),
                        'posted_at' => $t->last_posted_at?->toIso8601String(),
                    ])->all(),
            ];
        });
    }

    /**
     * Public board aggregates — the same numbers the public forum index already exposes to guests.
     *
     * @return array{title:string,url:string,members:int,topics:int,posts:int}
     */
    public function stats(): array
    {
        return Cache::remember('novfora.embed.stats', self::STATS_TTL_SECONDS, fn (): array => [
            'title' => (string) config('app.name', 'NovFora'),
            'url' => route('forums.index'),
            'members' => User::query()->where('status', 'active')->count(),
            'topics' => Topic::query()->where('approved_state', 'approved')->count(),
            'posts' => Post::query()->where('approved_state', 'approved')->count(),
        ]);
    }
}
