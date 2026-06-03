<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit trail (security §3). created_at only — no updated_at (UPDATED_AT = null).
 */
class AuditLog extends Model
{
    protected $table = 'audit_log';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'changes' => 'array',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
