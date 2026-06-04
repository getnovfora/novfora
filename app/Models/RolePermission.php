<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use App\Permissions\AclVersion;
use Illuminate\Database\Eloquent\Model;

class RolePermission extends Model
{
    // Explicit allowlist (phase-1.5 F-G): this defines what a role grants — never request-driven.
    protected $fillable = ['role_id', 'permission_key', 'value'];

    protected $casts = [
        'value' => 'integer',
    ];

    protected static function booted(): void
    {
        // A role redefinition changes any holder it's expanded onto — bump the ACL version (§1.3/§1.5).
        $bump = fn () => app(AclVersion::class)->bump();
        static::saved($bump);
        static::deleted($bump);
    }
}
