<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use App\Permissions\Scope;
use Database\Factories\ClubFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A club (sub-community, Phase 4 · M1.1). Owns one discussion forum (forum_id, wired in M1.4) and a roster
 * (club_user). The role/status on club_user is the SOURCE OF TRUTH for club rank; M1.2 projects it into
 * club-scoped acl_entries so the permission engine resolves club-scoped moderation/management.
 *
 * VISIBILITY (ADR-0047) — two orthogonal axes:
 *   • content (read topics/posts):  public clubs → everyone; closed/private → active members + global staff.
 *   • listing  (exists in directory/search): listed clubs → everyone; unlisted → active members + global staff.
 * NB the global `members forum.view = ALLOW` means the ACL engine alone cannot hide a private club from a
 * logged-in non-member (NEVER is absolute and would also bite real members) — so these query-level gates are
 * the AUTHORITATIVE content-hiding enforcement, consulted by every exposure surface (M1.5). The engine carries
 * club CAPABILITIES at club scope, plus a guests-group club-scope NEVER as anonymous defence-in-depth.
 */
class Club extends Model
{
    /** @use HasFactory<ClubFactory> */
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    public const PRIVACIES = ['public', 'closed', 'private'];

    public const ROLES = ['owner', 'moderator', 'member'];

    public const STATUSES = ['active', 'pending', 'invited', 'banned'];

    protected $casts = [
        'is_listed' => 'boolean',
        'member_count' => 'integer',
        'settings' => 'array',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    // ── Relations ────────────────────────────────────────────────────────────────────────────────────────

    /** @return HasMany<ClubMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(ClubMembership::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'club_user')
            ->withPivot(['role', 'status', 'joined_at'])
            ->withTimestamps();
    }

    /** The club's root discussion forum (M1.4). @return BelongsTo<Forum, $this> */
    public function forum(): BelongsTo
    {
        return $this->belongsTo(Forum::class, 'forum_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Scope helpers ────────────────────────────────────────────────────────────────────────────────────

    /** The permission-engine scope this club represents (M1.2 adds 'club' to Scope::parse + ScopeChain). */
    public function permissionScope(): Scope
    {
        return Scope::club((int) $this->id);
    }

    // ── Membership / role queries (source of truth = club_user) ──────────────────────────────────────────

    /** The viewer's membership row, or null. Memoised per (instance, user) for the request. */
    public function membershipOf(?User $user): ?ClubMembership
    {
        if (! $user || ! $user->exists) {
            return null;
        }

        return $this->memberships()
            ->where('user_id', $user->getKey())
            ->first();
    }

    /** The viewer's ACTIVE club role (owner|moderator|member), or null if not an active member. */
    public function roleOf(?User $user): ?string
    {
        $m = $this->membershipOf($user);

        return $m && $m->status === 'active' ? (string) $m->role : null;
    }

    public function isActiveMember(?User $user): bool
    {
        return $this->roleOf($user) !== null;
    }

    public function isOwner(?User $user): bool
    {
        return $this->roleOf($user) === 'owner';
    }

    /** Owner or moderator — the club-local moderation tier. */
    public function isClubModerator(?User $user): bool
    {
        return in_array($this->roleOf($user), ['owner', 'moderator'], true);
    }

    // ── Privacy semantics ────────────────────────────────────────────────────────────────────────────────

    public function contentIsPublic(): bool
    {
        return $this->privacy === 'public';
    }

    /** How a non-member joins: open (just join) | request (→approve) | invite (invite-only). */
    public function joinPolicy(): string
    {
        return match ($this->privacy) {
            'closed' => 'request',
            'private' => 'invite',
            default => 'open',
        };
    }

    /** May the viewer READ this club's discussion content? (public OR active member OR global staff) */
    public function isContentVisibleTo(?User $user): bool
    {
        return $this->contentIsPublic()
            || $this->isActiveMember($user)
            || ($user !== null && $user->exists && $user->isStaff());
    }

    /** May the viewer SEE THAT THIS CLUB EXISTS (directory/search/profile)? (listed OR member OR staff) */
    public function isListingVisibleTo(?User $user): bool
    {
        return (bool) $this->is_listed
            || $this->isActiveMember($user)
            || ($user !== null && $user->exists && $user->isStaff());
    }

    /**
     * May the viewer MANAGE the club (settings/roster/roles)? Resolved THROUGH the permission engine (M1.2):
     * club owners hold `club.manage` at club scope (projected from their roster role) and global administrators
     * hold it at global scope (the administrator preset, inherited into every club). A global *moderator* is
     * deliberately NOT a club manager — they moderate content (isModeratableBy), they do not own clubs.
     */
    public function isManageableBy(?User $user): bool
    {
        return $user !== null && $user->exists
            && $user->canDo('club.manage', $this->permissionScope());
    }

    /** May the viewer MODERATE club content (lock/pin/delete)? (owner|moderator OR global staff) */
    public function isModeratableBy(?User $user): bool
    {
        return $user !== null && $user->exists
            && ($this->isClubModerator($user) || $user->isStaff());
    }

    // ── Query scopes ─────────────────────────────────────────────────────────────────────────────────────

    /**
     * Restrict a club query to those whose EXISTENCE the viewer may see (directory/search). A listed club is
     * visible to everyone; an unlisted one only to its active members; global staff see all. This is the
     * single source of truth for club-existence visibility — surfaces must funnel through it (M1.5).
     *
     * @param  Builder<Club>  $query
     * @return Builder<Club>
     */
    public function scopeListableTo(Builder $query, ?User $user): Builder
    {
        if ($user !== null && $user->exists && $user->isStaff()) {
            return $query; // staff see every club
        }

        return $query->where(function (Builder $q) use ($user): void {
            $q->where('is_listed', true);
            if ($user !== null && $user->exists) {
                $q->orWhereIn('id', ClubMembership::activeClubIdsFor($user));
            }
        });
    }
}
