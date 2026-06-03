<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Forum;

use App\Content\ContentRenderer;
use App\Models\Forum;
use App\Models\Post;
use App\Models\PostRevision;
use App\Models\Topic;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The write path for forum content. Renders canonical → sanitized HTML + text (ADR-0005, the server is the
 * security boundary), maintains edit revisions, and audits. Authorization is the caller's responsibility
 * (the Livewire components / controllers gate every action through the permission engine before calling in).
 */
final class PostService
{
    public function __construct(private readonly ContentRenderer $renderer) {}

    /** Create a topic and its opening post atomically. */
    public function createTopic(User $author, Forum $forum, string $title, string $format, array $canonical): Topic
    {
        return DB::transaction(function () use ($author, $forum, $title, $format, $canonical) {
            $topic = Topic::create([
                'forum_id' => $forum->id,
                'user_id' => $author->id,
                'title' => $title,
                'slug' => $this->slug($title),
                'type' => 'normal',
                'status' => 'open',
                'approved_state' => 'approved',
            ]);

            $this->writePost($author, $topic, $format, $canonical);
            Audit::log('topic.created', $topic, ['title' => $title]);

            return $topic->refresh();
        });
    }

    public function reply(User $author, Topic $topic, string $format, array $canonical): Post
    {
        $post = $this->writePost($author, $topic, $format, $canonical);
        Audit::log('post.created', $post);

        return $post;
    }

    public function editPost(User $editor, Post $post, string $format, array $canonical, ?string $reason = null): Post
    {
        return DB::transaction(function () use ($editor, $post, $format, $canonical, $reason) {
            // Snapshot the prior canonical as a revision BEFORE overwriting (edit history, data-model §2).
            PostRevision::create([
                'post_id' => $post->id,
                'editor_id' => $editor->id,
                'body_format' => $post->body_format,
                'body_canonical' => $post->body_canonical,
                'reason' => $reason,
            ]);

            $rendered = $this->renderer->render($format, $canonical);
            $post->forceFill([
                'body_format' => $format,
                'body_canonical' => $canonical,
                'body_html_cache' => $rendered['html'],
                'body_text' => $rendered['text'],
                'edited_at' => now(),
                'edited_by' => $editor->id,
                'edit_count' => $post->edit_count + 1,
            ])->save();

            Audit::log('post.edited', $post, ['reason' => $reason]);

            return $post;
        });
    }

    private function writePost(User $author, Topic $topic, string $format, array $canonical): Post
    {
        $rendered = $this->renderer->render($format, $canonical);
        $position = (int) Post::where('topic_id', $topic->id)->max('position') + 1;

        return Post::create([
            'topic_id' => $topic->id,
            'user_id' => $author->id,
            'body_format' => $format,
            'body_canonical' => $canonical,
            'body_html_cache' => $rendered['html'],
            'body_text' => $rendered['text'],
            'position' => $position,
            'ip_address' => request()->ip(),
            'approved_state' => 'approved',
        ]);
    }

    private function slug(string $title): string
    {
        return Str::slug(Str::limit($title, 60, '')) ?: 'topic';
    }
}
