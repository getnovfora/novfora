<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Import\Contracts;

/**
 * A legacy-forum SOURCE DRIVER (ADR-0034). Reads a legacy forum's database READ-ONLY and maps its rows to
 * NovFora-shaped, normalised arrays. This is CLEAN-ROOM: a driver encodes the public DB schema of a reference
 * forum (table + column names) to copy DATA — it never copies that forum's code, templates, or program logic.
 *
 * Each method returns rows keyed by a stable, source-agnostic vocabulary the ImportRunner understands
 * (`source_id`, `username`, `parent_source_id`, …), so the runner is identical across drivers. Batched reads
 * take an `$afterId` cursor (keyset pagination) so a multi-million-row import is resumable and memory-bounded.
 */
interface SourceDriver
{
    /** A stable short key, e.g. 'phpbb' | 'mybb' | 'smf'. */
    public function key(): string;

    /** Whether this source's tables are reachable (a pre-flight connectivity check). */
    public function reachable(): bool;

    /**
     * Row counts of the entities that will be imported (for the plan + the verify reconciliation).
     *
     * @return array{users:int, forums:int, topics:int, posts:int}
     */
    public function counts(): array;

    /**
     * Users with source_id > $afterId, ascending, up to $limit. Bots/guests are excluded by the driver.
     *
     * @return list<array{source_id:int, username:string, email:string, password_hash:string, registered_at:int}>
     */
    public function users(int $afterId, int $limit): array;

    /**
     * All forums/categories (usually few), each with its parent's source id (0/null for a root).
     *
     * @return list<array{source_id:int, parent_source_id:int, name:string, type:string, position:int}>
     */
    public function forums(): array;

    /**
     * Topics with source_id > $afterId, ascending, up to $limit.
     *
     * @return list<array{source_id:int, forum_source_id:int, title:string, author_source_id:int, created_at:int}>
     */
    public function topics(int $afterId, int $limit): array;

    /**
     * Posts with source_id > $afterId, ascending, up to $limit. `body` is the raw legacy body the importer
     * converts to canonical markdown.
     *
     * @return list<array{source_id:int, topic_source_id:int, author_source_id:int, subject:string, body:string, created_at:int}>
     */
    public function posts(int $afterId, int $limit): array;

    /**
     * The legacy URL path patterns for an imported forum/topic, used to emit 301 redirect maps. Returns a list
     * of legacy relative paths that should redirect to the new entity (e.g. phpBB's viewtopic.php?t=ID).
     *
     * @return list<string>
     */
    public function legacyTopicPaths(int $sourceId): array;

    /** @return list<string> */
    public function legacyForumPaths(int $sourceId): array;
}
