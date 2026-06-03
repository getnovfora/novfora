<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Search;

use App\Models\Post;
use Illuminate\Support\Collection;

/**
 * Keyword search over post bodies (ADR-0010), tier-graceful by construction. The configured Scout engine
 * runs first (MySQL FULLTEXT on baseline, Meilisearch on enhanced); if that engine is unreachable or its
 * client is absent, it DEGRADES to a direct database query rather than erroring — so search always returns
 * results on the baseline tier with no external dependency. Results are filtered to approved content;
 * per-forum visibility is enforced by the caller against the permission engine.
 */
final class SearchService
{
    /** @return Collection<int, Post> */
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

    /** @return Collection<int, Post> */
    private function databaseFallback(string $query, int $limit): Collection
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);

        return Post::query()
            ->where('approved_state', 'approved')
            ->where('body_text', 'like', '%'.$escaped.'%')
            ->latest()
            ->limit($limit)
            ->with('topic')
            ->get();
    }
}
