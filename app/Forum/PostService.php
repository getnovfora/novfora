<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Forum;

use App\AntiSpam\ContentModerator;
use App\AntiSpam\ContentRejectedException;
use App\AntiSpam\WordFilterService;
use App\Content\ContentRenderer;
use App\Content\Mentions;
use App\Models\Forum;
use App\Models\Post;
use App\Models\PostRevision;
use App\Models\Topic;
use App\Models\User;
use App\Notifications\Notifier;
use App\Permissions\Scope;
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
    public function __construct(
        private readonly ContentRenderer $renderer,
        private readonly ContentModerator $moderator,
        private readonly WordFilterService $words,
        private readonly Notifier $notifier,
    ) {}

    /** Create a topic and its opening post atomically. */
    public function createTopic(User $author, Forum $forum, string $title, string $format, array $canonical): Topic
    {
        $topic = DB::transaction(function () use ($author, $forum, $title, $format, $canonical) {
            $topic = Topic::create([
                'forum_id' => $forum->id,
                'user_id' => $author->id,
                'title' => $title,
                'slug' => $this->slug($title),
                'type' => 'normal',
                'status' => 'open',
                'approved_state' => 'approved',
            ]);

            // The topic inherits its opening post's moderation state — a held OP makes the topic pending too.
            $post = $this->writePost($author, $topic, $format, $canonical);
            if ($post->approved_state !== 'approved') {
                $topic->forceFill(['approved_state' => $post->approved_state])->saveQuietly();
            }
            Audit::log('topic.created', $topic, ['title' => $title]);

            return $topic->refresh();
        });

        // Notify @mentions in the opening post (after commit; only if approved). New topic → no reply target.
        $op = Post::where('topic_id', $topic->getKey())->orderBy('position')->orderBy('id')->first();
        if ($op instanceof Post) {
            $this->dispatchPostNotifications($op);
        }

        return $topic;
    }

    public function reply(User $author, Topic $topic, string $format, array $canonical): Post
    {
        $post = $this->writePost($author, $topic, $format, $canonical);
        Audit::log('post.created', $post);

        $this->dispatchPostNotifications($post);

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

            $rendered = $this->renderer->render($format, $canonical, $this->restrictionsFor($editor, Scope::thread((int) $post->topic_id)));

            // Re-moderate the edit: a 'block' rule rejects it; a hold/flag routes it back to the queue.
            $verdict = $this->moderator->review($editor, $rendered['text']);
            if ($verdict->rejected()) {
                throw new ContentRejectedException($verdict->reasons);
            }

            $post->forceFill([
                'body_format' => $format,
                'body_canonical' => $canonical,
                'body_html_cache' => $this->words->applyReplacements($rendered['html']),
                'body_text' => $this->words->applyReplacements($rendered['text']),
                'approved_state' => $verdict->held() ? 'pending' : $post->approved_state,
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
        $rendered = $this->renderer->render($format, $canonical, $this->restrictionsFor($author, $topic->permissionScope()));

        // Post-time moderation (ADR-0007 §2.4): reject aborts the write; hold stores the post as pending
        // (new-user queue / flagged content); word-filter 'replace' rules rewrite the display.
        $verdict = $this->moderator->review($author, $rendered['text']);
        if ($verdict->rejected()) {
            throw new ContentRejectedException($verdict->reasons);
        }

        $position = (int) Post::where('topic_id', $topic->id)->max('position') + 1;

        return Post::create([
            'topic_id' => $topic->id,
            'user_id' => $author->id,
            'body_format' => $format,
            'body_canonical' => $canonical,
            'body_html_cache' => $this->words->applyReplacements($rendered['html']),
            'body_text' => $this->words->applyReplacements($rendered['text']),
            'position' => $position,
            'ip_address' => request()->ip(),
            'approved_state' => $verdict->held() ? 'pending' : 'approved',
        ]);
    }

    /**
     * Dispatch reply + @mention notifications for an APPROVED post (data-model §7). Called after the write
     * commits, and again when staff approve a held post — so a pending post notifies once, at approval, not at
     * write time. Reply → the topic's author; mentions → the users named in the post's canonical body.
     */
    public function dispatchPostNotifications(Post $post): void
    {
        if ($post->approved_state !== 'approved') {
            return;
        }

        $topic = Topic::find($post->topic_id);
        $author = $post->user_id ? User::find($post->user_id) : null;
        if (! $topic instanceof Topic || ! $author instanceof User) {
            return;
        }

        $payload = [
            'thread_id' => (int) $topic->id,
            'topic_title' => $topic->title,
            'post_id' => (int) $post->id,
            'url' => route('topics.show', $topic->id),
        ];

        // A reply (any earlier post exists) notifies the topic's author.
        $isReply = Post::where('topic_id', $post->topic_id)->where('id', '<', $post->id)->exists();
        if ($isReply && $topic->user_id && (int) $topic->user_id !== (int) $author->getKey()) {
            $opAuthor = User::find($topic->user_id);
            if ($opAuthor instanceof User) {
                $this->notifier->send($opAuthor, 'reply', $author, $payload);
            }
        }

        // @mentions in the canonical body (cast to array — tiptap docs are arrays; markdown yields none).
        foreach (Mentions::idsIn((array) $post->body_canonical) as $id) {
            $mentioned = User::find($id);
            if ($mentioned instanceof User) {
                $this->notifier->send($mentioned, 'mention', $author, $payload);
            }
        }
    }

    /**
     * Anti-spam content suppression for the author at this scope (security §2.4 / ADR-0007). An author who
     * lacks `post.links` / `post.images` (e.g. a TL0 account, where the gate is a hard NEVER) has those
     * elements suppressed from the rendered HTML — links keep their text, images are dropped. Resolved
     * through the SAME permission engine as everything else (no second system).
     *
     * @return list<string>
     */
    private function restrictionsFor(User $author, Scope $scope): array
    {
        $restrict = [];
        if (! $author->canDo('post.links', $scope)) {
            $restrict[] = 'links';
        }
        if (! $author->canDo('post.images', $scope)) {
            $restrict[] = 'images';
        }

        return $restrict;
    }

    private function slug(string $title): string
    {
        return Str::slug(Str::limit($title, 60, '')) ?: 'topic';
    }
}
