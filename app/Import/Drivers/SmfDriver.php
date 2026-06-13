<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Import\Drivers;

use App\Import\BbcodeConverter;
use App\Import\Contracts\SourceDriver;
use Illuminate\Database\ConnectionInterface;

/**
 * SMF 2.x source driver — SCAFFOLD (ADR-0034). Maps SMF's public schema (`smf_members`, `smf_boards`,
 * `smf_topics`, `smf_messages`) behind the same SourceDriver contract as the fully-built phpBB driver.
 * CLEAN-ROOM: schema only — no SMF code/templates are used, even though SMF's BSD licence would permit it
 * (the project's strict clean-room rule). SMF's SHA-1(lowercase-username + password) hashes are not
 * Laravel-verifiable → imported SMF users reset on first login. Verify against a live board before use.
 */
final class SmfDriver implements SourceDriver
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $prefix = 'smf_',
    ) {}

    public function key(): string
    {
        return 'smf';
    }

    public function reachable(): bool
    {
        try {
            $this->connection->table($this->prefix.'members')->limit(1)->count();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function counts(): array
    {
        return [
            'users' => $this->connection->table($this->prefix.'members')->count(),
            'forums' => $this->connection->table($this->prefix.'boards')->count(),
            'topics' => $this->connection->table($this->prefix.'topics')->count(),
            'posts' => $this->connection->table($this->prefix.'messages')->count(),
        ];
    }

    public function users(int $afterId, int $limit): array
    {
        return $this->connection->table($this->prefix.'members')
            ->where('id_member', '>', $afterId)->orderBy('id_member')->limit($limit)
            ->get(['id_member', 'member_name', 'email_address', 'date_registered'])
            ->map(fn ($r): array => [
                'source_id' => (int) $r->id_member, 'username' => (string) $r->member_name,
                'email' => (string) $r->email_address, 'password_hash' => '', // SMF SHA-1 → force a reset
                'registered_at' => (int) $r->date_registered,
            ])->all();
    }

    public function forums(): array
    {
        return $this->connection->table($this->prefix.'boards')->orderBy('board_order')
            ->get(['id_board', 'id_parent', 'name'])
            ->map(fn ($r): array => [
                'source_id' => (int) $r->id_board, 'parent_source_id' => (int) $r->id_parent, 'name' => (string) $r->name,
                'type' => 'forum', 'position' => (int) $r->id_board,
            ])->all();
    }

    public function topics(int $afterId, int $limit): array
    {
        return $this->connection->table($this->prefix.'topics')
            ->where('id_topic', '>', $afterId)->orderBy('id_topic')->limit($limit)
            ->get(['id_topic', 'id_board', 'id_member_started'])
            ->map(fn ($r): array => [
                'source_id' => (int) $r->id_topic, 'forum_source_id' => (int) $r->id_board, 'title' => '',
                'author_source_id' => (int) $r->id_member_started, 'created_at' => 0,
            ])->all();
    }

    public function posts(int $afterId, int $limit): array
    {
        $converter = new BbcodeConverter;

        return $this->connection->table($this->prefix.'messages')
            ->where('id_msg', '>', $afterId)->orderBy('id_msg')->limit($limit)
            ->get(['id_msg', 'id_topic', 'id_member', 'subject', 'body', 'poster_time'])
            ->map(fn ($r): array => [
                'source_id' => (int) $r->id_msg, 'topic_source_id' => (int) $r->id_topic, 'author_source_id' => (int) $r->id_member,
                'subject' => (string) $r->subject, 'body' => $converter->toMarkdown((string) $r->body),
                'created_at' => (int) $r->poster_time,
            ])->all();
    }

    public function legacyTopicPaths(int $sourceId): array
    {
        return ["/index.php?topic={$sourceId}"];
    }

    public function legacyForumPaths(int $sourceId): array
    {
        return ["/index.php?board={$sourceId}"];
    }
}
