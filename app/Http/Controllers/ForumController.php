<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Forum\ForumNode;
use App\Models\Forum;
use App\Models\Prefix;
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
        //
        // RH-9: cache PRIMITIVES only. config/cache.php sets serializable_classes => false, so a
        // serializing store (database/file/redis — every real deployment) would deserialize any cached
        // object into a __PHP_Incomplete_Class and 500 the view (`isCategory() on string`). We cache a
        // plain scalar array tree and rehydrate value objects below, OUTSIDE the cache — never a model.
        /** @var list<array<string, mixed>> $cached */
        $cached = Cache::remember('forum.index.tree', now()->addSeconds(60), fn () => Forum::query()
            ->whereNull('parent_id')
            ->orderBy('position')->orderBy('id')
            ->with(['children' => fn ($q) => $q->orderBy('position')->orderBy('id')])
            ->get()
            ->map(fn (Forum $forum): array => ForumNode::toArray($forum))
            ->all());

        $tree = array_map(static fn (array $row): ForumNode => ForumNode::fromArray($row), $cached);

        return view('forum.index', compact('tree', 'viewer'));
    }

    public function show(Request $request, Forum $forum): View
    {
        $viewer = $request->user() ?? User::guest();
        abort_unless($viewer->canDo('forum.view', $forum->permissionScope()), 403);

        $user = $request->user();
        $canModerate = $user?->canDo('topic.moderate', $forum->permissionScope()) ?? false;

        // Hide pending topics (a held opening post) from everyone but their author and local moderators.
        // Eager-load the starter (author), the last poster (lastPostUser), and the prefix so the board table
        // renders with no per-row queries (bounded eager-loads, not N+1).
        $topics = $forum->topics()
            ->with(['author.groups', 'lastPostUser.groups', 'prefix'])
            ->unless($canModerate, fn ($q) => $q->where(function ($q2) use ($user) {
                $q2->where('approved_state', 'approved');
                if ($user) {
                    $q2->orWhere('user_id', $user->getKey());
                }
            }))
            ->when(request('prefix'), fn ($q) => $q->where('prefix_id', request('prefix')))
            ->orderByDesc('is_pinned')
            ->orderByRaw('last_posted_at IS NULL') // posted topics before empty ones
            ->orderByDesc('last_posted_at')
            ->orderByDesc('id')
            ->paginate(20);

        $canPost = $user?->canDo('topic.create', $forum->permissionScope()) ?? false;

        // Sub-boards (ProBoards-style block above the topic table): the forum's child forums, filtered with
        // the SAME forum.view check the index uses. One bounded query; their counts/last-post are columns.
        $children = $forum->children()
            ->orderBy('position')->orderBy('id')
            ->get()
            ->filter(fn (Forum $child) => $viewer->canDo('forum.view', $child->permissionScope()))
            ->values();

        // Available prefixes for the filter bar: global + this forum's.
        $prefixes = Prefix::query()
            ->where(function ($q) use ($forum) {
                $q->whereNull('forum_id')->orWhere('forum_id', $forum->id);
            })
            ->orderBy('position')->orderBy('label')
            ->get();

        return view('forum.show', compact('forum', 'topics', 'viewer', 'canPost', 'children', 'prefixes'));
    }
}
