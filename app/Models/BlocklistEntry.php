<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A cron-cached crowdsourced/disposable blocklist row (ADR-0007 / data-model §6). */
class BlocklistEntry extends Model
{
    protected $table = 'blocklist_cache';

    protected $guarded = [];

    protected $casts = [
        'confidence' => 'integer',
        'expires_at' => 'datetime',
    ];

    /** Live entries only: unexpired (or never-expiring). */
    public function scopeLive($query)
    {
        return $query->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
