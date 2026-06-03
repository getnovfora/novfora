<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use App\Permissions\AclVersion;
use Illuminate\Database\Eloquent\Model;

class AclEntry extends Model
{
    protected $guarded = [];

    protected $casts = [
        'value' => 'integer',
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
