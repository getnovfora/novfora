<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single autosaved editor draft (P2-M1) — at most one per (user, context). Written only via the
 * ManagesDrafts trait, which always scopes by the authenticated user, so a draft is intrinsically own-only.
 */
class PostDraft extends Model
{
    protected $guarded = [];

    protected $casts = [
        'user_id' => 'integer',
        'context_id' => 'integer',
        'body_canonical' => 'array', // lossless TipTap doc
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
