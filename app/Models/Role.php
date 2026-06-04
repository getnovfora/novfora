<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    // Explicit allowlist (phase-1.5 F-G): a role bundles grants, so guard it from request-driven writes.
    protected $fillable = ['slug', 'name', 'is_preset', 'description'];

    protected $casts = [
        'is_preset' => 'boolean',
    ];

    public function permissions(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }
}
