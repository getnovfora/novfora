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
 * Trending / best-of (discovery 3.1). Ranks topics by an engagement SCORE built from the EXISTING all-time
 * aggregates (replies weighted, views, and the summed reaction tally) — no new denormalisation. "Trending"
 * windows on `last_posted_at` (recently-active); "best of" is all-time.
 *
 * PERMISSION-SAFE: every query is gated through {@see VisibleForumIds::for()} (null = sees all → no clause;
 * [] = sees none → empty; otherwise `whereIn('forum_id', …)`), querying the LIVE topics table so forum_id is
 * always current (no stale-scope re-check needed, unlike the activity feed's frozen scope_forum_id).
 */
final class TrendingService
{
    /** reply_count weighted heaviest, then views, then the topic's summed reaction count (correlated subquery). */
    private const SCORE = 'topics.reply_count * 4 + topics.view_count '
        .'+ COALESCE((select sum(prc.count) from post_reaction_counts prc '
        .'inner join posts p on p.id = prc.post_id where p.topic_id = topics.id), 0)';

    /** @return Collection<int,Topic> recently-active topics, ranked by engagement, visible to the viewer */
    public function trending(User $viewer, int $days = 7, int $limit = 20): Collection
    {
        $query = $this->base($viewer, $limit);
        if ($query === null) {
            return collect();
        }

        return $query->where('topics.last_posted_at', '>=', now()->subDays(max(1, $days)))->get();
    }

    /** @return Collection<int,Topic> the all-time most-engaged topics, visible to the viewer */
    public function bestOf(User $viewer, int $limit = 20): Collection
    {
        return $this->base($viewer, $limit)?->get() ?? collect();
    }

    /** @return Builder<Topic>|null null when the viewer can see no forum at all */
    private function base(User $viewer, int $limit): ?Builder
    {
        $visible = VisibleForumIds::for($viewer);
        if ($visible === []) {
            return null;
        }

        $query = Topic::query()
            ->where('topics.approved_state', 'approved')
            ->whereNotNull('topics.last_posted_at')
            ->select('topics.*')
            ->selectRaw('('.self::SCORE.') as score')
            ->with(['forum', 'author', 'prefix'])
            ->orderByDesc('score')
            ->orderByDesc('topics.last_posted_at')
            ->limit(max(1, min(50, $limit)));

        if ($visible !== null) {
            $query->whereIn('topics.forum_id', $visible);
        }

        return $query;
    }
}
