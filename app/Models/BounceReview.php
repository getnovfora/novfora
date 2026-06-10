<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P2-M2 — a bounce/complaint pulled from a NON-VERP polled mailbox, queued for STAFF review (data-model:
 * bounce_reviews). The candidate_email is UNVERIFIED (taken from untrusted body headers), so it is NEVER
 * auto-suppressed — a staff member authenticates it by hand in the ACP, then suppresses or dismisses it.
 */
class BounceReview extends Model
{
    public const UPDATED_AT = null;

    public const PENDING = 'pending';

    public const RESOLVED = 'resolved';

    public const DISMISSED = 'dismissed';

    protected $guarded = [];

    protected $casts = [
        'permanent' => 'boolean',
        'created_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
