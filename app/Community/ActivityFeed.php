<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Community;

use App\Models\Activity;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use App\Permissions\VisibleForumIds;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The fan-out-on-read activity feed (P2-M3 ⚙). RH-9 cache discipline:
 *   - a GLOBAL window of the latest activities is cached as PRIMITIVE rows under a version-keyed key
 *     (shared across viewers; the version bumps on every new Activity, so a new row simply makes the old
 *     entry unreadable; a 60s TTL is belt-and-braces);
 *   - the per-viewer permission filter (VisibleForumIds) and the subject/actor rehydration run AFTER the
 *     cache boundary — never a model, related object, or filter result in the cache.
 *
 * Known limitation (DECISIONS): the window is global (latest N), filtered to the page size per viewer. A
 * heavily-restricted viewer whose only visible forum is low-traffic can see an empty feed if every row in
 * the window is from forums they can't see. The fix (paginate past the window, or cache per-scope) is an
 * M4-era optimisation; for M3 most activity is in forums most viewers can see.
 */
final class ActivityFeed
{
    /** Global cache window — larger than the page so per-viewer filtering still has rows to show. */
    private const WINDOW = 100;

    /** Rendered page size. */
    private const LIMIT = 50;

    private const TTL_SECONDS = 60;

    public function __construct(private readonly ActivityVersion $version) {}

    /**
     * The viewer's permission-filtered, rehydrated feed page.
     *
     * @return list<ActivityFeedItem>
     */
    public function for(User $viewer): array
    {
        return $this->page($viewer, fn (): array => $this->window());
    }

    /**
     * The FOLLOWING variant (P2-M5): the same feed restricted to activity whose actor is one of the
     * viewer's followed users — and STILL passed through the same VisibleForumIds filter, so a followed
     * user's activity in a forum the viewer cannot see stays hidden (one permission path, never bypassed).
     * The caller resolves the followed ids (FollowService::followingIds) and handles the empty-set case
     * (the UI falls back to the global feed with a hint — recorded decision).
     *
     * @param  list<int>  $followedIds
     * @return list<ActivityFeedItem>
     */
    public function forFollowing(User $viewer, array $followedIds): array
    {
        $followedIds = array_values(array_unique(array_map('intval', $followedIds)));
        if ($followedIds === []) {
            return [];
        }

        return $this->page($viewer, fn (): array => $this->followingWindow($followedIds));
    }

    /**
     * Shared read path: short-circuit a sees-no-forum viewer BEFORE touching the window, then apply the
     * per-viewer permission filter and rehydrate — both strictly AFTER the cache boundary (RH-9).
     *
     * @param  \Closure(): list<array<string, mixed>>  $window
     * @return list<ActivityFeedItem>
     */
    private function page(User $viewer, \Closure $window): array
    {
        // Per-viewer permission filter (NOT cached). [] = sees no forum → no forum-scoped activity at all.
        $visibleIds = VisibleForumIds::for($viewer);
        if ($visibleIds === []) {
            return [];
        }

        $rows = $window();

        if ($visibleIds !== null) {
            $allowed = array_flip($visibleIds);
            $rows = array_values(array_filter(
                $rows,
                fn (array $r): bool => $r['scope_forum_id'] === null || isset($allowed[$r['scope_forum_id']]),
            ));
        }

        $rows = array_slice($rows, 0, self::LIMIT);
        if ($rows === []) {
            return [];
        }

        $items = $this->rehydrate($rows);

        // SECOND, CURRENT-STATE permission pass (adversarial-review HIGH): activities.scope_forum_id is
        // frozen at creation, so a topic later MOVED into a restricted forum would leak its title/link
        // through the row-level filter above. The rehydrated subjects carry their LIVE forum — re-check it
        // against the same visible set, at zero extra queries. A tombstone (gone subject) has no forum and
        // renders title-less, so it stays.
        if ($visibleIds !== null) {
            $allowed = array_flip($visibleIds);
            $items = array_values(array_filter($items, function (ActivityFeedItem $item) use ($allowed): bool {
                $forumId = $item->topic()?->forum_id;

                return $forumId === null || isset($allowed[(int) $forumId]);
            }));
        }

        return $items;
    }

    /**
     * The cached global window of primitive rows (newest first). Cache holds SCALARS only.
     *
     * @return list<array<string, mixed>>
     */
    private function window(): array
    {
        $key = 'novfora.activities.feed.v'.$this->version->current();

        try {
            return Cache::remember($key, now()->addSeconds(self::TTL_SECONDS), fn (): array => $this->loadWindow());
        } catch (\Throwable) {
            return $this->loadWindow(); // correctness never depends on the cache
        }
    }

    /**
     * The cached FOLLOWING window (P2-M5): the latest activities by the followed actors, as primitive rows.
     * The key carries a HASH OF THE SORTED FOLLOWED-ID SET — a follow/unfollow changes the key (so the
     * window can never serve a stale follow graph) and viewers with the same follow set share the entry;
     * the activity version + short TTL behave exactly like the global window. A pseudonymised (deleted)
     * actor is NULL and naturally never matches the IN list.
     *
     * @param  list<int>  $followedIds
     * @return list<array<string, mixed>>
     */
    private function followingWindow(array $followedIds): array
    {
        sort($followedIds);
        $setHash = md5(implode(',', $followedIds));
        $key = "novfora.activities.feed.following.{$setHash}.v".$this->version->current();

        try {
            return Cache::remember($key, now()->addSeconds(self::TTL_SECONDS), fn (): array => $this->loadWindow($followedIds));
        } catch (\Throwable) {
            return $this->loadWindow($followedIds); // correctness never depends on the cache
        }
    }

    /** @param list<int>|null $actorIds null = the global window @return list<array<string, mixed>> */
    private function loadWindow(?array $actorIds = null): array
    {
        return Activity::query()
            ->when($actorIds !== null, fn ($q) => $q->whereIn('actor_id', $actorIds))
            ->orderByDesc('id')
            ->limit(self::WINDOW)
            ->get(['id', 'actor_id', 'verb', 'subject_type', 'subject_id', 'object_type', 'object_id', 'scope_forum_id', 'created_at'])
            ->map(fn (Activity $a): array => [
                'id' => (int) $a->id,
                'actor_id' => $a->actor_id !== null ? (int) $a->actor_id : null,
                'verb' => (string) $a->verb,
                'subject_type' => (string) $a->subject_type,
                'subject_id' => (int) $a->subject_id,
                'object_type' => $a->object_type,
                'object_id' => $a->object_id !== null ? (int) $a->object_id : null,
                'scope_forum_id' => $a->scope_forum_id !== null ? (int) $a->scope_forum_id : null,
                'created_at' => $a->created_at?->toIso8601String(),
            ])->all();
    }

    /**
     * Batch-load the distinct actors + subjects for the page (withTrashed → a deleted subject becomes a
     * tombstone, not a throw). No per-row lazy loads.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<ActivityFeedItem>
     */
    private function rehydrate(array $rows): array
    {
        $topicType = (new Topic)->getMorphClass();
        $postType = (new Post)->getMorphClass();

        $actorIds = array_values(array_unique(array_filter(array_map(fn (array $r) => $r['actor_id'], $rows), fn ($v) => $v !== null)));
        $actors = $actorIds === [] ? collect() : User::with('groups')->whereIn('id', $actorIds)->get()->keyBy('id');

        $topicIds = [];
        $postIds = [];
        foreach ($rows as $r) {
            if ($r['subject_type'] === $topicType) {
                $topicIds[] = $r['subject_id'];
            } elseif ($r['subject_type'] === $postType) {
                $postIds[] = $r['subject_id'];
            }
        }
        $topics = $topicIds === [] ? collect() : Topic::withTrashed()->whereIn('id', array_unique($topicIds))->get()->keyBy('id');
        $posts = $postIds === [] ? collect() : Post::withTrashed()->with(['topic' => fn ($q) => $q->withTrashed()])->whereIn('id', array_unique($postIds))->get()->keyBy('id');

        $items = [];
        foreach ($rows as $r) {
            $subject = match ($r['subject_type']) {
                $topicType => $topics->get($r['subject_id']),
                $postType => $posts->get($r['subject_id']),
                default => null,
            };

            $items[] = new ActivityFeedItem(
                id: $r['id'],
                verb: $r['verb'],
                actor: $r['actor_id'] !== null ? $actors->get($r['actor_id']) : null,
                subject: $subject,
                createdAt: $r['created_at'] !== null ? Carbon::parse($r['created_at']) : null,
            );
        }

        return $items;
    }
}
