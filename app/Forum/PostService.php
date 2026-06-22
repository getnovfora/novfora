<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Forum;

use App\AntiSpam\ContentModerator;
use App\AntiSpam\ContentRejectedException;
use App\AntiSpam\WordFilterService;
use App\Content\ContentRenderer;
use App\Content\Mentions;
use App\Content\Oembed\EmbedRenderer;
use App\Events\PostCreated;
use App\Events\TopicCreated;
use App\Models\Forum;
use App\Models\Post;
use App\Models\PostRevision;
use App\Models\Prefix;
use App\Models\SpamAssessment;
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
        private readonly EmbedRenderer $embeds,
        private readonly AttachmentService $attachments,
    ) {}

    /**
     * Build a post's display HTML: render → word-filter → inject trusted oEmbed embeds (P2-M1). The embed
     * injection runs AFTER the sanitizer (inside ContentRenderer) and the word filter, so iframes are never
     * seen by the sanitizer (it still strips raw ones) and are never word-filtered. A no-op for content
     * without embed nodes (e.g. markdown).
     *
     * @param  array<string,mixed>  $canonical
     */
    private function displayHtml(string $renderedHtml, array $canonical): string
    {
        return $this->embeds->inject($this->words->applyReplacements($renderedHtml), $canonical);
    }

    /** Create a topic and its opening post atomically. */
    public function createTopic(User $author, Forum $forum, string $title, string $format, array $canonical, ?int $prefixId = null): Topic
    {
        $topic = DB::transaction(function () use ($author, $forum, $title, $format, $canonical, $prefixId) {
            // Validate the prefix: it must be global (forum_id = null) or belong to this forum.
            $resolvedPrefixId = null;
            if ($prefixId !== null) {
                $prefix = Prefix::find($prefixId);
                if ($prefix !== null && ($prefix->forum_id === null || (int) $prefix->forum_id === (int) $forum->id)) {
                    $resolvedPrefixId = $prefix->id;
                }
            }

            $topic = Topic::create([
                'forum_id' => $forum->id,
                'user_id' => $author->id,
                'title' => $title,
                'slug' => $this->slug($title),
                'type' => 'normal',
                'status' => 'open',
                'approved_state' => 'approved',
                'prefix_id' => $resolvedPrefixId,
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

        // Activity feed (P2-M3): log topic.created post-commit, only for an APPROVED topic (a held topic is
        // visible to author+mods only and must not leak into the public feed).
        if ($topic->approved_state === 'approved') {
            TopicCreated::dispatch($topic);
        }

        return $topic;
    }

    public function reply(User $author, Topic $topic, string $format, array $canonical): Post
    {
        $post = $this->writePost($author, $topic, $format, $canonical);
        Audit::log('post.created', $post);

        $this->dispatchPostNotifications($post);

        // Activity feed (P2-M3): log post.created post-commit, only for an APPROVED reply (a held reply must
        // not leak into the public feed).
        if ($post->approved_state === 'approved') {
            PostCreated::dispatch($post);
        }

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
                'body_html_cache' => $this->displayHtml($rendered['html'], $canonical),
                'body_text' => $this->words->applyReplacements($rendered['text']),
                'approved_state' => $verdict->held() ? 'pending' : $post->approved_state,
                'edited_at' => now(),
                'edited_by' => $editor->id,
                'edit_count' => $post->edit_count + 1,
            ])->save();

            // Bind any newly-referenced draft attachments owned by the EDITOR (ADR-0094). Author-owned +
            // unattached only, so an edit can never pull another member's file (or an already-attached one)
            // into this post.
            $this->attachments->attachToPost($post, $canonical, $editor->id);

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

        $post = Post::create([
            'topic_id' => $topic->id,
            'user_id' => $author->id,
            'body_format' => $format,
            'body_canonical' => $canonical,
            'body_html_cache' => $this->displayHtml($rendered['html'], $canonical),
            'body_text' => $this->words->applyReplacements($rendered['text']),
            'position' => $position,
            'ip_address' => request()->ip(),
            'approved_state' => $verdict->held() ? 'pending' : 'approved',
        ]);

        // Bind the author's own draft attachments referenced in this body to the post (ADR-0094). Until
        // associated they are uploader-only orphans, so readers would 403 on the image; this is what makes a
        // posted attachment visible under the thread's forum.view gate. Author-owned + unattached only.
        $this->attachments->attachToPost($post, $canonical, $author->id);

        // Record WHY a post was held (Phase 4 · M6.1) for the M6.2 review surface — the spam score + signals +
        // the moderation reasons. Only held posts produce a record; approved posts add no rows on the hot path.
        if ($verdict->held()) {
            SpamAssessment::create([
                'post_id' => $post->id,
                'user_id' => $author->id,
                'score' => $verdict->spam->score,
                'signals' => $verdict->spam->signals,
                'reasons' => $verdict->reasons,
            ]);
            Audit::log('post.spam_held', $post, ['score' => $verdict->spam->score, 'reasons' => $verdict->reasons]);
        }

        return $post;
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

        // Club privacy (Phase 4 · M1.5): a notification carries the topic title + url, so a reply/mention in a
        // CLUB forum must NEVER reach a recipient who cannot see the club's content (someone mentioned across
        // the club boundary, or a former member). For a board forum this gate is a no-op.
        $forum = $topic->forum_id ? Forum::find($topic->forum_id) : null;
        $mayNotify = fn (User $recipient): bool => $forum === null || $forum->clubContentVisibleTo($recipient);

        // A reply (any earlier post exists) notifies the topic's author.
        $isReply = Post::where('topic_id', $post->topic_id)->where('id', '<', $post->id)->exists();
        if ($isReply && $topic->user_id && (int) $topic->user_id !== (int) $author->getKey()) {
            $opAuthor = User::find($topic->user_id);
            if ($opAuthor instanceof User && $mayNotify($opAuthor)) {
                $this->notifier->send($opAuthor, 'reply', $author, $payload);
            }
        }

        // @mentions in the canonical body (cast to array — tiptap docs are arrays; markdown yields none).
        // P5.1 — bound the fan-out: the canonical doc is client-controlled, so cap the distinct recipients a
        // single post may notify (a notification + queued email each). Without this one crafted post could
        // mass-notify the whole board and flood the request thread. The per-recipient privacy gate still runs.
        $cap = max(0, (int) config('novfora.antispam.mention_fanout_cap', 10));
        $mentionIds = $cap === 0 ? [] : array_slice(Mentions::idsIn((array) $post->body_canonical), 0, $cap);
        foreach ($mentionIds as $id) {
            $mentioned = User::find($id);
            if ($mentioned instanceof User && $mayNotify($mentioned)) {
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

    /**
     * Re-render a post's display HTML + text from its (lossless) canonical with the author's CURRENT
     * suppression (phase-1.5 F-E). Used after a trust change so link/image gating re-applies to a user's
     * existing posts — re-suppressing on demotion, revealing on promotion. The canonical is never touched.
     */
    public function rerender(Post $post): void
    {
        $author = $post->user_id ? User::find($post->user_id) : null;
        $topic = Topic::withTrashed()->find($post->topic_id);
        if (! $author instanceof User || ! $topic instanceof Topic) {
            return;
        }

        $rendered = $this->renderer->render(
            (string) $post->body_format,
            (array) $post->body_canonical,
            $this->restrictionsFor($author, $topic->permissionScope()),
        );

        $post->forceFill([
            'body_html_cache' => $this->displayHtml($rendered['html'], (array) $post->body_canonical),
            'body_text' => $this->words->applyReplacements($rendered['text']),
        ])->saveQuietly(); // quiet: suppression changes display only — no counter/search churn
    }

    private function slug(string $title): string
    {
        return Str::slug(Str::limit($title, 60, '')) ?: 'topic';
    }
}
