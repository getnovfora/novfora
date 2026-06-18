<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Groups;

use App\Models\Group;
use App\Models\User;
use App\Permissions\MembershipCache;
use App\Support\Audit;
use Illuminate\Support\Collection;

/**
 * AND/OR auto-promotion into CUSTOM groups (ACP v3 · v3-e, ADR-0083). Generalises the Stage-A A3 trust-level
 * floor (TrustLevelManager) — same shape (a group's `auto_promotion` JSON is the criteria config; evaluation
 * runs from the cron + the criterion-moving events; promotion is idempotent) — but lifts the criteria from a
 * flat all-AND list to an arbitrary AND/OR tree of the four documented criteria.
 *
 * INVARIANTS:
 *   • PROMOTION-ONLY. This only ever ATTACHES a user to a group they qualify for; it never detaches/demotes.
 *     A user who later drops below the criteria keeps the group (mirrors A3 reputation gating). Demotion, if
 *     ever wanted, is a separate deliberate action.
 *   • IDEMPOTENT. Re-running converges: a user already in the group is skipped (no duplicate, no re-audit,
 *     no cache churn); `syncWithoutDetaching` + the pivot UNIQUE make a concurrent double-run a no-op.
 *   • CUSTOM GROUPS ONLY. System groups (Guests/Members auto-assigned) and trust groups (tl0…tl4, owned by
 *     TrustLevelManager with its own demotion semantics) are excluded — never auto-promoted here.
 *   • HOLDER CHANGE → INVALIDATE. A promotion adds a permission holder without touching acl_entries, so each
 *     promotion calls MembershipCache::flushFor() (the v3-e seam, G9's sibling).
 *
 * RULE TREE shape (stored in `groups.auto_promotion`):
 *   { "op": "AND"|"OR", "rules": [ <leaf> | <nested node> ] }
 *   leaf:  { "criterion": "posts"|"tenure_days"|"trust"|"reputation", "gte": <int> }
 *
 * BACK-COMPAT: the legacy flat shape { "min_posts":N, "min_days":N, "min_trust_level":N, "min_reputation":N }
 * still evaluates — normalize() wraps it as one AND node — so pre-v3 custom-group configs promote unchanged.
 */
final class GroupAutoPromoter
{
    /** Legacy flat key → criterion name (the back-compat map). */
    private const LEGACY_KEYS = [
        'min_posts' => 'posts',
        'min_days' => 'tenure_days',
        'min_trust_level' => 'trust',
        'min_reputation' => 'reputation',
    ];

    /** The closed criteria vocabulary (a leaf with any other criterion never matches). */
    public const CRITERIA = ['posts', 'tenure_days', 'trust', 'reputation'];

    /**
     * Evaluate every candidate group for $user and attach the ones they now qualify for. Returns the number
     * of groups they were newly promoted into.
     */
    public function promote(User $user): int
    {
        $candidates = $this->candidateGroups();
        if ($candidates->isEmpty()) {
            return 0; // early-out: nothing auto-promotes on this board (keeps the event hot path ~free)
        }

        $metrics = $this->metrics($user);
        $promoted = 0;

        foreach ($candidates as $group) {
            $tree = $this->normalize($group->auto_promotion);
            if ($tree === null || ! $this->satisfiesTree($tree, $metrics)) {
                continue;
            }
            if ($user->groups()->whereKey($group->getKey())->exists()) {
                continue; // already a member — idempotent skip (no duplicate, no churn)
            }

            // Idempotent attach (UNIQUE-safe even under a concurrent cron+event double-run).
            $user->groups()->syncWithoutDetaching([$group->getKey() => ['is_primary' => false]]);

            // Holder change with no acl_entries write → invalidate this user's resolver caches (v3-e seam).
            MembershipCache::flushFor($user);
            Audit::log('group.autopromoted', $group, ['user_id' => (int) $user->getKey()]);
            $promoted++;
        }

        return $promoted;
    }

    /** Whether any board group auto-promotes at all (cheap existence check for hot-path early-outs/tests). */
    public function hasCandidates(): bool
    {
        return $this->candidateGroups()->isNotEmpty();
    }

    /**
     * The user's current metric values for the four criteria. Reads the denormalised user columns (the same
     * standing-snapshot the kickoff names as the criteria source): post_count, tenure in days since
     * registration, trust level, and reputation points.
     *
     * @return array<string,int>
     */
    public function metrics(User $user): array
    {
        return [
            'posts' => (int) ($user->post_count ?? 0),
            'tenure_days' => $user->created_at ? (int) abs($user->created_at->diffInDays(now())) : 0,
            'trust' => $user->trustLevel(),
            'reputation' => (int) ($user->reputation_points ?? 0),
        ];
    }

    /**
     * Normalise a group's stored `auto_promotion` config into a rule tree, or null when it carries no
     * auto-promotion (empty, or the trust-style `manual` marker). Public so the builder UI + tests can reuse it.
     *
     * @param  array<string,mixed>|null  $config
     * @return array{op:string,rules:list<array<string,mixed>>}|null
     */
    public function normalize(?array $config): ?array
    {
        if (! is_array($config) || $config === []) {
            return null;
        }
        if (($config['manual'] ?? false) === true) {
            return null; // trust-style "managed manually" — never auto-promotes
        }

        // Already a nested tree: sanitise it (drop malformed leaves/nodes) and keep.
        if (isset($config['op']) || isset($config['rules'])) {
            return $this->sanitizeNode($config);
        }

        // Legacy flat shape → one AND node of leaves for the recognised min_* keys.
        $rules = [];
        foreach (self::LEGACY_KEYS as $legacy => $criterion) {
            if (array_key_exists($legacy, $config) && is_numeric($config[$legacy])) {
                $rules[] = ['criterion' => $criterion, 'gte' => (int) $config[$legacy]];
            }
        }

        return $rules === [] ? null : ['op' => 'AND', 'rules' => $rules];
    }

    /**
     * @param  array<string,mixed>  $tree  a normalised node {op, rules}
     * @param  array<string,int>  $metrics
     */
    public function satisfiesTree(array $tree, array $metrics): bool
    {
        $op = strtoupper((string) ($tree['op'] ?? 'AND'));
        $rules = is_array($tree['rules'] ?? null) ? $tree['rules'] : [];
        if ($rules === [] || ! in_array($op, ['AND', 'OR'], true)) {
            return false; // an empty or malformed node never promotes (fail-closed)
        }

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                if ($op === 'AND') {
                    return false;
                }

                continue;
            }

            $met = isset($rule['op']) || isset($rule['rules'])
                ? $this->satisfiesTree($rule, $metrics)          // nested node
                : $this->satisfiesLeaf($rule, $metrics);         // leaf criterion

            if ($op === 'AND' && ! $met) {
                return false;
            }
            if ($op === 'OR' && $met) {
                return true;
            }
        }

        return $op === 'AND'; // AND: all passed; OR: none passed
    }

    /** @param array<string,mixed> $leaf  @param array<string,int> $metrics */
    private function satisfiesLeaf(array $leaf, array $metrics): bool
    {
        $criterion = (string) ($leaf['criterion'] ?? '');
        if (! array_key_exists($criterion, $metrics) || ! is_numeric($leaf['gte'] ?? null)) {
            return false; // unknown criterion / missing threshold → fail-closed
        }

        return $metrics[$criterion] >= (int) $leaf['gte'];
    }

    /**
     * Drop anything that isn't a recognised leaf/node so a hand-edited or partially-built tree can never
     * accidentally evaluate true. Returns null if nothing survives.
     *
     * @param  array<string,mixed>  $node
     * @return array{op:string,rules:list<array<string,mixed>>}|null
     */
    private function sanitizeNode(array $node): ?array
    {
        $op = strtoupper((string) ($node['op'] ?? 'AND'));
        if (! in_array($op, ['AND', 'OR'], true)) {
            $op = 'AND';
        }
        $rules = is_array($node['rules'] ?? null) ? $node['rules'] : [];

        $clean = [];
        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            if (isset($rule['op']) || isset($rule['rules'])) {
                if (($child = $this->sanitizeNode($rule)) !== null) {
                    $clean[] = $child;
                }
            } elseif (in_array((string) ($rule['criterion'] ?? ''), self::CRITERIA, true) && is_numeric($rule['gte'] ?? null)) {
                $clean[] = ['criterion' => (string) $rule['criterion'], 'gte' => (int) $rule['gte']];
            }
        }

        return $clean === [] ? null : ['op' => $op, 'rules' => $clean];
    }

    /** @return Collection<int, Group> custom groups carrying a non-empty auto-promotion config. */
    private function candidateGroups(): Collection
    {
        return Group::query()
            ->where('type', 'custom')
            ->whereNotNull('auto_promotion')
            ->get()
            ->filter(fn (Group $g): bool => $this->normalize($g->auto_promotion) !== null)
            ->values();
    }
}
