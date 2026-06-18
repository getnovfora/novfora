<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Groups;

use App\Admin\GroupException;
use App\Models\Group;
use App\Models\User;
use App\Support\Audit;

/**
 * The primary-group chooser (ACP v3 · v3-e, ADR-0083). A user's primary group drives their rank badge, name
 * colour, and the title under their avatar. Both the user AND an admin can set it, from groups the user
 * belongs to — with an admin override taking precedence:
 *
 *   • setByUser()  — the member's own choice. Refused while an admin lock is in force (the admin choice wins).
 *   • setByAdmin() — an override; sets the chosen group primary AND locks it (is_primary_locked).
 *   • clearLock()  — an admin hands the choice back to the member (keeps the current primary, drops the lock).
 *
 * Primary is COSMETIC: the resolver reads ALL of a user's groups (groupIds()), so which one is primary never
 * changes effective permissions. The pivot writes here therefore do NOT invalidate the resolver caches — there
 * is nothing to invalidate. Staff always see all memberships regardless of the primary (a display concern in
 * the UI, not enforced here).
 */
class PrimaryGroupService
{
    /** The member's own choice of primary group. Throws if they aren't a member, or an admin lock is set. */
    public function setByUser(User $user, Group $group): void
    {
        $this->assertMember($user, $group);
        if ($this->isAdminLocked($user)) {
            throw new GroupException('An administrator has set your primary group and it can’t be changed.');
        }

        $this->apply($user, $group, locked: false);
        Audit::log('group.primary.set', $group, ['user_id' => (int) $user->getKey(), 'by' => 'user']);
    }

    /** An admin override: set the primary group and lock it so the member can't change it. */
    public function setByAdmin(User $user, Group $group, User $actor): void
    {
        $this->assertMember($user, $group);

        $this->apply($user, $group, locked: true);
        Audit::log('group.primary.set', $group, ['user_id' => (int) $user->getKey(), 'by' => 'admin', 'actor' => (int) $actor->getKey()]);
    }

    /** Hand the choice back to the member: keep the current primary but drop the admin lock. */
    public function clearLock(User $user, User $actor): void
    {
        foreach ($user->groups()->wherePivot('is_primary_locked', true)->pluck('groups.id') as $id) {
            $user->groups()->updateExistingPivot((int) $id, ['is_primary_locked' => false]);
        }
        $user->load('groups');
        Audit::log('group.primary.unlocked', null, ['user_id' => (int) $user->getKey(), 'actor' => (int) $actor->getKey()]);
    }

    public function isAdminLocked(User $user): bool
    {
        return $user->groups()->wherePivot('is_primary_locked', true)->exists();
    }

    /** Set exactly one group primary (with the given lock state), clearing primary/lock on every other group. */
    private function apply(User $user, Group $group, bool $locked): void
    {
        foreach ($user->groups as $g) {
            $isChosen = (int) $g->getKey() === (int) $group->getKey();
            $user->groups()->updateExistingPivot($g->getKey(), [
                'is_primary' => $isChosen,
                'is_primary_locked' => $locked && $isChosen,
            ]);
        }
        $user->load('groups'); // refresh so primaryGroup() reflects the new choice in this request's render
    }

    private function assertMember(User $user, Group $group): void
    {
        if (! $user->groups()->whereKey($group->getKey())->exists()) {
            throw new GroupException('A primary group must be one the user belongs to.');
        }
    }
}
