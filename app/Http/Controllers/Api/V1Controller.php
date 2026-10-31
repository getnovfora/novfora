<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\AntiSpam\PostRateLimiter;
use App\Forum\PostService;
use App\Forum\ReplyGate;
use App\Forum\TopicVisibility;
use App\Http\Controllers\Controller;
use App\Models\Forum;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The versioned read/write REST API (ADR-0033, /api/v1). Every action authorizes through the EXISTING
 * permission engine on the token-resolved user, so the API surface can never exceed the user's own rights:
 * forum/topic reads are gated on `forum.view`, and creating a reply is gated on `post.create` and goes through
 * the same PostService (trust gating, sanitisation, approval state) as the web path. Responses are explicitly
 * shaped — internal columns are never echoed — and collections are paginated.
 */
final class V1Controller extends Controller
{
    public function me(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return response()->json(['data' => [
            'id' => $user->getKey(),
            'username' => $user->username,
            'display_name' => $user->display_name,
            'trust_level' => (int) $user->trust_level,
            'reputation_points' => (int) $user->reputation_points,
        ]]);
    }

    public function forums(Request $request): JsonResponse
    {
        $user = $this->user($request);
        // Club privacy (M1.5): a club forum is only listed when its content is visible to the token's user.
        $forums = Forum::query()->where('type', 'forum')->orderBy('position')->orderBy('id')->get()
            ->filter(fn (Forum $forum): bool => $user->canDo('forum.view', $forum->permissionScope())
                && $forum->clubContentVisibleTo($user))
            ->map(fn (Forum $forum): array => $this->forumData($forum))
            ->values();

        return response()->json(['data' => $forums->all()]);
    }

    public function topics(Request $request, Forum $forum, TopicVisibility $visibility): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($forum->clubContentVisibleTo($user), 404); // club content gate FIRST (no disclosure)
        abort_unless($user->canDo('forum.view', $forum->permissionScope()), 403);

        // NF-03: the same "topics visible to viewer" selector as the web board listing — the pending-topic fence
        // and per-thread forum.view denials apply BEFORE pagination, so neither rows nor meta.total disclose a
        // hidden topic.
        $query = Topic::query()->where('forum_id', $forum->getKey());
        $visibility->constrain($query, $forum, $user);
        $topics = $query->orderByDesc('id')->paginate(min(50, max(1, (int) $request->integer('per_page', 20))));

        return $this->paginated($topics, fn (Topic $topic): array => $this->topicData($topic));
    }

    public function topic(Request $request, Topic $topic, TopicVisibility $visibility): JsonResponse
    {
        $user = $this->user($request);
        $topicForum = $topic->forum;
        abort_unless($topicForum instanceof Forum && $topicForum->clubContentVisibleTo($user), 404);
        abort_unless($user->canDo('forum.view', $topic->permissionScope()), 403);
        // NF-03: the web topic page's moderation fence — a pending topic exists only for its author and
        // topic.moderate holders; everyone else gets the same no-disclosure 404 (no title/metadata leak).
        abort_unless($visibility->canView($user, $topic), 404);

        $posts = Post::query()->where('topic_id', $topic->getKey())->where('approved_state', 'approved')
            ->orderBy('position')->orderBy('id')
            ->paginate(min(50, max(1, (int) $request->integer('per_page', 20))));

        return response()->json([
            'data' => $this->topicData($topic),
            'posts' => array_map(fn (Post $post): array => $this->postData($post), $posts->items()),
            'meta' => $this->paginationMeta($posts),
        ]);
    }

    public function createPost(Request $request, Topic $topic, PostService $posts, ReplyGate $gate, PostRateLimiter $limiter): JsonResponse
    {
        $user = $this->user($request);
        // NF-01: the same ReplyGate as the web composer and the scheduler — thread-scope forum.view +
        // post.create, the pending-topic fence, the lock, and club visibility/participation (404 = no
        // disclosure), so the API can never accept a reply the web path would refuse.
        $gate->authorize($user, $topic);

        $data = $request->validate(['body' => ['required', 'string', 'max:50000']]);

        // NF-06: the same trust-based posting budget as the web composers (one bucket per member), so the API
        // is never a second allowance. Checked after validation, like the composer, so a malformed request
        // doesn't spend it.
        if (! $limiter->attempt($user)) {
            abort(429, 'You are posting too quickly — please wait a moment and try again.', [
                'Retry-After' => (string) max(1, $limiter->availableIn($user)),
            ]);
        }

        $post = $posts->reply($user, $topic, 'markdown', ['source' => $data['body']]);

        return response()->json(['data' => $this->postData($post)], 201);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    /** @return array<string,mixed> */
    private function forumData(Forum $forum): array
    {
        return ['id' => $forum->getKey(), 'slug' => $forum->slug, 'title' => $forum->title, 'type' => $forum->type];
    }

    /** @return array<string,mixed> */
    private function topicData(Topic $topic): array
    {
        return [
            'id' => $topic->getKey(),
            'slug' => $topic->slug,
            'title' => $topic->title,
            'forum_id' => (int) $topic->forum_id,
            'created_at' => $topic->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    private function postData(Post $post): array
    {
        return [
            'id' => $post->getKey(),
            'topic_id' => (int) $post->topic_id,
            'author_id' => $post->user_id === null ? null : (int) $post->user_id,
            'html' => (string) $post->body_html_cache,
            'created_at' => $post->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  LengthAwarePaginator<int,covariant \Illuminate\Database\Eloquent\Model>  $paginator
     * @param  callable(mixed):array<string,mixed>  $map
     */
    private function paginated($paginator, callable $map): JsonResponse
    {
        return response()->json([
            'data' => array_map($map, $paginator->items()),
            'meta' => $this->paginationMeta($paginator),
        ]);
    }

    /** @param LengthAwarePaginator<int,covariant \Illuminate\Database\Eloquent\Model> $paginator */
    private function paginationMeta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }
}
