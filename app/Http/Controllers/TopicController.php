<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Community\IgnoreService;
use App\Discovery\RecommendationService;
use App\Forum\BookmarkService;
use App\Forum\PollService;
use App\Forum\ReactionService;
use App\Models\Forum;
use App\Models\Post;
use App\Models\Topic;
use App\Models\TopicRead;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class TopicController extends Controller
{
    public function show(Request $request, Topic $topic, ReactionService $reactions, PollService $polls, BookmarkService $bookmarks, IgnoreService $ignores, RecommendationService $recommendations): View|RedirectResponse
    {
        $viewer = $request->user() ?? User::guest();

        // A merged topic (P2-M4) is a permanent redirect shell: 301 to its target so old links never break. The
        // route resolves withTrashed so this can fire for the soft-deleted source; any OTHER trashed topic is
        // genuinely gone → 404 (recycle-bin semantics preserved). We resolve moved_to_topic_id TRANSITIVELY to
        // the chain's terminus (a chain of merges collapses to a single 301, never an N-hop redirect chain that
        // browsers would abort) and only redirect when the viewer may actually see the TARGET's forum —
        // otherwise 404, so a forbidden viewer never learns the target's id (no existence/metadata leak).
        if ($topic->moved_to_topic_id !== null) {
            $terminal = $topic;
            for ($hop = 0; $hop < 10 && $terminal->moved_to_topic_id !== null; $hop++) {
                $next = Topic::withTrashed()->find($terminal->moved_to_topic_id);
                if (! $next instanceof Topic) {
                    break;
                }
                $terminal = $next;
            }

            abort_if($terminal->getKey() === $topic->getKey() || $terminal->trashed(), 404);
            abort_unless($viewer->canDo('forum.view', $terminal->permissionScope()), 404);

            return redirect()->route('topics.show', $terminal->getKey(), 301);
        }
        if ($topic->trashed()) {
            abort(404);
        }

        // M1.4/M1.5: a topic in a CLUB forum is gated by club visibility FIRST (404 = no disclosure), so a
        // private club never 403s a guest on the seeded guests-NEVER. A board topic passes through.
        $topicForum = $topic->forum;
        abort_if($topicForum instanceof Forum && ! $topicForum->clubContentVisibleTo($request->user()), 404);
        abort_unless($viewer->canDo('forum.view', $topic->permissionScope()), 403);

        // View count (P2-M3) — throttled per viewer (or guest session) to one count per topic per hour, so a
        // refresh/F5 storm can't inflate it. A raw increment, no model hydration → no events fire.
        $viewKey = $viewer->exists
            ? "topic.viewed.u{$viewer->getKey()}.t{$topic->getKey()}"
            : 'topic.viewed.s'.$request->session()->getId().'.t'.$topic->getKey();
        if (Cache::add($viewKey, 1, now()->addHour())) {
            Topic::whereKey($topic->getKey())->increment('view_count');
        }

        // The view reads the topic's parent forum (breadcrumbs), author (JSON-LD), prefix (badge), and tags;
        // load them once here so none lazy-loads at render time.
        $topic->loadMissing(['forum', 'author', 'prefix', 'tags']);

        $user = $request->user();

        // Mark this topic read for the viewer (the unread / "what's new" watermark, data-model §9).
        if ($user instanceof User) {
            TopicRead::updateOrCreate(
                ['user_id' => $user->getKey(), 'topic_id' => $topic->getKey()],
                ['last_read_at' => now()],
            );
        }
        $scope = $topic->permissionScope();
        $canReply = $topic->isReplyable() && ($user?->canDo('post.create', $scope) ?? false);
        $canModerate = $user?->canDo('topic.moderate', $scope) ?? false;

        // Moderation-queue visibility (ADR-0007 §2.4): pending posts are hidden from everyone except their
        // author and staff who can moderate here. Approved posts are visible to all.
        // Eager-load the author's groups too so the poster sidebar's staff/role badge ($author->isStaff())
        // resolves from already-loaded data — one bounded query, never per-post (N+1).
        // Per-viewer display preferences (P2-M4): posts-per-page + thread sort order. A guest resolves to the
        // site defaults (15 / oldest). Newest-first reverses the position+id order so page 1 holds the latest.
        $newestFirst = $viewer->threadSortNewestFirst();
        $posts = $topic->posts()
            ->with(['author.groups', 'revisions'])
            ->unless($canModerate, fn ($q) => $q->where(function ($q2) use ($user) {
                $q2->where('approved_state', 'approved');
                if ($user) {
                    $q2->orWhere('user_id', $user->getKey());
                }
            }))
            ->when($newestFirst,
                fn ($q) => $q->orderByDesc('position')->orderByDesc('id'),
                fn ($q) => $q->orderBy('position')->orderBy('id'))
            ->paginate($viewer->postsPerPage());

        // Reactions for this page: RH-9-cached per-type tallies + the viewer's own picks. `canReact` is
        // forum-scoped (shared by every post here) so it resolves ONCE, never per post (N+1 guard).
        $postIds = $posts->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $reactionCounts = $reactions->countsForTopic($topic->getKey(), $postIds);
        $viewerReactions = $reactions->viewerReactions($viewer, $postIds);
        $canReact = $user instanceof User && $user->canDo('react.create', $scope);

        // The topic's poll (over the topics.poll_id seam), if any: RH-9-cached display data + the viewer's
        // own picks, with poll.vote resolved once for the page. Only touch the polls table when a poll exists
        // — a poll-less topic (the common case) pays zero poll queries.
        $poll = $topic->poll_id !== null ? $topic->loadMissing('poll')->poll : null;
        $pollData = $poll ? $polls->displayData($poll) : null;
        $pollVotes = $poll ? $polls->votedOptionIds($viewer, $poll) : [];
        $canVote = $poll !== null && $user instanceof User && $user->canDo('poll.vote', $scope);

        // post.history.view is forum-scoped, so resolve it ONCE for the page; the post footer additionally
        // lets an author see their OWN post's history (decided per post from already-loaded columns).
        $canViewHistory = $user instanceof User && $user->canDo('post.history.view', $scope);

        // Bookmarks (member tool 2.1): saving is ungated, so the only gate is "is signed in". Resolve the
        // viewer's saved post ids for THIS page in one batched query (never per post), plus the topic itself.
        $canBookmark = $user instanceof User;
        $viewerBookmarks = $canBookmark ? $bookmarks->bookmarkedIds($user, Post::class, $postIds) : [];
        $topicBookmarked = $canBookmark && $bookmarks->isBookmarked($user, $topic);

        // Ignored members (member tool 2.2): the viewer's ignore set, used by the post loop to collapse their
        // posts (never a staff member's — that guard lives in the view). Empty for guests.
        $ignoredIds = $user instanceof User ? $ignores->ignoredIds($user) : [];

        // Related topics (discovery 3.3): share-a-tag, topped up from the same forum, permission-safe.
        $related = $recommendations->related($topic, $viewer, 5);

        // SEO description = an excerpt of the opening post's text projection (security-safe; no HTML).
        $description = Str::limit((string) Post::where('topic_id', $topic->getKey())
            ->orderBy('position')->orderBy('id')->value('body_text'), 160);

        return view('forum.topic', compact(
            'topic', 'posts', 'viewer', 'user', 'canReply', 'canModerate', 'description',
            'reactionCounts', 'viewerReactions', 'canReact',
            'pollData', 'pollVotes', 'canVote', 'canViewHistory',
            'canBookmark', 'viewerBookmarks', 'topicBookmarked', 'ignoredIds', 'related',
        ));
    }
}
