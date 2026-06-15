<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Import\Drivers;

use App\Import\BbcodeConverter;
use App\Import\Contracts\ProvidesAttachments;
use App\Import\Contracts\SourceDriver;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * XenForo 2.x source driver (ADR-0041, Wave 4) — mirrors the phpBB driver's bar. CLEAN-ROOM: it encodes only
 * XenForo's PUBLIC table schema (`xf_user`, `xf_node`, `xf_thread`, `xf_post`, `xf_attachment` +
 * `xf_attachment_data`) to copy DATA, reading the legacy DB READ-ONLY; it never touches XenForo's code,
 * templates, or licensed libraries.
 *
 * XenForo specifics handled here:
 *   - Forums live in the unified `xf_node` tree; `node_type_id` ('Category'|'Forum'|'LinkForum') maps to our
 *     category|forum|link. The runner's topological sort handles parent-before-child.
 *   - Only `user_state = 'valid'` users, `discussion_state = 'visible'` threads, and `message_state = 'visible'`
 *     posts are imported (counts() applies the SAME filters so verify reconciles).
 *   - Passwords use XenForo's own (non-Laravel) scheme, so `password_hash` is '' → the runner assigns a random
 *     password and the user resets on first login (same as MyBB/SMF).
 *   - Post bodies are plain BBCode (no per-post UID), converted with BbcodeConverter like MyBB/SMF.
 *
 * NOT VALIDATED against a live XenForo install: the on-disk ATTACHMENT path layout (XF2
 * internal_data/attachments/<data_id/1000>/<data_id>-<file_hash>.data) and the slugged legacy-URL shapes vary
 * by version/config. The DATA mapping is fixture-verified; point --attachments at the internal data dir.
 */
final class XenForoDriver implements ProvidesAttachments, SourceDriver
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $prefix = 'xf_',
        private readonly ?string $attachmentsPath = null,
    ) {}

    public function key(): string
    {
        return 'xenforo';
    }

    public function reachable(): bool
    {
        try {
            $this->connection->table($this->t('user'))->limit(1)->count();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function counts(): array
    {
        return [
            'users' => $this->usersQuery()->count(),
            'forums' => $this->connection->table($this->t('node'))->count(),
            'topics' => $this->threadsQuery()->count(),
            'posts' => $this->postsQuery()->count(),
        ];
    }

    public function users(int $afterId, int $limit): array
    {
        return $this->usersQuery()
            ->where('user_id', '>', $afterId)->orderBy('user_id')->limit($limit)
            ->get(['user_id', 'username', 'email', 'register_date'])
            ->map(fn ($r): array => [
                'source_id' => (int) $r->user_id,
                'username' => (string) $r->username,
                'email' => (string) $r->email,
                'password_hash' => '', // XenForo hashes aren't Laravel-verifiable → random password + reset
                'registered_at' => (int) $r->register_date,
            ])->all();
    }

    public function forums(): array
    {
        return $this->connection->table($this->t('node'))->orderBy('display_order')->orderBy('node_id')
            ->get(['node_id', 'parent_node_id', 'title', 'node_type_id', 'display_order'])
            ->map(fn ($r): array => [
                'source_id' => (int) $r->node_id,
                'parent_source_id' => (int) $r->parent_node_id,
                'name' => (string) $r->title,
                'type' => match ((string) $r->node_type_id) {
                    'Category' => 'category',
                    'LinkForum' => 'link',
                    default => 'forum', // Forum (and anything else) renders as a forum
                },
                'position' => (int) $r->display_order,
            ])->all();
    }

    public function topics(int $afterId, int $limit): array
    {
        return $this->threadsQuery()
            ->where('thread_id', '>', $afterId)->orderBy('thread_id')->limit($limit)
            ->get(['thread_id', 'node_id', 'title', 'user_id', 'post_date'])
            ->map(fn ($r): array => [
                'source_id' => (int) $r->thread_id,
                'forum_source_id' => (int) $r->node_id,
                'title' => (string) $r->title,
                'author_source_id' => (int) $r->user_id,
                'created_at' => (int) $r->post_date,
            ])->all();
    }

    public function posts(int $afterId, int $limit): array
    {
        $converter = new BbcodeConverter;

        return $this->postsQuery()
            ->where('post_id', '>', $afterId)->orderBy('post_id')->limit($limit)
            ->get(['post_id', 'thread_id', 'user_id', 'message', 'post_date'])
            ->map(fn ($r): array => [
                'source_id' => (int) $r->post_id,
                'topic_source_id' => (int) $r->thread_id,
                'author_source_id' => (int) $r->user_id,
                'subject' => '', // XenForo posts have no per-post subject
                'body' => $converter->toMarkdown((string) $r->message),
                'created_at' => (int) $r->post_date,
            ])->all();
    }

    /** xf_attachment (content_type='post') joined to xf_attachment_data for the file metadata + on-disk name. */
    public function attachments(int $afterId, int $limit): array
    {
        if ($this->attachmentsPath === null) {
            return [];
        }

        return $this->connection->table($this->t('attachment').' as a')
            ->join($this->t('attachment_data').' as d', 'a.data_id', '=', 'd.data_id')
            ->where('a.content_type', 'post')
            ->where('a.attachment_id', '>', $afterId)->orderBy('a.attachment_id')->limit($limit)
            ->get(['a.attachment_id', 'a.content_id', 'd.data_id', 'd.user_id', 'd.filename', 'd.file_hash'])
            ->map(fn ($r): array => [
                'source_id' => (int) $r->attachment_id,
                'post_source_id' => (int) $r->content_id,
                'author_source_id' => (int) $r->user_id,
                'original_name' => (string) $r->filename,
                'mime' => $this->mimeFromName((string) $r->filename),
                'path' => $this->attachmentPath((int) $r->data_id, (string) $r->file_hash),
            ])->all();
    }

    public function legacyTopicPaths(int $sourceId): array
    {
        // Bare-id forms XenForo resolves, with and without the trailing slash, plus the index.php style. The
        // slugged form (/threads/<slug>.<id>/) needs the slug — a future enhancement (per-topic lookup).
        return ["/threads/{$sourceId}/", "/threads/{$sourceId}", "/index.php?threads/{$sourceId}/"];
    }

    public function legacyForumPaths(int $sourceId): array
    {
        return ["/forums/{$sourceId}/", "/forums/{$sourceId}", "/index.php?forums/{$sourceId}/"];
    }

    /** Valid users only (excludes email_confirm / moderated / disabled / rejected). */
    private function usersQuery(): Builder
    {
        return $this->connection->table($this->t('user'))->where('user_state', 'valid');
    }

    private function threadsQuery(): Builder
    {
        return $this->connection->table($this->t('thread'))->where('discussion_state', 'visible');
    }

    private function postsQuery(): Builder
    {
        return $this->connection->table($this->t('post'))->where('message_state', 'visible');
    }

    /** XF2 internal data layout: attachments/<data_id intdiv 1000>/<data_id>-<file_hash>.data */
    private function attachmentPath(int $dataId, string $fileHash): string
    {
        return rtrim((string) $this->attachmentsPath, '/').'/'.intdiv($dataId, 1000).'/'.$dataId.'-'.$fileHash.'.data';
    }

    private function mimeFromName(string $name): string
    {
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            'txt' => 'text/plain',
            'zip' => 'application/zip',
            default => 'application/octet-stream',
        };
    }

    private function t(string $name): string
    {
        return $this->prefix.$name;
    }
}
