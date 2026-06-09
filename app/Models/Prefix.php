<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Prefix extends Model
{
    protected $guarded = [];

    protected $casts = [
        'forum_id' => 'integer',
        'position' => 'integer',
    ];

    /** @return BelongsTo<Forum, $this> */
    public function forum(): BelongsTo
    {
        return $this->belongsTo(Forum::class);
    }

    /** @return HasMany<Topic, $this> */
    public function topics(): HasMany
    {
        return $this->hasMany(Topic::class);
    }
}
