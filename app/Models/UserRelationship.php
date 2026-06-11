<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserRelationshipFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A directed relationship edge between two users (P2-M2 Half-B builds the table; only the IGNORE half is wired
 * this milestone). `user_id` is the actor, `related_user_id` the target. IGNORE means the actor will not
 * receive PMs from — and cannot be added to a conversation started by — the target; the FOLLOW type is the
 * M3 seam (table only, deliberately unwired here).
 */
class UserRelationship extends Model
{
    /** @use HasFactory<UserRelationshipFactory> */
    use HasFactory;

    public const TYPE_FOLLOW = 'follow';

    public const TYPE_IGNORE = 'ignore';

    protected $guarded = [];

    protected $casts = [
        'user_id' => 'integer',
        'related_user_id' => 'integer',
    ];

    /** The actor who owns the edge. @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The target of the edge. @return BelongsTo<User, $this> */
    public function related(): BelongsTo
    {
        return $this->belongsTo(User::class, 'related_user_id');
    }
}
