<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Deliverability suppression entry (ADR-0014 / data-model §7). created_at only (append-only). */
class EmailSuppression extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
