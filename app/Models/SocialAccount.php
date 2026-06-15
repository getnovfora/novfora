<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A linked OAuth identity (Phase 4 · M2). The (provider, provider_user_id) pair is unique, so one external
 * account never maps to two local users.
 *
 * @property int $user_id
 * @property string $provider
 * @property string $provider_user_id
 */
class SocialAccount extends Model
{
    protected $guarded = [];

    protected $casts = [
        'linked_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
