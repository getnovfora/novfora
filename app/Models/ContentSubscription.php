<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A member's follow of a Topic (notify on new replies) or a Forum (notify on new topics) — M2, ADR-0097.
 * CONTENT following; distinct from {@see MemberSubscription} (paid membership tiers).
 */
class ContentSubscription extends Model
{
    protected $guarded = [];

    protected $casts = [
        'user_id' => 'integer',
        'subscribable_id' => 'integer',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphTo<Model, $this> */
    public function subscribable(): MorphTo
    {
        return $this->morphTo();
    }
}
