<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Discovery;

use App\Models\Topic;
use App\Models\User;
use App\Permissions\VisibleForumIds;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Lightweight, baseline-safe "related topics" recommendations (discovery 3.3). No ML, no external service:
 * topics that SHARE A TAG with the source, newest-active first, topped up from the SAME FORUM if needed.
 * Permission-safe via {@see VisibleForumIds} so a recommendation never points at a forum the viewer can't see.
 */
final class RecommendationService
{
    /** @return Collection<int,Topic> up to $limit related topics, visible to the viewer (excludes the source) */
    public function related(Topic $topic, User $viewer, int $limit = 5): Collection
    {
        $visible = VisibleForumIds::for($viewer);
        if ($visible === []) {
            return collect();
        }

        $limit = max(1, min(20, $limit));
        $tagIds = $topic->tags()->pluck('tags.id')->all();

        $byTag = collect();
        if ($tagIds !== []) {
            $byTag = $this->base($visible)
                ->where('id', '!=', $topic->getKey())
                ->whereHas('tags', fn (Builder $q) => $q->whereIn('tags.id', $tagIds))
                ->orderByDesc('last_posted_at')
                ->limit($limit)
                ->get();
        }

        if ($byTag->count() >= $limit) {
            return $byTag;
        }

        // Top up from the same forum (excluding the source + anything already chosen).
        $exclude = $byTag->pluck('id')->push($topic->getKey())->all();
        $more = $this->base($visible)
            ->where('forum_id', $topic->forum_id)
            ->whereNotIn('id', $exclude)
            ->orderByDesc('last_posted_at')
            ->limit($limit - $byTag->count())
            ->get();

        return $byTag->concat($more);
    }

    /** @param  list<int>|null  $visible @return Builder<Topic> */
    private function base(?array $visible): Builder
    {
        $query = Topic::query()
            ->where('approved_state', 'approved')
            ->whereNotNull('last_posted_at')
            ->with(['forum', 'prefix']);

        if ($visible !== null) {
            $query->whereIn('forum_id', $visible);
        }

        return $query;
    }
}
