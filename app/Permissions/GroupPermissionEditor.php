<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Permissions;

use App\Models\AclEntry;
use App\Models\Forum;
use App\Models\Group;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * ACP v3 · v3-c — the write engine behind the card-per-group permission editor (the headline "simple mode").
 *
 * It edits a GROUP's OWN `acl_entries` rows directly at a chosen scope (global / forum / club) — it does NOT go
 * through RoleExpander (that expands ROLE presets into entries; this edits a group's standing entries). The
 * three UI states map onto the three-state engine value:
 *   - 'yes'   → an ALLOW row (+1)
 *   - 'never' → a NEVER row (-1)
 *   - 'no'    → NO ROW (delete it) → the group inherits from the parent scope (NOT a value=0 row)
 *
 * Every write goes through AclEntry, whose model events bump AclVersion automatically, so the resolver memo +
 * caches refresh. This service is AUTH-AGNOSTIC: callers (the Livewire editor) enforce the manage-permissions
 * capability + the rank guard before invoking it. The PermissionInspector is the oracle for its correctness.
 */
final class GroupPermissionEditor
{
    /** The three editor states. */
    public const STATES = ['yes', 'no', 'never'];

    /** Keys that, stripped from the system admins group at global scope, would brick ACP recovery for everyone. */
    private const ADMIN_RECOVERY_KEYS = ['admin.access', 'permissions.manage'];

    public function __construct(private readonly AclVersion $version) {}

    /**
     * True when (group, key) is a recovery anchor — the system `admins` group's own `admin.access` /
     * `permissions.manage`. Removing/denying it at global scope would lock every admin out of the panel with no
     * in-app way back (the last-owner-guard concern; the full board-wide co-owner guard arrives in v3-a).
     */
    public function protectsAdminRecovery(Group $group, string $permissionKey): bool
    {
        return $group->slug === 'admins' && in_array($permissionKey, self::ADMIN_RECOVERY_KEYS, true);
    }

    /**
     * The current group×permission value map at a scope: [groupId][permissionKey] => engine value (1|-1).
     * A 'no' (inherit) is simply ABSENT. One query — cheap enough to render the whole matrix.
     *
     * @return array<int, array<string, int>>
     */
    public function matrix(Scope $scope): array
    {
        $rows = AclEntry::query()
            ->where('holder_type', 'group')
            ->where('scope_type', $scope->type)
            ->when($scope->id === null, fn ($q) => $q->whereNull('scope_id'), fn ($q) => $q->where('scope_id', $scope->id))
            ->get(['holder_id', 'permission_key', 'value']);

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->holder_id][$row->permission_key] = (int) $row->value;
        }

        return $map;
    }

    /** Translate an engine value (or absence) into a UI state. */
    public static function stateForValue(?int $value): string
    {
        return match ($value) {
            PermissionValue::Allow->value => 'yes',
            PermissionValue::Never->value => 'never',
            default => 'no', // absent, or a stray neutral 0 → inherit
        };
    }

    /** The explicit state of one group's entry for a permission at a scope. */
    public function stateOf(Group $group, string $permissionKey, Scope $scope): string
    {
        return self::stateForValue($this->matrix($scope)[$group->getKey()][$permissionKey] ?? null);
    }

    /**
     * Set a single group's state for a permission at a scope. 'no' DELETES the row (inherit); 'yes'/'never' write
     * an ALLOW/NEVER row. Returns true if anything changed. Audited per change unless $audit is false (the bulk
     * path audits once for the whole operation).
     */
    public function set(Group $group, string $permissionKey, Scope $scope, string $state, bool $audit = true): bool
    {
        if (! in_array($state, self::STATES, true)) {
            throw new \InvalidArgumentException("Unknown permission state: {$state}.");
        }

        // Hard invariant (actor-independent backstop): never strip the admins group's own ACP-recovery keys at
        // global scope. The Livewire editor pre-checks this for a clean 403; this throw guards any other caller.
        if ($state !== 'yes' && $scope->isGlobal() && $this->protectsAdminRecovery($group, $permissionKey)) {
            throw new \RuntimeException("Refusing to {$state} the administrators group's {$permissionKey} at global scope — it would lock everyone out of the admin panel.");
        }

        $match = [
            'permission_key' => $permissionKey,
            'holder_type' => 'group',
            'holder_id' => $group->getKey(),
            'scope_type' => $scope->type,
            'scope_id' => $scope->id,
        ];

        $before = $this->stateOf($group, $permissionKey, $scope);
        if ($before === $state) {
            return false; // no-op — don't churn AclVersion or the audit log
        }

        if ($state === 'no') {
            // A query-builder delete bypasses the per-row `deleted` model event, so bump AclVersion by hand —
            // otherwise the version-keyed can() cache would keep serving the now-removed grant (the same trap
            // the v3-0 prune cron handles). `before` was 'yes'/'never' here, so a row really did go.
            if (AclEntry::query()->where($match)->delete() > 0) {
                $this->version->bump();
            }
        } else {
            $value = $state === 'never' ? PermissionValue::Never->value : PermissionValue::Allow->value;
            AclEntry::updateOrCreate($match, ['value' => $value]); // a model save → its saved event bumps AclVersion
        }

        if ($audit) {
            Audit::log('acl.group.permission_set', $group, [
                'permission' => $permissionKey,
                'scope' => $scope->key(),
                'from' => $before,
                'to' => $state,
            ]);
        }

        return true;
    }

    /**
     * The category bulk-apply (foundations §4, DECIDED option A): copy a source forum's per-group permission
     * overrides onto EVERY forum under its parent category, in ONE transaction, audited once. This is the
     * ergonomic phpBB "copy permissions to the whole category" without introducing a category scope. Returns the
     * number of forums written. Throws if the source forum has no parent category.
     *
     * @param  list<string>  $permissionKeys  the forum-scoped keys to copy (the editor's visible set)
     */
    public function copyForumToCategory(Forum $sourceForum, array $permissionKeys): int
    {
        $category = $this->nearestCategory($sourceForum);
        if ($category === null) {
            throw new \RuntimeException('This forum has no parent category to apply to.');
        }

        $sourceScope = Scope::forum((int) $sourceForum->getKey());
        $sourceMatrix = $this->matrix($sourceScope);

        $targets = Forum::query()
            ->where('type', 'forum')
            ->whereNull('club_id') // defense-in-depth: the category bulk never reaches a club's own forums
            ->where('path', 'like', $category->path.'%')
            ->where('id', '!=', $sourceForum->getKey())
            ->get();

        $groups = Group::query()->get();

        return DB::transaction(function () use ($targets, $groups, $permissionKeys, $sourceMatrix, $sourceForum, $category) {
            foreach ($targets as $forum) {
                $scope = Scope::forum((int) $forum->getKey());
                foreach ($groups as $group) {
                    foreach ($permissionKeys as $key) {
                        $state = self::stateForValue($sourceMatrix[$group->getKey()][$key] ?? null);
                        $this->set($group, $key, $scope, $state, audit: false);
                    }
                }
            }

            Audit::log('acl.group.bulk_apply_category', $category, [
                'source_forum' => (int) $sourceForum->getKey(),
                'forums_written' => $targets->count(),
                'keys' => $permissionKeys,
            ]);

            return $targets->count();
        });
    }

    /** The nearest category-type ancestor of a forum (via the materialised path), or null. */
    public function nearestCategory(Forum $forum): ?Forum
    {
        $ids = array_values(array_filter(
            array_map('intval', explode('/', trim((string) $forum->path, '/'))),
            fn (int $id) => $id !== (int) $forum->getKey(),
        ));
        if ($ids === []) {
            return null;
        }

        return Forum::query()->whereIn('id', $ids)->where('type', 'category')->orderByDesc('depth')->first();
    }
}
