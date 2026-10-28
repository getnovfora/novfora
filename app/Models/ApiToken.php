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
 * @property array<int,string>|null $scopes
 * @property array<int,string>|null $ip_allowlist
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
            'scopes' => 'array',
            'ip_allowlist' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Does this token carry the given admin scope (E1 / NOV-135)? */
    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    /** An admin-scoped token (carries at least one admin scope) vs a plain member token (no scopes). */
    public function isAdminScoped(): bool
    {
        return ($this->scopes ?? []) !== [];
    }
}
