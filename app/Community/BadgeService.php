<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Community;

use App\Models\Badge;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The badge criteria engine (P2-M5 ⚙). Criteria are a CLOSED SET — a small matched JSON document
 * ({type, threshold}), never an evaluated expression (an expression engine on admin input would be a
 * security surface; deliberately out of M5 scope). Awards are IDEMPOTENT — insertOrIgnore on
 * UNIQUE(user_id, badge_id) — and PERMANENT: a badge is never revoked when its criterion later lapses
 * (rep drops, posts deleted); the recompute cron only ever ADDS missed awards. An unknown criteria type
 * in the DB (e.g. written by a future version) matches nothing rather than failing.
 *
 * post_count criteria COUNT live APPROVED posts directly — deliberately NOT users.post_count (now maintained,
 * but it counts ALL non-deleted posts incl. held/pending, which would award badges for unapproved spam);
 * reputation criteria read the ledger-reconciled users.reputation_points.
 */
final class BadgeService
{
    /** The closed criteria vocabulary. join = awarded for existing at all (the welcome badge). */
    public const CRITERIA_TYPES = ['join', 'post_count', 'reputation'];

    /** Criteria types re-checkable from $trigger events (used by the listeners to scope evaluation). */
    public const TRIGGER_JOIN = 'join';

    public const TRIGGER_POST_COUNT = 'post_count';

    public const TRIGGER_REPUTATION = 'reputation';

    /**
     * Evaluate the active badges for $user — all of them, or only those whose criteria type matches
     * $trigger (the event listeners scope to their own trigger; the cron sweep passes null).
     * Returns how many NEW awards were made.
     *
     * @param  Collection<int, Badge>|null  $badges  prefetched ACTIVE badge catalog —
     *                                               the cron sweep passes it once per run instead of re-querying the invariant catalog per user
     */
    public function evaluate(User $user, ?string $trigger = null, $badges = null): int
    {
        if (! $user->getKey()) {
            return 0;
        }

        $badges ??= self::activeBadges();
        if ($trigger !== null) {
            $badges = $badges->filter(fn (Badge $b): bool => (($b->criteria['type'] ?? null) === $trigger));
        }

        if ($badges->isEmpty()) {
            return 0;
        }

        // One read of the existing award set so the sweep never issues a write per already-held badge.
        $held = DB::table('user_badges')->where('user_id', $user->getKey())
            ->pluck('badge_id')->map(fn ($i): int => (int) $i)->flip();

        // Lazily counted only when a post_count badge is actually in play. APPROVED posts only — the
        // PostCreated/TopicCreated emitters fire for approved content only, and the cron sweep must apply
        // the same bar, or held/rejected spam would earn permanent badges (adversarial-review finding).
        $postCount = null;

        $awarded = 0;
        foreach ($badges as $badge) {
            if (isset($held[(int) $badge->getKey()])) {
                continue;
            }

            $criteria = (array) $badge->criteria;
            $threshold = (int) ($criteria['threshold'] ?? 0);

            $meets = match ($criteria['type'] ?? null) {
                self::TRIGGER_JOIN => true, // the user exists — welcome aboard
                self::TRIGGER_POST_COUNT => ($postCount ??= Post::where('user_id', $user->getKey())
                    ->where('approved_state', 'approved')->count()) >= $threshold,
                self::TRIGGER_REPUTATION => (int) $user->reputation_points >= $threshold,
                default => false, // closed set: an unknown type matches nothing
            };

            if ($meets && $this->award($user, $badge)) {
                $awarded++;
            }
        }

        return $awarded;
    }

    /** The active badge catalog, freshly queried. @return \Illuminate\Support\Collection<int, Badge> */
    public static function activeBadges()
    {
        return Badge::query()->where('is_active', true)->get();
    }

    /** Idempotent award: true only when a NEW user_badges row was written (UNIQUE(user_id, badge_id)). */
    public function award(User $user, Badge $badge): bool
    {
        if (! $user->getKey() || ! $badge->getKey()) {
            return false;
        }

        return DB::table('user_badges')->insertOrIgnore([
            'user_id' => (int) $user->getKey(),
            'badge_id' => (int) $badge->getKey(),
            'awarded_at' => now(),
        ]) > 0;
    }

    /**
     * Validate an ACP-submitted criteria document against the closed set. Returns the NORMALISED
     * document (exactly {type} or {type, threshold}) or null when invalid — the only shape ever stored.
     *
     * @param  array<string, mixed>  $criteria
     * @return array{type: string, threshold?: int}|null
     */
    public static function validateCriteria(array $criteria): ?array
    {
        $type = $criteria['type'] ?? null;
        if (! is_string($type) || ! in_array($type, self::CRITERIA_TYPES, true)) {
            return null;
        }

        if ($type === self::TRIGGER_JOIN) {
            return ['type' => $type]; // join carries no threshold
        }

        $threshold = $criteria['threshold'] ?? null;
        if (! is_numeric($threshold) || (int) $threshold < 1) {
            return null;
        }

        return ['type' => $type, 'threshold' => (int) $threshold];
    }
}
