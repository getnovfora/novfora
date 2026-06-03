<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\AntiSpam;

use App\Models\Group;
use App\Models\Post;
use App\Models\User;
use App\Models\Warning;
use App\Support\Audit;

/**
 * Trust-level auto promotion / demotion (ADR-0007 §2.3 / data-model §4).
 *
 * Trust levels ARE ACL groups (TrustGateSeeder seeds their gates), so promoting a user = moving their
 * trust-group membership; the permission engine then resolves the new gates with no second system. This
 * runs from the scheduler (`hearth:trust:recompute`, cron — ADR-0011), is idempotent, and reads the
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
        if ($points >= (int) config('hearth.antispam.trust.demotion_points', 10)) {
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

    /** Highest level whose post/tenure/prior-level thresholds the user meets (cumulative). */
    private function earnedLevel(User $user): int
    {
        $posts = Post::where('user_id', $user->getKey())->count();
        $days = $user->created_at ? (int) abs($user->created_at->diffInDays(now())) : 0;

        $target = 0;
        foreach (self::PROMOTABLE as $slug => $level) {
            $rules = $this->rules($slug);
            if ($rules === null || ($rules['manual'] ?? false)) {
                break;
            }

            $meets = $posts >= (int) ($rules['min_posts'] ?? 0)
                && $days >= (int) ($rules['min_days'] ?? 0)
                && $target >= (int) ($rules['min_trust_level'] ?? 0);

            if (! $meets) {
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

        if ($from !== $target) {
            $user->forceFill(['trust_level' => $target])->save();
            Audit::log($target > $from ? 'user.trust.promoted' : 'user.trust.demoted', $user, ['from' => $from, 'to' => $target]);
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
