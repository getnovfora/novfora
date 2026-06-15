<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Community;

use App\Models\User;
use App\Models\UserRelationship;
use Illuminate\Support\Facades\DB;

/**
 * The write + read path for the IGNORE half of user_relationships (the "block"). An ignore is a directed edge
 * actor → target meaning: the actor hides the target's posts and will not receive PMs from — and cannot be
 * added to a conversation started by — the target (the PM half is enforced in ConversationService). Ignoring
 * is SILENT: the target is never told. Like a bookmark, it is ungated personal participation (no ACL key).
 *
 * Idempotency rides the DB UNIQUE(user_id, related_user_id, type) — ignore() is an insertOrIgnore. Self-ignore
 * is a hard refuse here regardless of any ACL state. No event is dispatched (an ignore must not notify anyone).
 */
final class IgnoreService
{
    /** Ignore $target. Returns true when a new edge was created, false when it already existed. */
    public function ignore(User $actor, User $target): bool
    {
        if (! $actor->getKey() || ! $target->getKey()) {
            return false;
        }
        if ((int) $actor->getKey() === (int) $target->getKey()) {
            throw new \InvalidArgumentException('A user cannot ignore themselves.');
        }

        $now = now();

        return DB::table('user_relationships')->insertOrIgnore([
            'user_id' => (int) $actor->getKey(),
            'related_user_id' => (int) $target->getKey(),
            'type' => UserRelationship::TYPE_IGNORE,
            'created_at' => $now,
            'updated_at' => $now,
        ]) > 0;
    }

    /** Stop ignoring $target. Returns true when an edge was actually removed. */
    public function unignore(User $actor, User $target): bool
    {
        return UserRelationship::query()
            ->where('user_id', $actor->getKey())
            ->where('related_user_id', $target->getKey())
            ->where('type', UserRelationship::TYPE_IGNORE)
            ->delete() > 0;
    }

    /** Whether $actor currently ignores $target. */
    public function ignores(User $actor, User $target): bool
    {
        if (! $actor->getKey() || ! $target->getKey()) {
            return false;
        }

        return UserRelationship::query()
            ->where('user_id', $actor->getKey())
            ->where('related_user_id', $target->getKey())
            ->where('type', UserRelationship::TYPE_IGNORE)
            ->exists();
    }

    /** The ids $actor ignores — used to hide their posts on a page. @return list<int> */
    public function ignoredIds(User $actor): array
    {
        if (! $actor->getKey()) {
            return [];
        }

        return UserRelationship::query()
            ->where('user_id', $actor->getKey())
            ->where('type', UserRelationship::TYPE_IGNORE)
            ->pluck('related_user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /** The ignored users themselves (for the settings list), each with their username. @return \Illuminate\Support\Collection<int,User> */
    public function ignoredUsers(User $actor)
    {
        return User::query()
            ->whereIn('id', $this->ignoredIds($actor))
            ->orderBy('username')
            ->get(['id', 'username', 'display_name']);
    }
}
