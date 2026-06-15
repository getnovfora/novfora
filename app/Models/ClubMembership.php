<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row on the `club_user` edge (Phase 4 · M1.1). The role/status pair is the source of truth for club
 * membership; the membership-management services (M1.3) write it and M1.2 mirrors active roles into
 * club-scoped acl_entries.
 *
 * @property int $club_id
 * @property int $user_id
 * @property string $role owner|moderator|member
 * @property string $status active|pending|invited|banned
 */
class ClubMembership extends Model
{
    protected $table = 'club_user';

    protected $guarded = [];

    protected $casts = [
        'joined_at' => 'datetime',
    ];

    /** @return BelongsTo<Club, $this> */
    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * The ids of clubs the user is an ACTIVE member of — used by the listing-visibility scope and the
     * content-visibility query filters (M1.5). One query; callers may cache for the request.
     *
     * @return list<int>
     */
    public static function activeClubIdsFor(User $user): array
    {
        if (! $user->exists) {
            return [];
        }

        return static::query()
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->pluck('club_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
