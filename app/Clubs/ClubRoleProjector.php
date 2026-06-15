<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Clubs;

use App\Models\AclEntry;
use App\Models\Club;
use App\Models\ClubMembership;
use App\Models\Group;
use App\Permissions\AclVersion;
use App\Permissions\PermissionValue;

/**
 * Projects a club membership's ACTIVE role into per-user, club-scoped acl_entries (Phase 4 · M1.2) — so the
 * SAME PermissionResolver that gates the whole board also resolves a club moderator's scoped powers. The
 * roster (club_user.role/status) stays the source of truth; this is its mirror into the engine.
 *
 * DESIGN. Only the elevated roles get club-scope grants — a plain MEMBER relies on the global `member` preset
 * for posting (topic.create/post.create inherited from global into the club's forum chain), and their
 * content access is the M1.5 visibility gate, not an ACL grant. Grants are written at scope_type='club',
 * scope_id=club_id, holder_type='user'; a club's discussion forums inject that club node into their scope
 * chain (M1.4), so these capabilities reach every topic in the club but NOWHERE else (scope isolation).
 *
 * ActorRank still guards actor-vs-target rank, so a club owner can never out-rank global staff who happen to
 * be in the club (M1.3).
 */
class ClubRoleProjector
{
    /**
     * Club role → the capability keys granted ALLOW at club scope. Members get none (global preset suffices).
     *
     * @var array<string, list<string>>
     */
    private const GRANTS = [
        'owner' => ['club.manage', 'topic.moderate', 'post.edit.any', 'post.delete.any', 'post.history.view'],
        'moderator' => ['topic.moderate', 'post.edit.any', 'post.delete.any', 'post.history.view'],
        'member' => [],
    ];

    /**
     * Re-derive a user's club-scope grants from a membership row. Idempotent: clears the user's existing
     * club-scope entries for this club, then writes the active role's grants. A non-active status (pending /
     * invited / banned) leaves the user with NO club-scope grants. Bumps AclVersion so cached verdicts drop.
     */
    public function project(ClubMembership $membership): void
    {
        $clubId = (int) $membership->club_id;
        $userId = (int) $membership->user_id;

        $this->clearWithoutBump($clubId, $userId);

        if ($membership->status === 'active') {
            foreach (self::GRANTS[$membership->role] ?? [] as $key) {
                AclEntry::create([
                    'permission_key' => $key,
                    'holder_type' => 'user',
                    'holder_id' => $userId,
                    'scope_type' => 'club',
                    'scope_id' => $clubId,
                    'value' => PermissionValue::Allow->value,
                ]);
            }
        }

        app(AclVersion::class)->bump();
    }

    /** Remove every club-scope grant for a user in a club (used on leave / removal). Bumps AclVersion. */
    public function clear(int $clubId, int $userId): void
    {
        $this->clearWithoutBump($clubId, $userId);
        app(AclVersion::class)->bump();
    }

    /**
     * Anonymous-leak defence-in-depth (Phase 4 · M1.5): a closed/private club seeds `forum.view = NEVER` for
     * the GUESTS group at club scope. No real member is ever a guest, so this hard-denies every anonymous
     * surface (sitemap, RSS, guest search, the forum-facet dropdown) through the `forum.view` checks they
     * already perform — the club forum's chain includes the club node (M1.4). A public club carries no such
     * entry. Idempotent; call on create and on any privacy change.
     */
    public function projectPrivacy(Club $club): void
    {
        $guests = Group::query()->where('slug', 'guests')->first();
        if (! $guests instanceof Group) {
            return;
        }

        AclEntry::query()
            ->where('permission_key', 'forum.view')
            ->where('holder_type', 'group')->where('holder_id', (int) $guests->id)
            ->where('scope_type', 'club')->where('scope_id', (int) $club->id)
            ->delete();

        if ($club->privacy !== 'public') {
            AclEntry::create([
                'permission_key' => 'forum.view',
                'holder_type' => 'group',
                'holder_id' => (int) $guests->id,
                'scope_type' => 'club',
                'scope_id' => (int) $club->id,
                'value' => PermissionValue::Never->value,
            ]);
        }

        app(AclVersion::class)->bump();
    }

    private function clearWithoutBump(int $clubId, int $userId): void
    {
        AclEntry::query()
            ->where('holder_type', 'user')
            ->where('holder_id', $userId)
            ->where('scope_type', 'club')
            ->where('scope_id', $clubId)
            ->delete();
    }
}
