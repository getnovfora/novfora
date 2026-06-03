<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Forum;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ForumController extends Controller
{
    public function index(Request $request): View
    {
        $viewer = $request->user() ?? User::guest();

        // Fragment-cache the viewer-independent category tree + counts. Tier-graceful: served from the
        // configured store (DB on baseline, Redis on enhanced); a short TTL keeps it eventually consistent
        // and it is NEVER load-bearing — per-viewer forum.view filtering still runs every request, and a
        // dead cache simply re-queries. (Per-post content render is already cached in body_html_cache.)
        $tree = Cache::remember('forum.index.tree', now()->addSeconds(60), fn () => Forum::query()
            ->whereNull('parent_id')
            ->orderBy('position')->orderBy('id')
            ->with(['children' => fn ($q) => $q->orderBy('position')->orderBy('id')])
            ->get());

        return view('forum.index', compact('tree', 'viewer'));
    }

    public function show(Request $request, Forum $forum): View
    {
        $viewer = $request->user() ?? User::guest();
        abort_unless($viewer->canDo('forum.view', $forum->permissionScope()), 403);

        $topics = $forum->topics()
            ->with('author')
            ->orderByDesc('is_pinned')
            ->orderByRaw('last_posted_at IS NULL') // posted topics before empty ones
            ->orderByDesc('last_posted_at')
            ->orderByDesc('id')
            ->paginate(20);

        $canPost = $request->user()?->canDo('topic.create', $forum->permissionScope()) ?? false;

        return view('forum.show', compact('forum', 'topics', 'viewer', 'canPost'));
    }
}
