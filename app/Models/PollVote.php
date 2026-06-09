<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single user's vote for one poll option (P2-M1). UNIQUE(poll_option_id, user_id) is the structural floor;
 * PollService is the sole write path and enforces single-choice / max_choices by locking the poll row.
 */
class PollVote extends Model
{
    protected $guarded = [];

    protected $casts = [
        'poll_id' => 'integer',
        'poll_option_id' => 'integer',
        'user_id' => 'integer',
    ];

    /** @return BelongsTo<Poll, $this> */
    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class);
    }

    /** @return BelongsTo<PollOption, $this> */
    public function option(): BelongsTo
    {
        return $this->belongsTo(PollOption::class, 'poll_option_id');
    }
}
