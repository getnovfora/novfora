<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Import;

use App\Content\ContentRenderer;
use App\Import\Contracts\SourceDriver;
use App\Models\Forum;
use App\Models\ImportMap;
use App\Models\Post;
use App\Models\Redirect;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Orchestrates a legacy import (ADR-0013 / ADR-0034) — driver-agnostic, IDEMPOTENT, and RESUMABLE. Every
 * created entity is recorded in `import_maps` keyed by `(source, kind, source_id)`, so a re-run skips what it
 * already created and continues from the last id (keyset cursor) — a multi-million-row import survives an
 * interruption and runs in cron windows on the baseline tier. Imports go straight through the Eloquent models,
 * NOT the post/topic services, so a bulk import does NOT fire domain events (no webhook storm, no activity-feed
 * flood, no reputation awards). Legacy URL patterns become 301 redirects. The three stages are preflight (count
 * + plan, read-only), import (the batched work), and verify (count reconciliation).
 */
final class ImportRunner
{
    public function __construct(private readonly ContentRenderer $renderer) {}

    /** @return array{source:string, counts:array{users:int,forums:int,topics:int,posts:int}, already_imported:array<string,int>} */
    public function preflight(SourceDriver $driver): array
    {
        if (! $driver->reachable()) {
            throw new ImportException("The {$driver->key()} source is not reachable — check the connection settings.");
        }

        return [
            'source' => $driver->key(),
            'counts' => $driver->counts(),
            'already_imported' => $this->importedCounts($driver->key()),
        ];
    }

    /** @return array<string,array{source:int,imported:int,complete:bool}> the verify report */
    public function import(SourceDriver $driver, int $batch = 500): array
    {
        $this->importUsers($driver, $batch);
        $this->importForums($driver);
        $this->importTopics($driver, $batch);
        $this->importPosts($driver, $batch);

        return $this->verify($driver);
    }

    /** @return array<string,array{source:int,imported:int,complete:bool}> */
    public function verify(SourceDriver $driver): array
    {
        $source = $driver->counts();
        $imported = $this->importedCounts($driver->key());
        $report = [];
        foreach (['users', 'forums', 'topics', 'posts'] as $kind) {
            $report[$kind] = [
                'source' => $source[$kind],
                'imported' => $imported[$kind],
                'complete' => $imported[$kind] >= $source[$kind],
            ];
        }

        return $report;
    }

    private function importUsers(SourceDriver $driver, int $batch): void
    {
        $after = $this->resumeAfter($driver->key(), 'user');
        while (($rows = $driver->users($after, $batch)) !== []) {
            foreach ($rows as $row) {
                $after = max($after, $row['source_id']);
                if ($this->isMapped($driver->key(), 'user', $row['source_id'])) {
                    continue;
                }
                $username = $this->uniqueUsername($row['username'], $row['source_id']);
                $user = User::create([
                    'name' => $username,
                    'username' => $username,
                    'display_name' => trim($row['username']) !== '' ? Str::limit(trim($row['username']), 60, '') : $username,
                    'email' => $this->uniqueEmail($row['email'], $driver->key(), $row['source_id']),
                    // The legacy hash is stored as-is via the 'hashed' cast, which PRESERVES an already-valid
                    // bcrypt ($2y$) hash (it verifies + auto-rehashes to argon2id on first login) and re-hashes
                    // anything else. A legacy phpass/SHA hash that can't be verified simply fails the check, so
                    // that user resets — no reset is forced for modern hashes.
                    'password' => $row['password_hash'] !== '' ? $row['password_hash'] : Hash::make(Str::random(40)),
                ]);
                $user->forceFill([
                    'status' => 'active',
                    'created_at' => $row['registered_at'] > 0 ? Carbon::createFromTimestamp($row['registered_at']) : $user->created_at,
                ])->saveQuietly();
                $this->record($driver->key(), 'user', $row['source_id'], (int) $user->getKey());
            }
        }
    }

    private function importForums(SourceDriver $driver): void
    {
        // Import forums parent-before-child WITHOUT relying on the driver's row order (phpBB yields nested-set
        // order, but MyBB `disporder` / SMF `board_order` are display order, not hierarchy). Pass repeatedly
        // over the not-yet-imported forums, creating any whose parent is a root (0) or already mapped, until a
        // full pass makes no progress; any then-remaining forums (a missing or cyclic parent) are created as
        // roots so none is silently dropped. Idempotent: an already-mapped forum is skipped on re-run.
        $pending = [];
        foreach ($driver->forums() as $row) {
            if (! $this->isMapped($driver->key(), 'forum', $row['source_id'])) {
                $pending[] = $row;
            }
        }

        while ($pending !== []) {
            $progressed = false;
            $next = [];
            foreach ($pending as $row) {
                $parentReady = $row['parent_source_id'] <= 0
                    || $this->target($driver->key(), 'forum', $row['parent_source_id']) !== null;
                if ($parentReady) {
                    $this->createForum($driver, $row);
                    $progressed = true;
                } else {
                    $next[] = $row;
                }
            }
            if (! $progressed) {
                foreach ($next as $row) {
                    $this->createForum($driver, $row, forceRoot: true); // missing/cyclic parent → root, never dropped
                }
                break;
            }
            $pending = $next;
        }
    }

    /** @param array{source_id:int, parent_source_id:int, name:string, type:string, position:int} $row */
    private function createForum(SourceDriver $driver, array $row, bool $forceRoot = false): void
    {
        $parentId = (! $forceRoot && $row['parent_source_id'] > 0)
            ? $this->target($driver->key(), 'forum', $row['parent_source_id'])
            : null;
        $forum = Forum::create([
            'parent_id' => $parentId,
            'slug' => $this->uniqueSlug('forums', $row['name'], $row['source_id']),
            'title' => $row['name'] !== '' ? $row['name'] : "Forum {$row['source_id']}",
            'type' => $row['type'],
            'position' => $row['position'],
        ]);
        $this->record($driver->key(), 'forum', $row['source_id'], (int) $forum->getKey());
        foreach ($driver->legacyForumPaths($row['source_id']) as $path) {
            $this->redirect($path, '/forums/'.$forum->getKey());
        }
    }

    private function importTopics(SourceDriver $driver, int $batch): void
    {
        $after = $this->resumeAfter($driver->key(), 'topic');
        while (($rows = $driver->topics($after, $batch)) !== []) {
            foreach ($rows as $row) {
                $after = max($after, $row['source_id']);
                if ($this->isMapped($driver->key(), 'topic', $row['source_id'])) {
                    continue;
                }
                $forumId = $this->target($driver->key(), 'forum', $row['forum_source_id']);
                if ($forumId === null) {
                    continue; // orphan: its forum wasn't imported (e.g. an excluded forum) — skip
                }
                $topic = Topic::create([
                    'forum_id' => $forumId,
                    'slug' => $this->uniqueSlug('topics', $row['title'], $row['source_id']),
                    'title' => $row['title'] !== '' ? $row['title'] : "Topic {$row['source_id']}",
                    'user_id' => $this->target($driver->key(), 'user', $row['author_source_id']),
                ]);
                if ($row['created_at'] > 0) {
                    $topic->forceFill(['created_at' => Carbon::createFromTimestamp($row['created_at'])])->saveQuietly();
                }
                $this->record($driver->key(), 'topic', $row['source_id'], (int) $topic->getKey());
                foreach ($driver->legacyTopicPaths($row['source_id']) as $path) {
                    $this->redirect($path, '/topics/'.$topic->getKey());
                }
            }
        }
    }

    private function importPosts(SourceDriver $driver, int $batch): void
    {
        $after = $this->resumeAfter($driver->key(), 'post');
        while (($rows = $driver->posts($after, $batch)) !== []) {
            foreach ($rows as $row) {
                $after = max($after, $row['source_id']);
                if ($this->isMapped($driver->key(), 'post', $row['source_id'])) {
                    continue;
                }
                $topicId = $this->target($driver->key(), 'topic', $row['topic_source_id']);
                if ($topicId === null) {
                    continue; // orphan post
                }
                $rendered = $this->renderer->render('markdown', ['source' => $row['body']]);
                $post = Post::create([
                    'topic_id' => $topicId,
                    'user_id' => $this->target($driver->key(), 'user', $row['author_source_id']),
                    'body_format' => 'markdown',
                    'body_canonical' => json_encode(['source' => $row['body']]),
                    'body_html_cache' => $rendered['html'],
                    'body_text' => $rendered['text'],
                    'approved_state' => 'approved',
                    'position' => Post::query()->where('topic_id', $topicId)->count(),
                ]);
                if ($row['created_at'] > 0) {
                    $post->forceFill(['created_at' => Carbon::createFromTimestamp($row['created_at'])])->saveQuietly();
                }
                $this->record($driver->key(), 'post', $row['source_id'], (int) $post->getKey());
            }
        }
    }

    /** @return array<string,int> */
    private function importedCounts(string $source): array
    {
        return [
            'users' => ImportMap::query()->where('source', $source)->where('kind', 'user')->count(),
            'forums' => ImportMap::query()->where('source', $source)->where('kind', 'forum')->count(),
            'topics' => ImportMap::query()->where('source', $source)->where('kind', 'topic')->count(),
            'posts' => ImportMap::query()->where('source', $source)->where('kind', 'post')->count(),
        ];
    }

    private function resumeAfter(string $source, string $kind): int
    {
        return (int) ImportMap::query()->where('source', $source)->where('kind', $kind)->max('source_id');
    }

    private function isMapped(string $source, string $kind, int $sourceId): bool
    {
        return ImportMap::query()->where('source', $source)->where('kind', $kind)->where('source_id', $sourceId)->exists();
    }

    private function target(string $source, string $kind, int $sourceId): ?int
    {
        $value = ImportMap::query()->where('source', $source)->where('kind', $kind)->where('source_id', $sourceId)->value('target_id');

        return $value === null ? null : (int) $value;
    }

    private function record(string $source, string $kind, int $sourceId, int $targetId): void
    {
        ImportMap::query()->insertOrIgnore([
            'source' => $source, 'kind' => $kind, 'source_id' => $sourceId, 'target_id' => $targetId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function redirect(string $fromPath, string $toPath): void
    {
        Redirect::query()->updateOrCreate(['from_path' => $fromPath], ['to_path' => $toPath, 'status' => 301]);
    }

    private function uniqueUsername(string $name, int $sourceId): string
    {
        $base = trim($name) !== '' ? Str::limit(trim($name), 40, '') : "user{$sourceId}";
        $candidate = $base;
        $suffix = 0;
        while (User::query()->where('username', $candidate)->exists()) {
            $candidate = $base.'_'.$sourceId.($suffix > 0 ? '_'.$suffix : '');
            $suffix++;
        }

        return $candidate;
    }

    private function uniqueEmail(string $email, string $source, int $sourceId): string
    {
        $email = trim(mb_strtolower($email));
        if ($email === '' || User::query()->where('email', $email)->exists()) {
            return "{$source}-{$sourceId}@imported.invalid";
        }

        return $email;
    }

    private function uniqueSlug(string $table, string $title, int $sourceId): string
    {
        $base = Str::slug(Str::limit($title, 80, '')) ?: 'item';
        $candidate = $base;
        $suffix = 0;
        while (DB::table($table)->where('slug', $candidate)->exists()) {
            $candidate = $base.'-'.$sourceId.($suffix > 0 ? '-'.$suffix : '');
            $suffix++;
        }

        return $candidate;
    }
}
