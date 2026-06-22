<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\AntiSpam;

use App\Jobs\RegenerateUserPostHtml;
use App\Models\Group;
use App\Models\Post;
use App\Models\TopicRead;
use App\Models\User;
use App\Models\Warning;
use App\Permissions\MembershipCache;
use App\Support\Audit;

/**
 * Trust-level auto promotion / demotion (ADR-0007 §2.3 / data-model §4).
 *
 * Trust levels ARE ACL groups (TrustGateSeeder seeds their gates), so promoting a user = moving their
 * trust-group membership; the permission engine then resolves the new gates with no second system. This
 * runs from the scheduler (`novfora:trust:recompute`, cron — ADR-0011), is idempotent, and reads the
 * numeric promotion thresholds from each TL group's `auto_promotion` config.
 *
 * Rules: a live infraction-point total ≥ `demotion_points` demotes to TL0; any lesser live flag (a warning
 * or a non-active status) freezes promotion at the current level; otherwise the user settles at the highest
 * level whose thresholds they meet. TL4 (leader) is manual — never auto-granted, and only the hard demotion
 * can lower it.
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

        // A lesser live flag (a warning, or a pending/suspended status) freezes promotion at the current level.
        if ($points > 0 || ($user->status ?? 'active') !== 'active') {
            return $current;
        }

        return $this->earnedLevel($user);
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
        if ($points > 0) {
            return "frozen: {$points} live warning point(s)";
        }
        if (($user->status ?? 'active') !== 'active') {
            return 'frozen: status != active ('.($user->status ?? 'active').')';
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
        $group = Group::where('slug', 'tl'.$target)->first();
        if (! $group instanceof Group) {
            return; // a tenant install missing this level — leave membership untouched rather than break
        }

        $from = (int) $user->trust_level;

        // A user is in exactly one trust group (kept secondary). Detaching/attaching changes the user's
        // group-set signature, so the resolved-permission cache key changes and resolution stays correct
        // without an explicit version bump.
        $user->groups()->detach(Group::where('type', 'trust')->pluck('id')->all());
        $user->groups()->attach($group->getKey(), ['is_primary' => false]);

        // The pivot writes above fire no model events, so invalidate this user's resolver caches explicitly
        // (ACP v3 · v3-e seam, ADR-0083 — G9's sibling): refresh the in-memory groups relation + flush the
        // per-request memo and VisibleForumIds, so the re-render below and any later check this process
        // (e.g. the cron recompute loop) re-resolve against the NEW trust group. On an actual level CHANGE
        // (incl. a demotion, which can return the user to a previously-cached signature) also bump the version;
        // an unchanged recompute is a no-op swap, so it stays on the cheap signature path (no hourly-sweep thrash).
        MembershipCache::flushFor($user, bumpVersion: $from !== $target);

        if ($from !== $target) {
            $user->forceFill(['trust_level' => $target])->save();
            Audit::log($target > $from ? 'user.trust.promoted' : 'user.trust.demoted', $user, ['from' => $from, 'to' => $target]);

            // Phase-1.5 F-E: re-render this user's posts so link/image suppression matches their NEW trust
            // level — re-suppress on demotion (the security-relevant direction), reveal on promotion. Queued,
            // so a spammer with many posts doesn't stall the recompute; drained by the cron line.
            RegenerateUserPostHtml::dispatch((int) $user->getKey());
        }
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
