<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A pre-defined warning "action bundle" (IPS concept; security §3 / data-model §5). */
class WarningType extends Model
{
    protected $guarded = [];

    protected $casts = [
        'default_points' => 'integer',
        'decay_days' => 'integer',
        'default_action' => 'array',
        'is_active' => 'boolean',
    ];
}
