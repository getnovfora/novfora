<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Discovery\FeedBuilder;
use App\Models\Forum;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use App\Permissions\VisibleForumIds;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * RSS/Atom feeds per forum / topic / user (discovery 3.2). Feeds are PUBLIC (readers don't authenticate), so
 * each exposes only GUEST-visible content — a private forum's feed 404s for everyone, exactly like the
 * sitemap's guest gate. Cached for a few minutes (the same discipline as the sitemap).
 */
class FeedController extends Controller
{
    public function __construct(private readonly FeedBuilder $builder) {}

    public function forum(Forum $forum): Response
    {
        abort_unless($forum->type === 'forum', 404);
        abort_unless(User::guest()->canDo('forum.view', $forum->permissionScope()), 404);

        $xml = Cache::remember("novfora.feed.forum.{$forum->id}", now()->addMinutes(15), function () use ($forum): string {
            $topics = $forum->topics()->where('approved_state', 'approved')->whereNotNull('last_posted_at')
                ->orderByDesc('last_posted_at')->limit(30)->get(['id', 'slug', 'title', 'last_posted_at']);

            return $this->builder->atom(
                $this->meta($forum->title, route('forums.show', $forum), route('feeds.forum', $forum), $topics->first()?->last_posted_at),
                $topics->map(fn (Topic $t): array => [
                    'title' => (string) $t->title,
                    'url' => route('topics.show', $t),
                    'id' => route('topics.show', $t),
                    'updated' => ($t->last_posted_at ?? now())->toAtomString(),
                ])->all(),
            );
        });

        return $this->respond($xml);
    }

    public function topic(Topic $topic): Response
    {
        abort_if($topic->trashed() || $topic->moved_to_topic_id !== null, 404);
        abort_unless(User::guest()->canDo('forum.view', $topic->permissionScope()), 404);

        $xml = Cache::remember("novfora.feed.topic.{$topic->id}", now()->addMinutes(15), function () use ($topic): string {
            $posts = Post::query()->where('topic_id', $topic->getKey())->where('approved_state', 'approved')->with('author')
                ->orderByDesc('position')->orderByDesc('id')->limit(30)->get();

            return $this->builder->atom(
                $this->meta($topic->title, route('topics.show', $topic), route('feeds.topic', $topic), $posts->first()?->created_at),
                $posts->map(fn (Post $p): array => [
                    'title' => 'Re: '.$topic->title,
                    'url' => route('topics.show', $topic).'#post-'.$p->getKey(),
                    'id' => route('topics.show', $topic).'#post-'.$p->getKey(),
                    'updated' => ($p->created_at ?? now())->toAtomString(),
                    'summary' => Str::limit((string) $p->body_text, 300),
                    'author' => $p->author instanceof User ? (string) $p->author->username : '',
                ])->all(),
            );
        });

        return $this->respond($xml);
    }

    public function user(User $user): Response
    {
        $xml = Cache::remember("novfora.feed.user.{$user->id}", now()->addMinutes(15), function () use ($user): string {
            $visible = VisibleForumIds::for(User::guest());
            $topics = Topic::query()->where('user_id', $user->getKey())
                ->where('approved_state', 'approved')->whereNotNull('last_posted_at')
                ->when($visible === [], fn ($q) => $q->whereRaw('1 = 0'))
                ->when(is_array($visible), fn ($q) => $q->whereIn('forum_id', $visible))
                ->orderByDesc('last_posted_at')->limit(30)->get(['id', 'slug', 'title', 'last_posted_at']);

            return $this->builder->atom(
                $this->meta(
                    ($user->display_name ?? $user->username).' — topics',
                    route('profiles.show', $user), route('feeds.user', $user), $topics->first()?->last_posted_at,
                ),
                $topics->map(fn (Topic $t): array => [
                    'title' => (string) $t->title,
                    'url' => route('topics.show', $t),
                    'id' => route('topics.show', $t),
                    'updated' => ($t->last_posted_at ?? now())->toAtomString(),
                ])->all(),
            );
        });

        return $this->respond($xml);
    }

    /** @return array{title:string,url:string,selfUrl:string,updated:string} */
    private function meta(string $title, string $url, string $selfUrl, mixed $updated): array
    {
        return [
            'title' => $title.' · '.config('app.name', 'NovFora'),
            'url' => $url,
            'selfUrl' => $selfUrl,
            'updated' => ($updated ?? now())->toAtomString(),
        ];
    }

    private function respond(string $xml): Response
    {
        return response($xml, 200)->header('Content-Type', 'application/atom+xml; charset=UTF-8');
    }
}
