<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An admin-defined profile field (data-model §1). */
class CustomField extends Model
{
    protected $guarded = [];

    protected $casts = [
        'options' => 'array',
        'position' => 'integer',
        'is_active' => 'boolean',
    ];
}
