<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Import\Drivers;

use App\Import\BbcodeConverter;
use App\Import\Contracts\SourceDriver;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * phpBB 3.x source driver (ADR-0034) — the highest-value importer. CLEAN-ROOM: it encodes phpBB's PUBLIC table
 * schema (`phpbb_users`, `phpbb_forums`, `phpbb_topics`, `phpbb_posts`) to copy DATA only; it reads the legacy
 * DB READ-ONLY and never touches phpBB's code or templates. Bots (`user_type = 2`) are excluded; categories,
 * forums, and links are distinguished by `forum_type` (phpBB FORUM_CAT=0 / FORUM_POST=1 / FORUM_LINK=2).
 */
final class PhpbbDriver implements SourceDriver
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $prefix = 'phpbb_',
    ) {}

    public function key(): string
    {
        return 'phpbb';
    }

    public function reachable(): bool
    {
        try {
            $this->connection->table($this->t('users'))->limit(1)->count();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function counts(): array
    {
        return [
            'users' => $this->usersQuery()->count(),
            'forums' => $this->connection->table($this->t('forums'))->count(),
            'topics' => $this->connection->table($this->t('topics'))->count(),
            'posts' => $this->connection->table($this->t('posts'))->count(),
        ];
    }

    public function users(int $afterId, int $limit): array
    {
        return $this->usersQuery()
            ->where('user_id', '>', $afterId)->orderBy('user_id')->limit($limit)
            ->get(['user_id', 'username', 'user_email', 'user_password', 'user_regdate'])
            ->map(fn ($r): array => [
                'source_id' => (int) $r->user_id,
                'username' => (string) $r->username,
                'email' => (string) $r->user_email,
                'password_hash' => (string) $r->user_password,
                'registered_at' => (int) $r->user_regdate,
            ])->all();
    }

    public function forums(): array
    {
        return $this->connection->table($this->t('forums'))->orderBy('left_id')
            ->get(['forum_id', 'parent_id', 'forum_name', 'forum_type'])
            ->map(fn ($r): array => [
                'source_id' => (int) $r->forum_id,
                'parent_source_id' => (int) $r->parent_id,
                'name' => (string) $r->forum_name,
                'type' => match ((int) $r->forum_type) {
                    0 => 'category', 2 => 'link', default => 'forum'
                },
                'position' => (int) $r->forum_id,
            ])->all();
    }

    public function topics(int $afterId, int $limit): array
    {
        return $this->connection->table($this->t('topics'))
            ->where('topic_id', '>', $afterId)->orderBy('topic_id')->limit($limit)
            ->get(['topic_id', 'forum_id', 'topic_title', 'topic_poster', 'topic_time'])
            ->map(fn ($r): array => [
                'source_id' => (int) $r->topic_id,
                'forum_source_id' => (int) $r->forum_id,
                'title' => (string) $r->topic_title,
                'author_source_id' => (int) $r->topic_poster,
                'created_at' => (int) $r->topic_time,
            ])->all();
    }

    public function posts(int $afterId, int $limit): array
    {
        $converter = new BbcodeConverter;

        return $this->connection->table($this->t('posts'))
            ->where('post_id', '>', $afterId)->orderBy('post_id')->limit($limit)
            ->get(['post_id', 'topic_id', 'poster_id', 'post_subject', 'post_text', 'post_time', 'bbcode_uid'])
            ->map(fn ($r): array => [
                'source_id' => (int) $r->post_id,
                'topic_source_id' => (int) $r->topic_id,
                'author_source_id' => (int) $r->poster_id,
                'subject' => (string) $r->post_subject,
                'body' => $converter->toMarkdown((string) $r->post_text, (string) ($r->bbcode_uid ?? '')),
                'created_at' => (int) $r->post_time,
            ])->all();
    }

    public function legacyTopicPaths(int $sourceId): array
    {
        return ["/viewtopic.php?t={$sourceId}"];
    }

    public function legacyForumPaths(int $sourceId): array
    {
        return ["/viewforum.php?f={$sourceId}"];
    }

    /** Normal members + founders; phpBB bots (user_type 2) and inactive (1) are excluded. */
    private function usersQuery(): Builder
    {
        return $this->connection->table($this->t('users'))->whereIn('user_type', [0, 3]);
    }

    private function t(string $name): string
    {
        return $this->prefix.$name;
    }
}
