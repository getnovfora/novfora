<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A personal API token (ADR-0033). Stored as a sha256 hash of a one-time plaintext; resolves to its owning
 * user, on whose behalf every API request is then authorized through the existing permission engine. Written
 * only through App\Api\ApiTokenService.
 *
 * @property int $user_id
 * @property string $name
 * @property string $token_hash
 * @property array<int,string>|null $abilities
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 */
class ApiToken extends Model
{
    protected $guarded = [];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
