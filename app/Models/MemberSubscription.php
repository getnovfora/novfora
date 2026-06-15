<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A member's grant of a membership tier (Phase 4 · M5.1). Only `active` rows grant perks (TierProjector
 * re-derives). Stores no card data — `provider_ref` is an opaque external id at most.
 *
 * @property int $id
 * @property int $user_id
 * @property int $tier_id
 * @property string $status
 * @property string $provider
 * @property ?string $provider_ref
 * @property ?Carbon $expires_at
 */
class MemberSubscription extends Model
{
    protected $fillable = [
        'user_id', 'tier_id', 'status', 'provider', 'provider_ref', 'started_at', 'expires_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<MembershipTier, $this> */
    public function tier(): BelongsTo
    {
        return $this->belongsTo(MembershipTier::class, 'tier_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @param Builder<MemberSubscription> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active');
    }
}
