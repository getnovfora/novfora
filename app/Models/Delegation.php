<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use App\Admin\DelegationService;
use App\Permissions\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ACP v3 · v3-f — a temporary-access delegation (ADR-0087): the provenance record for one time-boxed capability
 * grant. Written + projected into a TTL `acl_entries` row by {@see DelegationService}; never read by
 * the resolver (G1). A row is LIVE when it is not revoked and not yet expired; `live()` scopes the UI list and
 * the cascade re-check to exactly those.
 */
class Delegation extends Model
{
    public const UPDATED_AT = null; // created_at only — a delegation is immutable except for its revoked_at marker

    // Written only by trusted server code (DelegationService); a delegation IS a grant, so keep the surface tight.
    protected $fillable = ['delegator_id', 'recipient_id', 'permission_key', 'scope_type', 'scope_id', 'expires_at', 'revoked_at'];

    protected $casts = [
        'scope_id' => 'integer',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function delegator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegator_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    /** The delegated scope, reconstructed from its columns (global when scope_id is null). */
    public function scope(): Scope
    {
        return $this->scope_type === 'global'
            ? Scope::global()
            : new Scope($this->scope_type, $this->scope_id);
    }

    /** Live = standing right now: not revoked and not yet expired. The only rows that mirror an acl_entries row. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }
}
