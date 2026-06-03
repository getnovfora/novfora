<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A per-user, per-topic read watermark (data-model §9). No timestamps beyond last_read_at. */
class TopicRead extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'last_read_at' => 'datetime',
    ];
}
