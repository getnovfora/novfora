<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\Forum;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class TagController extends Controller
{
    /**
     * All tags, ordered by usage (most-used first). The listing is public; individual tagged topics are
     * filtered on tags.show. M1.5: a tag used ONLY in a closed/private club forum is omitted, so a club-exclusive
     * tag name (and its existence) never leaks on the public index.
     */
    public function index(): View
    {
        $hidden = $this->hiddenClubForumIds();

        $tags = Tag::query()
            ->when(
                $hidden->isNotEmpty(),
                fn ($q) => $q->whereHas('topics', fn ($t) => $t->whereNotIn('forum_id', $hidden->all())),
            )
            ->orderByDesc('usage_count')->orderBy('name')->paginate(50);

        return view('tags.index', compact('tags'));
    }

    /** Forum ids of closed/private club discussion forums — content that must not surface publicly. @return Collection<int,int> */
    private function hiddenClubForumIds(): Collection
    {
        $closedClubIds = Club::query()->where('privacy', '!=', 'public')->pluck('id');
        if ($closedClubIds->isEmpty()) {
            return collect();
        }

        return Forum::query()->whereIn('club_id', $closedClubIds)->pluck('id')->map(fn ($id): int => (int) $id);
    }

    /**
     * Topics carrying the given tag, paginated and permission-filtered by forum.view (mirrors the board).
     * Eager-load the topic's forum + author + prefix + tags to avoid N+1.
     */
    public function show(Request $request, Tag $tag): View
    {
        $viewer = $request->user() ?? User::guest();

        // Load the tag's topics with their forums so we can filter by forum.view. We over-fetch and
        // filter in PHP (the forum set is small) rather than building a complex subquery — the same
        // pattern used by ForumController::show for child boards.
        $topics = $tag->topics()
            ->with(['forum', 'author.groups', 'prefix', 'tags'])
            ->where('approved_state', 'approved')
            ->orderByDesc('last_posted_at')
            ->orderByDesc('id')
            ->paginate(20);

        // Filter to forums the viewer can see (forum.view check per unique forum).
        /** @var array<int,bool> $visibleForums */
        $visibleForums = [];
        $topics->setCollection(
            $topics->getCollection()->filter(function ($topic) use ($viewer, &$visibleForums) {
                $forumId = (int) $topic->forum_id;
                if (! array_key_exists($forumId, $visibleForums)) {
                    $forum = $topic->forum;
                    $visibleForums[$forumId] = $forum instanceof Forum
                        && $viewer->canDo('forum.view', $forum->permissionScope())
                        && $forum->clubContentVisibleTo($viewer); // M1.5 club content gate
                }

                return $visibleForums[$forumId];
            })->values(),
        );

        return view('tags.show', compact('tag', 'topics', 'viewer'));
    }
}
