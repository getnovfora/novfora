<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One username change (old → new) for a member — written only by UsernameService (U8, ADR-0106),
 * snapshotted before the users row is overwritten. Singular table per the audit_log precedent.
 */
class UsernameHistory extends Model
{
    protected $table = 'username_history';

    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
