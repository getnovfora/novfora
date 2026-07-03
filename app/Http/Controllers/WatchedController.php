<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ContentSubscription;
use App\Models\Forum;
use App\Models\Tag;
use App\Models\Topic;
use App\Models\User;
use App\Permissions\VisibleForumIds;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Watched surface (U2, NOV-101) — a member's home loop: the forums, tags, and topics they follow, plus the
 * most recent topics across their followed forums/tags. EVERY list is fenced by {@see VisibleForumIds} (the same
 * fence the subscription fan-out re-checks per recipient), so a follow can never surface content the follower can
 * no longer see. All reads are bounded IN(...) queries — no per-follow N+1.
 */
final class WatchedController extends Controller
{
    /** Recent-feed cap — the home loop shows the newest N topics across follows, never an unbounded scan. */
    private const FEED_LIMIT = 30;

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $visibleForumIds = VisibleForumIds::for($user);

        $subs = ContentSubscription::query()->where('user_id', $user->getKey())->get();
        $forumIds = $subs->where('subscribable_type', (new Forum)->getMorphClass())->pluck('subscribable_id')->all();
        $tagIds = $subs->where('subscribable_type', (new Tag)->getMorphClass())->pluck('subscribable_id')->all();
        $topicIds = $subs->where('subscribable_type', (new Topic)->getMorphClass())->pluck('subscribable_id')->all();

        // The forum-visibility fence: VisibleForumIds::for() returns null = sees ALL (no clause), [] = sees none,
        // else the id set — so a follow can never surface content the viewer lost access to. Apply it the same
        // null-aware way every other caller does.
        $forums = Forum::query()->whereIn('id', $forumIds)
            ->when($visibleForumIds !== null, fn ($q) => $q->whereIn('id', $visibleForumIds))
            ->orderBy('title')->get();
        $tags = Tag::query()->whereIn('id', $tagIds)->orderBy('name')->get();
        $topics = Topic::query()->whereIn('id', $topicIds)
            ->when($visibleForumIds !== null, fn ($q) => $q->whereIn('forum_id', $visibleForumIds))
            ->where('approved_state', 'approved')->with(['forum', 'lastPostUser'])->latest('last_posted_at')->get();

        // The home loop: recent approved topics in any followed forum OR carrying any followed tag, visibility-fenced.
        $recent = collect();
        if ($forumIds !== [] || $tagIds !== []) {
            $recent = Topic::query()
                ->where('approved_state', 'approved')
                ->when($visibleForumIds !== null, fn ($q) => $q->whereIn('forum_id', $visibleForumIds))
                ->where(function ($q) use ($forumIds, $tagIds) {
                    $q->when($forumIds !== [], fn ($q2) => $q2->orWhereIn('forum_id', $forumIds))
                        ->when($tagIds !== [], fn ($q2) => $q2->orWhereHas('tags', fn ($t) => $t->whereIn('tags.id', $tagIds)));
                })
                ->with(['forum', 'author', 'lastPostUser'])
                ->latest('last_posted_at')
                ->limit(self::FEED_LIMIT)
                ->get();
        }

        return view('watched.index', compact('forums', 'tags', 'topics', 'recent'));
    }
}
