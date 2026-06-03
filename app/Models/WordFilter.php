<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A configurable word filter (security §3 / data-model §5): replace | flag | block. */
class WordFilter extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_regex' => 'boolean',
        'whole_word' => 'boolean',
        'is_active' => 'boolean',
    ];
}
