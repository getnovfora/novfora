<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\AntiSpam;

use App\Jobs\RegenerateUserPostHtml;
use App\Models\AuditLog;
use App\Models\Group;
use App\Models\Post;
use App\Models\TopicRead;
use App\Models\User;
use App\Models\Warning;
use App\Permissions\MembershipCache;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Trust-level auto promotion / demotion (ADR-0007 §2.3 / data-model §4).
 *
 * Trust levels ARE ACL groups (TrustGateSeeder seeds their gates), so promoting a user = moving their
 * trust-group membership; the permission engine then resolves the new gates with no second system. This
 * runs from the scheduler (`novfora:trust:recompute`, cron — ADR-0011), is idempotent, and reads the
 * numeric promotion thresholds from each TL group's `auto_promotion` config.
 *
 * Rules: a live infraction-point total ≥ `demotion_points` demotes to TL0; a non-active status (pending /
 * suspended) freezes promotion at the current level; a sub-demotion live warning no longer PINS a member in
 * new-user moderation — an active member still graduates to TL1 but is CAPPED there until the warning clears
 * (ADR-0092); otherwise the user settles at the highest level whose thresholds they meet. TL4 (leader) is
 * manual — never auto-granted, and only the hard demotion can lower it.
 */
final class TrustLevelManager
{
    /** Promotable levels in order; TL4 is manual (auto_promotion.manual = true). */
    private const PROMOTABLE = ['tl1' => 1, 'tl2' => 2, 'tl3' => 3];

    /** Recompute the user's trust level, sync their TL group, and persist. Returns the resulting level. */
    public function recompute(User $user): int
    {
        $target = $this->evaluate($user);
        $this->setLevel($user, $target);

        return $target;
    }

    /**
     * v1.x F2 (ADR-0101): an ADMIN MANUAL trust-level override. A deliberate staff action (gated by
     * members.trust.manage + the rank guard at the call site), so — unlike the cron recompute — it is NOT
     * anti-spam-gated and may move the level in EITHER direction. It reuses the SAME trust-group swap +
     * MembershipCache seam as the auto path, then marks `trust_locked` so {@see evaluate()} treats the level
     * as a sticky floor (the auto-engine may still promote above it, a hard-infraction total still hard-demotes
     * to TL0, but structural auto-demotion can no longer silently undo the admin's choice). Audited with the
     * actor + reason. Idempotent re-affirm when the level is unchanged (re-asserts the lock).
     */
    public function manualSet(User $user, int $level, User $actor, ?string $reason = null): void
    {
        $level = max(0, min(4, $level));
        $from = $this->swapTrustGroup($user, $level, lock: true);
        if ($from === -1) {
            return; // a tenant install missing this level — membership left untouched
        }

        // Audit with the EXPLICIT actor (not ambient auth()), written directly so actor_id reflects $actor —
        // robust for any future console/queued caller and consistent with ReputationService::adminAdjust().
        AuditLog::create([
            'actor_id' => $actor->getKey(),
            'action' => 'user.trust.manual_set',
            'auditable_type' => $user::class,
            'auditable_id' => $user->getKey(),
            'changes' => ['from' => $from, 'to' => $level, 'by' => (int) $actor->getKey(), 'reason' => $reason],
            'ip_address' => request()->ip(),
            'created_at' => now(),
        ]);

        // Re-render this user's posts so link/image suppression matches the NEW level (re-suppress on a manual
        // demotion, reveal on a promotion). Queued; drained by the cron line on the baseline tier.
        if ($from !== $level) {
            RegenerateUserPostHtml::dispatch((int) $user->getKey());
        }
    }

    /** The trust level the user should be at right now (pure; no writes). */
    public function evaluate(User $user): int
    {
        $points = $this->liveInfractionPoints($user);

        // Serious infractions → hard demotion to TL0, even for a leader.
        if ($points >= (int) config('novfora.antispam.trust.demotion_points', 10)) {
            return 0;
        }

        $current = (int) $user->trust_level;

        // TL4 leaders are managed manually; only the hard demotion above can move them.
        if ($current === 4) {
            return 4;
        }

        // A non-active status (pending / suspended) freezes promotion entirely at the current level.
        if (($user->status ?? 'active') !== 'active') {
            return $current;
        }

        $earned = $this->earnedLevel($user);

        // ADR-0092: a sub-demotion live warning no longer PINS a member in new-user moderation. An ACTIVE member
        // who has earned TL1 still graduates to TL1 (escaping the TL0 hold); the warning still CAPS promotion at
        // TL1 (no climb to TL2+ while a warning is live) and never demotes — the target is max(current, min(earned, 1)).
        if ($points > 0) {
            return max($current, min($earned, 1)); // 1 = TL1
        }

        // v1.x F2 (ADR-0101): a MANUAL admin override (trust_locked) is a sticky FLOOR — structural
        // auto-(de)motion never pulls the member below where an admin deliberately set them. The engine may
        // still auto-PROMOTE above the floor when earned (max keeps the higher of the two), and the hard
        // infraction demotion + status freeze above still apply (this branch is only reached past them).
        if ($user->trust_locked ?? false) {
            return max($current, $earned);
        }

        return $earned;
    }

    /**
     * The reason the user's trust promotion is currently held back, or null if nothing freezes it. Read-only;
     * reuses the SAME freeze logic as evaluate() so the diagnosis never drifts from the engine's decision.
     */
    public function freezeReason(User $user): ?string
    {
        $points = $this->liveInfractionPoints($user);
        $demotion = (int) config('novfora.antispam.trust.demotion_points', 10);

        if ($points >= $demotion) {
            return "hard demotion: {$points} live infraction point(s) ≥ {$demotion}";
        }
        if (($user->status ?? 'active') !== 'active') {
            return 'frozen: status != active ('.($user->status ?? 'active').')';
        }
        // A live warning no longer freezes the TL0→TL1 graduation (ADR-0092); it CAPS promotion at TL1, so it
        // only holds a member back when they've otherwise earned past TL1 (mirrors evaluate()'s cap).
        if ($points > 0 && $this->earnedLevel($user) > 1) {
            return "promotion capped at TL1 by {$points} live warning point(s)";
        }

        return null;
    }

    /**
     * A read-only diagnosis of WHERE the user's trust level stands and WHY — drives the `--user` recompute
     * diagnostic and the moderation-queue hold reason. Reuses evaluate()/earnedLevel()/freezeReason() so it can
     * never disagree with the engine (no forked thresholds).
     *
     * @return array{current:int, target:int, reason:string}
     */
    public function explain(User $user): array
    {
        $current = (int) $user->trust_level;
        $target = $this->evaluate($user);
        $freeze = $this->freezeReason($user);

        if ($freeze !== null) {
            $reason = $freeze;
        } elseif ($current === 4) {
            $reason = 'TL4 (leader) — manual level, not auto-changed';
        } else {
            $earned = $this->earnedLevel($user);
            if ($earned > $current) {
                $reason = "eligible → promoted to TL{$earned}";
            } elseif ($earned < $current) {
                $reason = "structural demotion → TL{$earned}";
            } else {
                $reason = $this->belowThresholdReason($user, $current);
            }
        }

        return ['current' => $current, 'target' => $target, 'reason' => $reason];
    }

    /** Explain the gap to the next rung — "below threshold (posts X/5, days Y/1, reads Z/5)". */
    private function belowThresholdReason(User $user, int $current): string
    {
        $next = $current + 1;
        $rules = $this->rules('tl'.$next);
        if ($rules === null || ($rules['manual'] ?? false)) {
            return "at TL{$current}; TL{$next} is not auto-granted";
        }

        $posts = Post::where('user_id', $user->getKey())->count();
        $reads = TopicRead::where('user_id', $user->getKey())->count();
        $days = $user->created_at ? (int) abs($user->created_at->diffInDays(now())) : 0;

        $parts = [
            'posts '.$posts.'/'.(int) ($rules['min_posts'] ?? 0),
            'days '.$days.'/'.(int) ($rules['min_days'] ?? 0),
            'reads '.$reads.'/'.(int) ($rules['min_topics_read'] ?? 0),
        ];
        if ((int) ($rules['min_reputation'] ?? 0) > 0) {
            $parts[] = 'reputation '.(int) $user->reputation_points.'/'.(int) $rules['min_reputation'];
        }

        return "below threshold for TL{$next} (".implode(', ', $parts).')';
    }

    /**
     * Highest level whose thresholds the user meets (cumulative). Phase-1.5 F-D: TL0→TL1 now requires the
     * spec's §2.3 engagement signals — posts AND tenure AND topics-READ (from M4's topic_reads) — not a raw
     * self-post count, so a patient self-poster can't lift the TL0 link/image NEVER gate by talking to
     * themselves. The "no active flags" half is enforced by evaluate() (a live flag freezes promotion).
     *
     * A3: each rung's `auto_promotion` may also carry a `min_reputation` bar (seeded on tl2/tl3) checked
     * against the denormalised `users.reputation_points`. Reputation is a PROMOTION-ONLY gate: it can block
     * climbing to a level ABOVE the user's current standing, but it never pulls a member BELOW a level they
     * already hold (no spurious demotion for a reputation dip). Structural demotion — losing the posts/tenure/
     * reads for a level you sit at — is unchanged.
     */
    private function earnedLevel(User $user): int
    {
        $posts = Post::where('user_id', $user->getKey())->count();
        $reads = TopicRead::where('user_id', $user->getKey())->count(); // distinct topics read (one row per topic)
        $days = $user->created_at ? (int) abs($user->created_at->diffInDays(now())) : 0;
        $reputation = (int) $user->reputation_points;
        $current = (int) $user->trust_level;

        $target = 0;
        foreach (self::PROMOTABLE as $slug => $level) {
            $rules = $this->rules($slug);
            if ($rules === null || ($rules['manual'] ?? false)) {
                break;
            }

            $structuralMet = $posts >= (int) ($rules['min_posts'] ?? 0)
                && $days >= (int) ($rules['min_days'] ?? 0)
                && $reads >= (int) ($rules['min_topics_read'] ?? 0)
                && $target >= (int) ($rules['min_trust_level'] ?? 0);

            // Reputation gates climbing ABOVE the current level only; a rung at or below where the user already
            // sits is exempt, so a reputation shortfall can never demote them.
            $reputationMet = $level <= $current
                || $reputation >= (int) ($rules['min_reputation'] ?? 0);

            if (! ($structuralMet && $reputationMet)) {
                break; // levels are cumulative — stop at the first unmet rung
            }
            $target = $level;
        }

        return $target;
    }

    private function setLevel(User $user, int $target): void
    {
        $target = max(0, min(4, $target));
        $from = $this->swapTrustGroup($user, $target, lock: false);

        if ($from === -1 || $from === $target) {
            return; // a missing level, or an unchanged recompute — no audit, no re-render (the swap is a no-op)
        }

        Audit::log($target > $from ? 'user.trust.promoted' : 'user.trust.demoted', $user, ['from' => $from, 'to' => $target]);

        // Phase-1.5 F-E: re-render this user's posts so link/image suppression matches their NEW trust level —
        // re-suppress on demotion (the security-relevant direction), reveal on promotion. Queued, so a spammer
        // with many posts doesn't stall the recompute; drained by the cron line.
        RegenerateUserPostHtml::dispatch((int) $user->getKey());
    }

    /**
     * Atomically swap the user's single trust group to tl{level} under a USER-ROW LOCK, refresh the resolver
     * caches, and persist the denorm column — returning the prior level (or -1 when the level's group is
     * missing on this install, so the caller no-ops). The lock SERIALISES concurrent trust changes (a manual
     * set racing the cron recompute, or two admins), so the member can never transiently hold two trust groups
     * — which PermissionResolver would otherwise read as the most-permissive UNION of both levels. Mirrors the
     * locking discipline proven in ReputationService::award(). $lock=true marks the level a sticky admin
     * override (trust_locked) and writes even on an unchanged level (re-affirming the lock); the auto path
     * (lock=false) writes the column only on an actual change.
     */
    private function swapTrustGroup(User $user, int $level, bool $lock): int
    {
        $group = Group::where('slug', 'tl'.$level)->first();
        if (! $group instanceof Group) {
            return -1;
        }

        return DB::transaction(function () use ($user, $level, $group, $lock): int {
            // Authoritative prior level under the row lock — anything reading/writing this user's trust waits.
            $from = (int) (User::whereKey($user->getKey())->lockForUpdate()->value('trust_level') ?? 0);

            // The pivot writes fire no model events, so invalidate the resolver caches explicitly (ACP v3 · v3-e
            // seam, ADR-0083): refresh the in-memory groups relation + flush the per-request memo + VisibleForumIds,
            // and on an actual CHANGE bump the version (a move can return the user to a previously-cached
            // signature). An unchanged swap stays on the cheap signature path (no hourly-sweep thrash).
            $user->groups()->detach(Group::where('type', 'trust')->pluck('id')->all());
            $user->groups()->attach($group->getKey(), ['is_primary' => false]);
            MembershipCache::flushFor($user, bumpVersion: $from !== $level);

            // Persist the denorm INSIDE the transaction so the trust GROUP and the column can never diverge.
            // The auto path writes only on an actual change; a manual override ALSO sets the sticky flag and
            // writes even on an unchanged level (re-affirming the lock).
            if ($lock) {
                $user->forceFill(['trust_level' => $level, 'trust_locked' => true])->save();
            } elseif ($from !== $level) {
                $user->forceFill(['trust_level' => $level])->save();
            }

            return $from;
        });
    }

    private function liveInfractionPoints(User $user): int
    {
        return (int) Warning::where('user_id', $user->getKey())->live()->sum('points');
    }

    /** @return array<string,mixed>|null */
    private function rules(string $slug): ?array
    {
        return Group::where('slug', $slug)->first()?->auto_promotion;
    }
}
