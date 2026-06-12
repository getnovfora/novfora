<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Forum;

use App\Events\Reacted;
use App\Events\ReactionRemoved;
use App\Models\Post;
use App\Models\PostReactionCount;
use App\Models\Reaction;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The single write + read path for post reactions (P2-M1). Single-choice typed reactions (XF-style): a user
 * holds at most one reaction per post. The per-type tally in `post_reaction_counts` is recomputed
 * AUTHORITATIVELY from `reactions` after every mutation (drift-free, mirroring Post::syncAggregates) rather
 * than blindly incremented, so a lost/duplicated event can never skew a count.
 *
 * Read side honours RH-9: the thread page reads a single primitives-only cache entry per (topic, version),
 * version-bumped on any reaction change — one cache GET for the whole page (never a per-post N+1 against the
 * cache table on a serialising store), rehydrated after the boundary.
 */
final class ReactionService
{
    private const COUNTS_TTL_MINUTES = 30;

    /** @return list<string> the configured reaction type keys */
    public static function types(): array
    {
        return array_keys((array) config('novfora.reactions.types', []));
    }

    public static function isValidType(string $type): bool
    {
        return in_array($type, self::types(), true);
    }

    /**
     * Apply the single user action with single-choice semantics, transactionally:
     *   - no existing reaction       → add $type
     *   - existing reaction == $type → remove it (toggle off)
     *   - existing reaction != $type → change to $type
     * Returns the resulting type, or null when the reaction was toggled off.
     *
     * @throws \InvalidArgumentException on an unknown type
     */
    public function toggle(User $user, Post $post, string $type): ?string
    {
        if (! self::isValidType($type)) {
            throw new \InvalidArgumentException("Unknown reaction type: {$type}");
        }

        try {
            return $this->apply($user, $post, $type);
        } catch (UniqueConstraintViolationException) {
            // A concurrent first-reaction by the SAME user won the INSERT race (lockForUpdate cannot lock a
            // not-yet-existent row; the DB UNIQUE is the real guard). The row now exists, so a single retry
            // resolves deterministically down the change/remove path — no second insert, no 500.
            return $this->apply($user, $post, $type);
        }
    }

    private function apply(User $user, Post $post, string $type): ?string
    {
        [$result, $changed, $removed] = DB::transaction(function () use ($user, $post, $type): array {
            // Serialise concurrent toggles on the same (post,user) so the single-choice invariant and the
            // tally recompute stay consistent. (The missing-row INSERT race is caught by toggle() above.)
            $existing = Reaction::where('post_id', $post->getKey())
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->first();

            $touched = [];
            $result = null;
            $removed = null;

            if ($existing === null) {
                Reaction::create([
                    'post_id' => $post->getKey(),
                    'user_id' => $user->getKey(),
                    'type' => $type,
                    'tenant_id' => $post->tenant_id,
                ]);
                $touched[] = $type;
                $result = $type;
            } elseif ($existing->type === $type) {
                $existing->delete();
                $touched[] = $type;
                $removed = $existing; // the in-memory model keeps its id — the rep ledger's source key
            } else {
                $touched[] = $existing->type;
                $existing->forceFill(['type' => $type])->save();
                $touched[] = $type;
                $result = $type;
            }

            foreach (array_unique($touched) as $t) {
                $this->recountType($post, $t);
            }
            $this->bumpVersion((int) $post->topic_id);

            Audit::log('post.reacted', $post, [
                'type' => $type,
                'result' => $result ?? 'removed',
                'user_id' => $user->getKey(),
            ]);

            return [$result, $result !== null, $removed];
        });

        // Emit the domain events AFTER commit, so a listener never sees uncommitted state or fires on a
        // rolled-back toggle. Reacted on add/change (notification + reputation award — the amendment-#4
        // score weights are LIVE as of P2-M5); ReactionRemoved on pure toggle-off (reputation revoke).
        if ($changed) {
            Reacted::dispatch($user, $post, $type);
        } elseif ($removed !== null) {
            ReactionRemoved::dispatch($user, $post, $removed);
        }

        return $result;
    }

    /**
     * Authoritatively recompute the (post,type) tally from the source table. A row exists only while count ≥ 1.
     */
    private function recountType(Post $post, string $type): void
    {
        $this->recountTypeForPostId((int) $post->getKey(), $type);
    }

    /** As recountType, but keyed by a bare post id (no model needed) — the batch/cascade path. */
    private function recountTypeForPostId(int $postId, string $type): void
    {
        $count = Reaction::where('post_id', $postId)->where('type', $type)->count();

        if ($count === 0) {
            PostReactionCount::where('post_id', $postId)->where('type', $type)->delete();

            return;
        }

        PostReactionCount::updateOrCreate(
            ['post_id' => $postId, 'type' => $type],
            ['count' => $count],
        );
    }

    /**
     * Authoritatively recompute the per-type tallies for a batch of posts and invalidate each affected
     * topic's cached tally. The account-deletion cascade (ADR-0025) calls this AFTER a departing user's
     * reactions are bulk-deleted: the affected post ids are captured BEFORE the delete (the reaction rows —
     * and the captured ids — are gone afterwards), then for every post we recompute exactly the types that
     * could have shifted: any type with a surviving reaction PLUS any type that still carries a count row
     * (so a tally the deleted reactor solely held is driven to 0 and its row removed). Config-independent —
     * a since-removed reaction type is still reconciled. Posts survive deletion (pseudonymised), so a
     * soft-deleted one is loaded via withTrashed to resolve its topic for cache invalidation.
     *
     * @param  list<int>  $postIds
     */
    public function recomputeForPosts(array $postIds): void
    {
        $postIds = array_values(array_unique(array_map('intval', $postIds)));
        if ($postIds === []) {
            return;
        }

        foreach ($postIds as $postId) {
            $types = Reaction::where('post_id', $postId)->distinct()->pluck('type')
                ->merge(PostReactionCount::where('post_id', $postId)->pluck('type'))
                ->unique();

            foreach ($types as $type) {
                $this->recountTypeForPostId($postId, (string) $type);
            }
        }

        foreach (Post::withTrashed()->whereIn('id', $postIds)->pluck('topic_id')->unique() as $topicId) {
            $this->invalidateTopic((int) $topicId);
        }
    }

    // ── read side (RH-9: primitives only, rehydrate after the boundary) ────────────────────────────

    /**
     * Per-post reaction tallies for a page of posts, as a primitive map [postId => [type => count]]. Caches
     * the WHOLE topic's tally under one (topic, version) key — a single cache GET serves every page of the
     * topic, so the thread page never issues a per-post count query (budget-safe on a serialising store).
     * On any reaction change the version bumps, so a stale entry is simply never read again.
     *
     * @param  list<int>  $postIds
     * @return array<int, array<string, int>>
     */
    public function countsForTopic(int $topicId, array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }

        $version = $this->version($topicId);

        $all = Cache::remember(
            "novfora.reactions.counts.t{$topicId}.v{$version}",
            now()->addMinutes(self::COUNTS_TTL_MINUTES),
            function () use ($topicId): array {
                // One query; emit plain nested scalars so unserialize(allowed_classes:false) leaves it intact.
                $map = [];
                $rows = PostReactionCount::query()
                    ->whereIn('post_id', Post::where('topic_id', $topicId)->select('id'))
                    ->get(['post_id', 'type', 'count']);

                foreach ($rows as $row) {
                    $map[(int) $row->post_id][(string) $row->type] = (int) $row->count;
                }

                return $map;
            },
        );

        // Rehydrate/slice to just this page's posts — AFTER the cache boundary.
        return array_intersect_key($all, array_flip($postIds));
    }

    /** Fresh (uncached) per-type tally for a single post — used right after a mutation in the action. @return array<string,int> */
    public function countsForPost(Post $post): array
    {
        return PostReactionCount::where('post_id', $post->getKey())
            ->pluck('count', 'type')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    /**
     * The viewer's own reaction type per post (to highlight their pick), as [postId => type]. Per-viewer, so
     * NOT cached — one batched query for the whole page.
     *
     * @param  list<int>  $postIds
     * @return array<int, string>
     */
    public function viewerReactions(User $viewer, array $postIds): array
    {
        if ($postIds === [] || ! $viewer->exists) {
            return [];
        }

        return Reaction::where('user_id', $viewer->getKey())
            ->whereIn('post_id', $postIds)
            ->pluck('type', 'post_id')
            ->map(fn ($t) => (string) $t)
            ->all();
    }

    /**
     * Invalidate a topic's cached reaction tally — bump the version so the next read rebuilds. Called when the
     * post SET of a topic changes outside ReactionService (a post soft-delete/restore cascades/affects which
     * tallies are in scope), keeping the RH-9 cache consistent without a per-post key.
     */
    public function invalidateTopic(int $topicId): void
    {
        $this->bumpVersion($topicId);
    }

    // ── per-topic cache version (mirrors the AclVersion pattern; TTL ≫ counts TTL so it never resets first) ──

    private function version(int $topicId): int
    {
        return (int) Cache::get($this->versionKey($topicId), 0);
    }

    private function bumpVersion(int $topicId): void
    {
        $key = $this->versionKey($topicId);

        // add() seeds the counter at 1 if absent (returns true); otherwise increment the live counter.
        if (! Cache::add($key, 1, now()->addYear())) {
            Cache::increment($key);
        }
    }

    private function versionKey(int $topicId): string
    {
        return "novfora.reactions.ver.t{$topicId}";
    }
}
