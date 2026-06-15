<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single-use, expiring club invitation (Phase 4 · M1.3). The `token` is the secret carried in the accept
 * link; an optional `email` binds it to one address.
 *
 * @property string $token
 * @property ?string $email
 * @property Carbon $expires_at
 * @property ?Carbon $accepted_at
 */
class ClubInvitation extends Model
{
    protected $guarded = [];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    /** Route-model-bind by the opaque token, never the sequential id. */
    public function getRouteKeyName(): string
    {
        return 'token';
    }

    /** @return BelongsTo<Club, $this> */
    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isPast();
    }
}
