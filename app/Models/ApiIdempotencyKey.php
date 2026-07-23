<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A stored Admin-API response keyed by (token, idempotency-key) so a replayed mutating request returns the
 * original response instead of re-running (E1 / NOV-135, ADR-0115). Append-only (no updated_at); pruned by TTL.
 * The `idempotency_key` column stores a sha256 of the client's header value, so any-length client key maps to a
 * fixed 64-char storage key with no truncation collision.
 *
 * @property int $api_token_id
 * @property string $idempotency_key
 * @property string $method
 * @property string $path
 * @property int $response_status
 * @property string $response_body
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class ApiIdempotencyKey extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
