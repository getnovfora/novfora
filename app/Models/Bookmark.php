<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A personal bookmark — a private edge from a user to a saved Topic or Post (member tool 2.1). Written only
 * through BookmarkService; the narrow fillable set is defence-in-depth. Cascades away with the user.
 */
class Bookmark extends Model
{
    protected $fillable = ['user_id', 'bookmarkable_type', 'bookmarkable_id'];

    /** @return MorphTo<Model, $this> */
    public function bookmarkable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
