<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use App\Permissions\AclVersion;
use Illuminate\Database\Eloquent\Model;

class AclEntry extends Model
{
    // Explicit allowlist (phase-1.5 F-G): an ACL entry IS a grant, so a fully-unguarded model would let any
    // future `AclEntry::create($request->...)` mint a privilege (e.g. value=ALLOW on admin.access for your
    // own holder_id). Only the resolver's columns are mass-assignable; everything is written by trusted
    // server code (RoleExpander, seeders, the Acl test helper).
    // `expires_at` (ACP v3 v3-0) is safe to mass-assign: a TTL can only NARROW a grant (it lapses), never mint
    // or widen one — so it carries none of the escalation risk the allowlist above guards against.
    protected $fillable = ['permission_key', 'holder_type', 'holder_id', 'scope_type', 'scope_id', 'value', 'expires_at'];

    protected $casts = [
        'value' => 'integer',
        'expires_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Event-driven invalidation (security §1.3): any ACL change bumps the global version counter,
        // invalidating every resolved-permission cache at once.
        $bump = fn () => app(AclVersion::class)->bump();
        static::saved($bump);
        static::deleted($bump);
    }
}
