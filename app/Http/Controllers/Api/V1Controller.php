<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Forum\PostService;
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
        $forums = Forum::query()->where('type', 'forum')->orderBy('position')->orderBy('id')->get()
            ->filter(fn (Forum $forum): bool => $user->canDo('forum.view', $forum->permissionScope()))
            ->map(fn (Forum $forum): array => $this->forumData($forum))
            ->values();

        return response()->json(['data' => $forums->all()]);
    }

    public function topics(Request $request, Forum $forum): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($user->canDo('forum.view', $forum->permissionScope()), 403);

        $topics = Topic::query()->where('forum_id', $forum->getKey())
            ->orderByDesc('id')->paginate(min(50, max(1, (int) $request->integer('per_page', 20))));

        return $this->paginated($topics, fn (Topic $topic): array => $this->topicData($topic));
    }

    public function topic(Request $request, Topic $topic): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($user->canDo('forum.view', $topic->permissionScope()), 403);

        $posts = Post::query()->where('topic_id', $topic->getKey())->where('approved_state', 'approved')
            ->orderBy('position')->orderBy('id')
            ->paginate(min(50, max(1, (int) $request->integer('per_page', 20))));

        return response()->json([
            'data' => $this->topicData($topic),
            'posts' => array_map(fn (Post $post): array => $this->postData($post), $posts->items()),
            'meta' => $this->paginationMeta($posts),
        ]);
    }

    public function createPost(Request $request, Topic $topic, PostService $posts): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($user->canDo('post.create', $topic->permissionScope()), 403);

        $data = $request->validate(['body' => ['required', 'string', 'max:50000']]);
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
