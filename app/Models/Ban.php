<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use App\Permissions\AclVersion;
use Illuminate\Database\Eloquent\Model;

class Ban extends Model
{
    protected $guarded = [];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Bans are evaluated before the ACL (security §1.2 step 1), and can() caches its verdict — so
        // issuing/lifting a ban must invalidate resolved-permission caches, exactly like an ACL change.
        $bump = fn () => app(AclVersion::class)->bump();
        static::saved($bump);
        static::deleted($bump);
    }
}
