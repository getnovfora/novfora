<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ReputationEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One reputation award (P2-M5): $points to $user, sourced from a polymorphic $source (a Reaction; a Post/
 * Topic for the optional creation awards). UNIQUE(source_type, source_id) is the idempotency key — one
 * source awards at most once. Append-only (no updated_at). ALL writes go through ReputationService, which
 * keeps users.reputation_points (the denormalised sum) reconciled — never create/delete rows directly.
 */
class ReputationEvent extends Model
{
    /** @use HasFactory<ReputationEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'user_id' => 'integer',
        'source_id' => 'integer',
        'points' => 'integer',
    ];

    /** The recipient. @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphTo<Model, $this> */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
