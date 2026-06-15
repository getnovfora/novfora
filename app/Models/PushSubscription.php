<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A browser/device Web Push subscription (Phase 4 · M3.2). The existence of a row is the user's opt-in for
 * that device. `endpoint_hash` (sha-256 of the endpoint) is the unique key, since the full endpoint can exceed
 * an index length.
 *
 * @property string $endpoint
 * @property string $public_key
 * @property string $auth_token
 */
class PushSubscription extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
