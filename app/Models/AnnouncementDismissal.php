<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A per-user announcement dismissal (U4, NOV-102). Append-only from the app's view — created once when a user
 * dismisses an announcement banner, removed only by the cascading FKs. No updated_at (the row never changes).
 */
final class AnnouncementDismissal extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'dismissed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }
}
