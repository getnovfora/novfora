<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Members;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\UsernameHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * U8 (ADR-0106): the single write chokepoint for post-creation username changes — an ADMIN action
 * (gated by users.manage + the rank/no-self guard at the call site), never user-facing (BUG-019).
 * A service method, NOT a model event: every "record history + audit" feature here (post edits, bans,
 * trust overrides, reputation adjustments) is an explicit transactional service, so the guards stay
 * colocated with the write.
 *
 * NOTE (accepted consequence, ADR-0106): usernames are route keys (User::getRouteKeyName), so a change
 * 404s the member's old /users/{username} URL immediately — revertTo() is the recovery path; the
 * Redirect/LegacyRedirects layer is deliberately NOT wired to username changes this pass.
 */
class UsernameService
{
    /**
     * Rename $target to $newUsername, recording a username_history row + an audit row in one transaction.
     *
     * @throws ValidationException on an invalid, taken, or unchanged name
     */
    public function change(User $target, string $newUsername, User $actor, ?string $reason = null): void
    {
        $this->apply($target, $newUsername, $actor, $reason, 'user.username.changed');
    }

    /**
     * Restore a previous username from the member's OWN history. FAILS LOUD (ValidationException) when
     * the old name has since been claimed by someone else — a revert must never auto-suffix its way into
     * a name the admin didn't ask for (that would silently defeat the revert).
     *
     * @throws ValidationException when the entry belongs to a different member or the name is now taken
     */
    public function revertTo(User $target, UsernameHistory $entry, User $actor): void
    {
        if ((int) $entry->user_id !== (int) $target->getKey()) {
            throw ValidationException::withMessages([
                'username' => 'That history entry does not belong to this member.',
            ]);
        }

        $this->apply($target, (string) $entry->old_username, $actor, 'Reverted to a previous username.', 'user.username.reverted');
    }

    private function apply(User $target, string $newUsername, User $actor, ?string $reason, string $action): void
    {
        DB::transaction(function () use ($target, $newUsername, $actor, $reason, $action): void {
            // Re-read the current name UNDER A LOCK (held until commit) so two racing changes/reverts
            // serialize and the history row + audit from→to stay truthful (the ReputationService::adminAdjust
            // / TrustLevelManager shape).
            $current = User::whereKey($target->getKey())->lockForUpdate()->value('username');

            if ($newUsername === $current) {
                throw ValidationException::withMessages(['username' => "That is already this member's username."]);
            }

            // The registration rule (CreateNewUser), ignoring the target's own row: a revert legitimately
            // writes back a value that WAS this member's, but a name someone ELSE now holds fails loud here.
            Validator::make(['username' => $newUsername], [
                'username' => ['required', 'string', 'alpha_dash', 'min:3', 'max:30', Rule::unique(User::class)->ignore($target->getKey())],
            ], [
                'username.alpha_dash' => 'The username may only contain letters, numbers, dashes and underscores.',
            ])->validate();

            // Snapshot the prior name BEFORE the overwrite (the post_revisions ordering), same transaction.
            UsernameHistory::create([
                'user_id' => $target->getKey(),
                'old_username' => (string) $current,
                'new_username' => $newUsername,
                'changed_by' => $actor->getKey(),
                'reason' => $reason,
            ]);

            $target->forceFill(['username' => $newUsername])->save();

            // Audit with the EXPLICIT actor (not ambient auth()) — the TrustLevelManager::manualSet idiom,
            // robust for any future console/queued caller.
            AuditLog::create([
                'actor_id' => $actor->getKey(),
                'action' => $action,
                'auditable_type' => $target::class,
                'auditable_id' => $target->getKey(),
                'changes' => ['from' => $current, 'to' => $newUsername, 'reason' => $reason],
                'ip_address' => request()->ip(),
                'created_at' => now(),
            ]);
        });
    }
}
