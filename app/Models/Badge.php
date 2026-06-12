<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BadgeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * An ACP-managed badge definition (P2-M5). criteria is a CLOSED-SET JSON document — see
 * BadgeService::CRITERIA_TYPES; validated on save, matched (never evaluated) at award time.
 * Awards live in user_badges (UNIQUE(user_id,badge_id)) and are permanent.
 */
class Badge extends Model
{
    /** @use HasFactory<BadgeFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'criteria' => 'array',
        'is_active' => 'boolean',
    ];

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_badges')->withPivot('awarded_at');
    }
}
