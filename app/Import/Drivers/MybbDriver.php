<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Import\Drivers;

use App\Import\BbcodeConverter;
use App\Import\Contracts\ProvidesAttachments;
use App\Import\Contracts\SourceDriver;
use Illuminate\Database\ConnectionInterface;

/**
 * MyBB 1.8 source driver — SCAFFOLD (ADR-0034). It maps MyBB's public schema (`mybb_users`, `mybb_forums`,
 * `mybb_threads`, `mybb_posts`) behind the same SourceDriver contract as the (fully-built + tested) phpBB
 * driver, so completing MyBB support is mapping work, not architecture. CLEAN-ROOM: schema only. NOTE: MyBB
 * stores `md5(md5(salt).md5(password))`, which Laravel cannot verify — imported MyBB users reset their
 * password on first login. Verify against a live board before production use.
 */
final class MybbDriver implements ProvidesAttachments, SourceDriver
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $prefix = 'mybb_',
        private readonly ?string $attachmentsPath = null,
    ) {}

    public function key(): string
    {
        return 'mybb';
    }

    public function reachable(): bool
    {
        try {
            $this->connection->table($this->prefix.'users')->limit(1)->count();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function counts(): array
    {
        return [
            'users' => $this->connection->table($this->prefix.'users')->count(),
            'forums' => $this->connection->table($this->prefix.'forums')->count(),
            'topics' => $this->connection->table($this->prefix.'threads')->count(),
            'posts' => $this->connection->table($this->prefix.'posts')->count(),
        ];
    }

    public function users(int $afterId, int $limit): array
    {
        return $this->connection->table($this->prefix.'users')
            ->where('uid', '>', $afterId)->orderBy('uid')->limit($limit)
            ->get(['uid', 'username', 'email', 'password', 'regdate'])
            ->map(fn ($r): array => [
                'source_id' => (int) $r->uid, 'username' => (string) $r->username, 'email' => (string) $r->email,
                'password_hash' => '', // MyBB salted-md5 is not Laravel-verifiable → force a reset (empty hash)
                'registered_at' => (int) $r->regdate,
            ])->all();
    }

    public function forums(): array
    {
        return $this->connection->table($this->prefix.'forums')->orderBy('disporder')
            ->get(['fid', 'pid', 'name', 'type'])
            ->map(fn ($r): array => [
                'source_id' => (int) $r->fid, 'parent_source_id' => (int) $r->pid, 'name' => (string) $r->name,
                'type' => $r->type === 'c' ? 'category' : 'forum', 'position' => (int) $r->fid,
            ])->all();
    }

    public function topics(int $afterId, int $limit): array
    {
        return $this->connection->table($this->prefix.'threads')
            ->where('tid', '>', $afterId)->orderBy('tid')->limit($limit)
            ->get(['tid', 'fid', 'subject', 'uid', 'dateline'])
            ->map(fn ($r): array => [
                'source_id' => (int) $r->tid, 'forum_source_id' => (int) $r->fid, 'title' => (string) $r->subject,
                'author_source_id' => (int) $r->uid, 'created_at' => (int) $r->dateline,
            ])->all();
    }

    public function posts(int $afterId, int $limit): array
    {
        $converter = new BbcodeConverter;

        return $this->connection->table($this->prefix.'posts')
            ->where('pid', '>', $afterId)->orderBy('pid')->limit($limit)
            ->get(['pid', 'tid', 'uid', 'subject', 'message', 'dateline'])
            ->map(fn ($r): array => [
                'source_id' => (int) $r->pid, 'topic_source_id' => (int) $r->tid, 'author_source_id' => (int) $r->uid,
                'subject' => (string) $r->subject, 'body' => $converter->toMarkdown((string) $r->message),
                'created_at' => (int) $r->dateline,
            ])->all();
    }

    /** MyBB attachments (`mybb_attachments`); the physical file is `attachname` under the uploads/ base dir. */
    public function attachments(int $afterId, int $limit): array
    {
        if ($this->attachmentsPath === null) {
            return [];
        }

        return $this->connection->table($this->prefix.'attachments')
            ->where('aid', '>', $afterId)->orderBy('aid')->limit($limit)
            ->get(['aid', 'pid', 'uid', 'filename', 'filetype', 'attachname'])
            ->map(fn ($r): array => [
                'source_id' => (int) $r->aid,
                'post_source_id' => (int) $r->pid,
                'author_source_id' => (int) $r->uid,
                'original_name' => (string) $r->filename,
                'mime' => (string) $r->filetype,
                'path' => rtrim($this->attachmentsPath, '/').'/'.$r->attachname,
            ])->all();
    }

    public function legacyTopicPaths(int $sourceId): array
    {
        return ["/showthread.php?tid={$sourceId}"];
    }

    public function legacyForumPaths(int $sourceId): array
    {
        return ["/forumdisplay.php?fid={$sourceId}"];
    }
}
