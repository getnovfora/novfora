<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Search;

use App\Models\Post;
use App\Permissions\VisibleForumIds;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Keyword + faceted search over post bodies (ADR-0010; P2-M4 facets), tier-graceful by construction.
 *
 * `posts()` is the original keyword-only path (typeahead + back-compat): the configured Scout engine first
 * (MySQL FULLTEXT on baseline, Meilisearch on enhanced); if that engine is unreachable it DEGRADES to a
 * direct database query rather than erroring. `search()` is the faceted path behind the search page: it runs
 * as a direct, controllable Eloquent query on the BASELINE (no Scout dependency — the baseline IS the DB) so
 * every facet works without an external engine, and threads VisibleForumIds through EVERY result so a viewer
 * can never retrieve a post from a forum they cannot see — via the forum facet or any other.
 *
 * Driver abstraction (DECISIONS): facets that map to real post columns (author, date) and the topic-derived
 * constraints (forum, tag, type, visibility) are expressed as Eloquent WHEREs for the DB tier; `meiliFilter()`
 * translates the same SearchQuery into Meilisearch's native filter syntax for the enhanced tier. The DB tier
 * is the tested baseline; the Meili translation is unit-tested at the string level.
 */
final class SearchService
{
    /**
     * Keyword-only search (typeahead + back-compat). Visibility is enforced by the caller. @return Collection<int, Post>
     */
    public function posts(string $query, int $limit = 20): Collection
    {
        $query = trim($query);
        if ($query === '') {
            return collect();
        }

        try {
            return Post::search($query)->take($limit)->get()
                ->load('topic') // on the Eloquent collection, before filter() narrows it to a base collection
                ->filter(fn (Post $p) => $p->approved_state === 'approved')
                ->values();
        } catch (\Throwable) {
            // Enhanced engine unreachable / client absent → degrade to the database. Never error.
            return $this->databaseFallback($query, $limit);
        }
    }

    /**
     * Faceted, visibility-gated search (the search page). Always correct on the baseline DB tier. @return Collection<int, Post>
     */
    public function search(SearchQuery $query): Collection
    {
        $visible = VisibleForumIds::for($query->viewer);
        if ($visible === []) {
            return collect(); // the viewer can see no forum → no result is reachable (never run an IN ())
        }

        $forumIds = $this->effectiveForumIds($query, $visible);
        if ($forumIds === []) {
            return collect(); // the chosen forum is not visible to this viewer → empty
        }

        return $this->databaseSearch($query, $forumIds);
    }

    /**
     * The effective forum-id constraint = the viewer's visible set ∩ the (optional) forum facet.
     * Returns null = no forum constraint (sees all AND no forum facet); [] = an impossible set → empty result.
     *
     * @param  list<int>|null  $visible  null = sees all; otherwise the visible forum ids
     * @return list<int>|null
     */
    private function effectiveForumIds(SearchQuery $query, ?array $visible): ?array
    {
        if ($query->forumId === null) {
            return $visible; // null (all) or the visible subset — visibility still applies
        }
        if ($visible === null || in_array($query->forumId, $visible, true)) {
            return [$query->forumId]; // the chosen forum is visible (or the viewer sees all)
        }

        return []; // the chosen forum is NOT visible → no results, regardless of keyword
    }

    /**
     * The baseline Eloquent implementation: keyword (body_text LIKE) + facets, constrained to $forumIds.
     *
     * @param  list<int>|null  $forumIds  null = no forum constraint (sees all); else restrict to these forums
     * @return Collection<int, Post>
     */
    private function databaseSearch(SearchQuery $query, ?array $forumIds): Collection
    {
        return Post::query()
            ->where('approved_state', 'approved')
            ->when($query->term !== '', fn (Builder $b) => $b->where('body_text', 'like', '%'.$this->escape($query->term).'%'))
            ->when($query->authorId !== null, fn (Builder $b) => $b->where('user_id', $query->authorId))
            ->when($query->dateFrom !== null, fn (Builder $b) => $b->where('created_at', '>=', $query->dateFrom))
            ->when($query->dateTo !== null, fn (Builder $b) => $b->where('created_at', '<=', $query->dateTo))
            // forum (∩ visibility), tag, and type are topic-derived: one correlated EXISTS over the topic.
            ->whereHas('topic', function (Builder $t) use ($forumIds, $query) {
                if ($forumIds !== null) {
                    $t->whereIn('forum_id', $forumIds);
                }
                if ($query->tagIds !== []) {
                    $t->whereHas('tags', fn (Builder $tag) => $tag->whereIn('tags.id', $query->tagIds));
                }
                if ($query->type === 'topic') {
                    // Only the opening post of each thread (one hit per topic). first_post_id correlates to posts.id.
                    $t->whereColumn('topics.first_post_id', 'posts.id');
                }
            })
            ->latest('id')
            ->limit($query->limit)
            ->with('topic')
            ->get();
    }

    /**
     * Translate a SearchQuery into Meilisearch native filter expressions (enhanced tier). forum_id / user_id /
     * created_at are filterable attributes on the Meili index (Post::toSearchableArray adds them for that
     * driver). The tag + type facets are NOT expressed here and are NOT yet supported on a Meili execution
     * path — the faceted page deliberately stays on the DB engine (search() always calls databaseSearch), so
     * this translation is provided + unit-tested for a future Meili wiring but is not on any live path today.
     * Returned as a list of clause strings the caller joins with " AND ".
     *
     * @param  list<int>|null  $forumIds  the already-resolved visible∩facet forum set (null = unconstrained)
     * @return list<string>
     */
    public function meiliFilter(SearchQuery $query, ?array $forumIds): array
    {
        $clauses = [];

        if ($forumIds !== null) {
            $clauses[] = 'forum_id IN ['.implode(', ', $forumIds).']';
        }
        if ($query->authorId !== null) {
            $clauses[] = 'user_id = '.$query->authorId;
        }
        if ($query->dateFrom !== null) {
            $clauses[] = 'created_at >= '.$query->dateFrom->getTimestamp();
        }
        if ($query->dateTo !== null) {
            $clauses[] = 'created_at <= '.$query->dateTo->getTimestamp();
        }

        return $clauses;
    }

    /** Public so the search page / Meili path can resolve the same visible∩facet set the DB path uses. */
    public function resolveForumIds(SearchQuery $query): ?array
    {
        return $this->effectiveForumIds($query, VisibleForumIds::for($query->viewer));
    }

    /** @return Collection<int, Post> */
    private function databaseFallback(string $query, int $limit): Collection
    {
        return Post::query()
            ->where('approved_state', 'approved')
            ->where('body_text', 'like', '%'.$this->escape($query).'%')
            ->latest()
            ->limit($limit)
            ->with('topic')
            ->get();
    }

    /** Escape the LIKE wildcards so a literal % or _ in the query can't widen the match. */
    private function escape(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
