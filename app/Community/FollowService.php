<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Community;

use App\Events\Followed;
use App\Models\User;
use App\Models\UserRelationship;
use Illuminate\Support\Facades\DB;

/**
 * The single write + read path for the FOLLOW half of user_relationships (P2-M5; the table and the ignore
 * half are M2 Half-B). A follow is a directed edge user_id (follower) → related_user_id (followee).
 *
 * Idempotency is the DB UNIQUE(user_id, related_user_id, type): follow() is an insertOrIgnore against it,
 * so a double-submit / concurrent race can never create a second edge or double-fire the notification —
 * the Followed event is dispatched ONLY when a row was actually inserted. SELF-FOLLOW IS A HARD REFUSE
 * here in the service, regardless of any ACL state — there is no permission an admin can grant that makes
 * a user follow themselves.
 *
 * No denormalised counters: follower/following counts are per-profile COUNT queries (within the page
 * budget), and the following-feed cache key carries a HASH of the followed-id set, so a follow/unfollow
 * re-keys the cached window immediately — no version counter to maintain (ActivityFeed::forFollowing).
 */
final class FollowService
{
    /**
     * Follow $followee. Returns true when a new edge was created, false when it already existed (no-op).
     *
     * @throws \InvalidArgumentException on self-follow (hard refuse, never permission-liftable)
     */
    public function follow(User $follower, User $followee): bool
    {
        if (! $follower->getKey() || ! $followee->getKey()) {
            return false; // a non-persisted (guest) endpoint can hold no edge
        }
        if ((int) $follower->getKey() === (int) $followee->getKey()) {
            throw new \InvalidArgumentException('A user cannot follow themselves.');
        }

        $now = now();
        $inserted = DB::table('user_relationships')->insertOrIgnore([
            'user_id' => (int) $follower->getKey(),
            'related_user_id' => (int) $followee->getKey(),
            'type' => UserRelationship::TYPE_FOLLOW,
            'created_at' => $now,
            'updated_at' => $now,
        ]) > 0;

        if ($inserted) {
            Followed::dispatch($follower, $followee);
        }

        return $inserted;
    }

    /** Unfollow $followee. Returns true when an edge was actually removed. */
    public function unfollow(User $follower, User $followee): bool
    {
        return UserRelationship::query()
            ->where('user_id', $follower->getKey())
            ->where('related_user_id', $followee->getKey())
            ->where('type', UserRelationship::TYPE_FOLLOW)
            ->delete() > 0;
    }

    /** Whether $follower currently follows $followee. */
    public function follows(User $follower, User $followee): bool
    {
        if (! $follower->getKey() || ! $followee->getKey()) {
            return false;
        }

        return UserRelationship::query()
            ->where('user_id', $follower->getKey())
            ->where('related_user_id', $followee->getKey())
            ->where('type', UserRelationship::TYPE_FOLLOW)
            ->exists();
    }

    /** How many users follow $user (their audience). Query, not denorm — profile-page budget absorbs one COUNT. */
    public function followerCount(User $user): int
    {
        return UserRelationship::query()
            ->where('related_user_id', $user->getKey())
            ->where('type', UserRelationship::TYPE_FOLLOW)
            ->count();
    }

    /** How many users $user follows. */
    public function followingCount(User $user): int
    {
        return UserRelationship::query()
            ->where('user_id', $user->getKey())
            ->where('type', UserRelationship::TYPE_FOLLOW)
            ->count();
    }

    /** The ids $follower follows — the following-feed's actor filter. @return list<int> */
    public function followingIds(User $follower): array
    {
        if (! $follower->getKey()) {
            return [];
        }

        return UserRelationship::query()
            ->where('user_id', $follower->getKey())
            ->where('type', UserRelationship::TYPE_FOLLOW)
            ->pluck('related_user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
