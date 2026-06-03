<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\User;
use App\Permissions\Scope;
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
        $q = trim((string) $request->query('q', ''));
        $viewer = $request->user() ?? User::guest();

        $results = $q === '' ? collect()
            : $search->posts($q, 25)->filter(fn (Post $p) => $this->visible($viewer, $p))->values();

        return view('search.index', ['q' => $q, 'results' => $results]);
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
        return $post->topic !== null && $viewer->canDo('forum.view', Scope::thread((int) $post->topic_id));
    }
}
