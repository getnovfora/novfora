<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Moderation;

use App\Models\StaffNote;
use App\Models\User;
use App\Permissions\Scope;

/**
 * The single authority for staff-note visibility and management (A1) — the sibling of
 * App\Community\MembersDirectory and App\Account\AccountDeletionService::canForceDelete. Every surface (the
 * profile @if, the ⚡staff-notes SFC mount guard, and every SFC action) routes through these two predicates,
 * so the "staff-only, never the subject" rule has exactly one definition.
 *
 * Authorisation is expressed entirely through the EXISTING permission engine (`bans.manage`, global) — there
 * is no second permission system and no new mask semantics. `bans.manage` is held by moderators and admins
 * (RoleSeeder), i.e. staff; the additional `viewer !== subject` clause is what keeps a note off the subject's
 * own view even when the subject is themselves staff.
 */
final class StaffNotes
{
    /** A staff viewer may see notes about ANOTHER member — never about themselves, never as a non-staff user. */
    public static function visibleTo(?User $viewer, User $subject): bool
    {
        return $viewer instanceof User
            && (int) $viewer->getKey() !== (int) $subject->getKey()
            && $viewer->canDo('bans.manage', Scope::global());
    }

    /**
     * Edit/delete a specific note. Any staff member may ADD a note, but only its author or an admin may modify
     * or remove it. A note whose author has been deleted (author_id NULL) is manageable only by an admin.
     */
    public static function canManage(?User $viewer, StaffNote $note): bool
    {
        if (! $viewer instanceof User || ! $viewer->canDo('bans.manage', Scope::global())) {
            return false;
        }

        return ($note->author_id !== null && (int) $note->author_id === (int) $viewer->getKey())
            || $viewer->isAdmin();
    }
}
