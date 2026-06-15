<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member's saved search (search 6.1): a label + the keyword term + the full GET query string to replay it.
 * Private to the owner; written only through SavedSearchService.
 */
class SavedSearch extends Model
{
    protected $fillable = ['user_id', 'name', 'term', 'query_string'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
