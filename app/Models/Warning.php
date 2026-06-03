<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An issued warning / infraction (security §3 / data-model §5). Points decay via `expires_at`; the live
 * (unexpired) sum drives automated consequences at thresholds. `acknowledged_at` gates the IPS-style
 * "acknowledge before posting is restored" flow.
 */
class Warning extends Model
{
    protected $guarded = [];

    protected $casts = [
        'points' => 'integer',
        'action_taken' => 'array',
        'expires_at' => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(WarningType::class, 'warning_type_id');
    }

    /** Live (unexpired) warnings only — the ones whose points still count. */
    public function scopeLive($query)
    {
        return $query->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
