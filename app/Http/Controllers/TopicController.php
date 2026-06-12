<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Forum\PollService;
use App\Forum\ReactionService;
use App\Models\Post;
use App\Models\Topic;
use App\Models\TopicRead;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class TopicController extends Controller
{
    public function show(Request $request, Topic $topic, ReactionService $reactions, PollService $polls): View
    {
        $viewer = $request->user() ?? User::guest();
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
        $canReply = $topic->status !== 'locked' && ($user?->canDo('post.create', $scope) ?? false);
        $canModerate = $user?->canDo('topic.moderate', $scope) ?? false;

        // Moderation-queue visibility (ADR-0007 §2.4): pending posts are hidden from everyone except their
        // author and staff who can moderate here. Approved posts are visible to all.
        // Eager-load the author's groups too so the poster sidebar's staff/role badge ($author->isStaff())
        // resolves from already-loaded data — one bounded query, never per-post (N+1).
        $posts = $topic->posts()
            ->with(['author.groups', 'revisions'])
            ->unless($canModerate, fn ($q) => $q->where(function ($q2) use ($user) {
                $q2->where('approved_state', 'approved');
                if ($user) {
                    $q2->orWhere('user_id', $user->getKey());
                }
            }))
            ->orderBy('position')->orderBy('id')
            ->paginate(15);

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

        // SEO description = an excerpt of the opening post's text projection (security-safe; no HTML).
        $description = Str::limit((string) Post::where('topic_id', $topic->getKey())
            ->orderBy('position')->orderBy('id')->value('body_text'), 160);

        return view('forum.topic', compact(
            'topic', 'posts', 'viewer', 'user', 'canReply', 'canModerate', 'description',
            'reactionCounts', 'viewerReactions', 'canReact',
            'pollData', 'pollVotes', 'canVote', 'canViewHistory',
        ));
    }
}
