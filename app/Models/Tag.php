<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Tag extends Model
{
    protected $guarded = [];

    protected $casts = [
        'usage_count' => 'integer',
    ];

    /** @return MorphToMany<Topic, $this> */
    public function topics(): MorphToMany
    {
        return $this->morphedByMany(Topic::class, 'taggable');
    }
}
