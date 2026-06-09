<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One option of a poll (P2-M1). `vote_count` is denormalised and recomputed authoritatively from `poll_votes`
 * by PollService on every vote — never blindly incremented, so it cannot drift.
 */
class PollOption extends Model
{
    protected $guarded = [];

    protected $casts = [
        'poll_id' => 'integer',
        'position' => 'integer',
        'vote_count' => 'integer',
    ];

    /** @return BelongsTo<Poll, $this> */
    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class);
    }
}
