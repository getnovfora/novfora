<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Forum;
use App\Models\Post;
use App\Models\User;
use App\Permissions\Scope;
use App\Search\SearchQuery;
use App\Search\SearchService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Keyword search (ADR-0010). Public — anonymous resolves as the Guests group — with results filtered to
 * forums the viewer can see (never leak a post from a hidden forum). `index` renders a page; `suggest`
 * powers inline predictive results.
 */
class SearchController extends Controller
{
    public function index(Request $request, SearchService $search): View
    {
        $viewer = $request->user() ?? User::guest();
        $query = SearchQuery::fromRequest($request, $viewer, 25);

        // A search runs when there's a keyword OR any facet narrows it; visibility is enforced inside search().
        $results = ($query->term === '' && ! $query->hasFacets()) ? collect() : $search->search($query);

        // Forums the viewer can see, for the forum-facet dropdown (categories excluded — only postable nodes).
        $forums = Forum::query()->where('type', 'forum')->orderBy('title')->get()
            ->filter(fn (Forum $f) => $viewer->canDo('forum.view', $f->permissionScope()))
            ->values();

        return view('search.index', [
            'q' => $query->term,
            'query' => $query,
            'results' => $results,
            'forums' => $forums,
        ]);
    }

    public function suggest(Request $request, SearchService $search): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $viewer = $request->user() ?? User::guest();

        $data = $q === '' ? collect()
            : $search->posts($q, 6)
                ->filter(fn (Post $p) => $this->visible($viewer, $p))
                ->map(fn (Post $p) => [
                    'title' => $p->topic?->title,
                    'url' => route('topics.show', $p->topic_id),
                    'snippet' => Str::limit($p->body_text, 90),
                ])->values();

        return response()->json(['data' => $data]);
    }

    private function visible(User $viewer, Post $post): bool
    {
        $topic = $post->topic;
        if ($topic === null || ! $viewer->canDo('forum.view', Scope::thread((int) $post->topic_id))) {
            return false;
        }
        // M1.5: a typeahead hit in a club forum is suppressed unless the viewer may see the club's content
        // (the faceted search path is already gated by VisibleForumIds; this guards the Scout typeahead path).
        $forum = $topic->forum;

        return $forum instanceof Forum && $forum->clubContentVisibleTo($viewer);
    }
}
