<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Spike P2 — one pending notification staged for a future digest (data-model: digest_queue_items). A NULL
 * `digest_run_id` is unclaimed; the assembler flips a bounded batch to a run id inside one transaction.
 * Append-only ledger (created_at only); stores a payload snapshot, never rendered HTML.
 */
class DigestQueueItem extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<DigestRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(DigestRun::class, 'digest_run_id');
    }
}
