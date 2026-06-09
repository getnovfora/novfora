<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Denormalised per-type reaction tally for a post (P2-M1). The read-side source for the thread hot path —
 * recomputed authoritatively from `reactions` by ReactionService on every write, never incremented blindly,
 * so it cannot drift. A (post,type) row only exists while its count is ≥ 1.
 */
class PostReactionCount extends Model
{
    protected $guarded = [];

    protected $casts = [
        'post_id' => 'integer',
        'count' => 'integer',
    ];

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
