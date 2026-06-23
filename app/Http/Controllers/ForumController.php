<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Forum\ForumNode;
use App\Models\Forum;
use App\Models\Prefix;
use App\Models\Topic;
use App\Models\TopicRead;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
            ->whereNull('club_id') // M1.4: club discussion forums never appear in the main board tree
            ->orderBy('position')->orderBy('id')
            ->with(['children' => fn ($q) => $q->orderBy('position')->orderBy('id')])
            ->get()
            ->map(fn (Forum $forum): array => ForumNode::toArray($forum))
            ->all());

        $tree = array_map(static fn (array $row): ForumNode => ForumNode::fromArray($row), $cached);

        // F6 (member-audit gap): the "latest activity" column now shows the last post's TOPIC + AUTHOR, not just
        // a bare timestamp. Resolve them for every forum's last_topic_id in ONE bounded pass (no per-forum N+1)
        // OUTSIDE the cached tree — the tree stays viewer-independent scalars (RH-9). Rows for forums the viewer
        // cannot see are never rendered, so building the map over the whole tree leaks nothing.
        $lastTopicIds = [];
        foreach ($tree as $node) {
            $lastTopicIds[] = $node->last_topic_id;
            foreach ($node->children as $child) {
                $lastTopicIds[] = $child->last_topic_id;
            }
        }
        $lastTopics = $this->lastActivityTopics($lastTopicIds);

        return view('forum.index', compact('tree', 'viewer', 'lastTopics'));
    }

    public function show(Request $request, Forum $forum): View
    {
        $viewer = $request->user() ?? User::guest();
        // M1.4/M1.5: a CLUB forum's content is gated by club visibility (members/staff for closed/private),
        // not just forum.view. The club gate runs FIRST so a private club consistently 404s (no disclosure)
        // rather than 403ing a guest on the seeded guests-NEVER. A board forum (club_id=null) passes through.
        abort_unless($forum->clubContentVisibleTo($request->user()), 404);
        abort_unless($viewer->canDo('forum.view', $forum->permissionScope()), 403);

        $user = $request->user();
        $canModerate = $user?->canDo('topic.moderate', $forum->permissionScope()) ?? false;

        // Hide pending topics (a held opening post) from everyone but their author and local moderators.
        // Eager-load the starter (author), the last poster (lastPostUser), and the prefix so the board table
        // renders with no per-row queries (bounded eager-loads, not N+1).
        $topics = $forum->topics()
            // firstPost:id,body_text backs the M3 first-post excerpt in ONE bounded eager query (no N+1).
            ->with(['author.groups', 'lastPostUser.groups', 'prefix', 'tags', 'firstPost:id,body_text'])
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

        // M2 (Pillar 3 polish): per-row unread state from the viewer's read watermark, for SIGNED-IN viewers
        // only. ONE batched query over the page's topics (no N+1 — query-budget discipline), mirroring the
        // What's-new unread rule: a topic is unread when it has activity the viewer hasn't seen.
        $unread = [];
        if ($user) {
            $reads = TopicRead::query()
                ->where('user_id', $user->getKey())
                ->whereIn('topic_id', $topics->getCollection()->pluck('id')->all())
                ->get()
                ->keyBy('topic_id');

            foreach ($topics as $topic) {
                $lastRead = $reads->get($topic->id)?->last_read_at;
                $unread[$topic->id] = $topic->last_posted_at !== null
                    && ($lastRead === null || $topic->last_posted_at->gt($lastRead));
            }
        }

        // Posting in a club forum requires active membership (or staff), even where reading is public.
        $canPost = ($user?->canDo('topic.create', $forum->permissionScope()) ?? false)
            && $forum->clubParticipationAllowed($user);

        // Sub-boards (ProBoards-style block above the topic table): the forum's child forums, filtered with
        // the SAME forum.view check the index uses. One bounded query; their counts/last-post are columns.
        $children = $forum->children()
            ->orderBy('position')->orderBy('id')
            ->get()
            ->filter(fn (Forum $child) => $viewer->canDo('forum.view', $child->permissionScope()))
            ->values();

        // F6: the same bounded last-activity (topic + author) resolution the index uses, for the sub-boards rows.
        $lastTopics = $this->lastActivityTopics($children->pluck('last_topic_id')->all());

        // Available prefixes for the filter bar: global + this forum's.
        $prefixes = Prefix::query()
            ->where(function ($q) use ($forum) {
                $q->whereNull('forum_id')->orWhere('forum_id', $forum->id);
            })
            ->orderBy('position')->orderBy('label')
            ->get();

        return view('forum.show', compact('forum', 'topics', 'viewer', 'canPost', 'canModerate', 'children', 'prefixes', 'unread', 'lastTopics'));
    }

    /**
     * Resolve the "latest activity" topic for a set of forum last_topic_id values in a BOUNDED number of
     * queries — one IN over topics + one eager-load of the last poster and their groups — NEVER a per-forum
     * lookup (the board-index hot path). Keyed by topic id for O(1) row lookup. Only APPROVED, non-deleted
     * topics resolve: a pending or removed last topic returns nothing for that forum, so the row falls back to
     * the plain timestamp and never leaks a held topic's title or author.
     *
     * @param  array<int, int|null>  $topicIds
     * @return Collection<int, Topic>
     */
    private function lastActivityTopics(array $topicIds): Collection
    {
        $ids = array_values(array_unique(array_filter($topicIds)));

        if ($ids === []) {
            return collect();
        }

        return Topic::query()
            ->where('approved_state', 'approved')
            ->whereIn('id', $ids)
            ->with('lastPostUser.groups')
            ->get(['id', 'title', 'slug', 'last_post_id', 'last_post_user_id'])
            ->keyBy('id');
    }
}
