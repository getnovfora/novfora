<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Spike P2 — the digest idempotency anchor + send receipt (data-model: digest_runs). One row per
 * (user_id, cadence, period_key), enforced by a DB UNIQUE index: that committed row is what guarantees
 * exactly-once digest assembly across coarse / overlapping / killed cron ticks. `status` (claimed → built
 * → sent) is the send-job dedup guard; `mailed_at` is the two-phase self-heal flag.
 */
class DigestRun extends Model
{
    protected $guarded = [];

    protected $casts = [
        'item_count' => 'integer',
        'claimed_at' => 'datetime',
        'built_at' => 'datetime',
        'mailed_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    /** @return HasMany<DigestQueueItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(DigestQueueItem::class);
    }
}
