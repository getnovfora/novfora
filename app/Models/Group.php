<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Group extends Model
{
    // Explicit allowlist (phase-1.5 F-G): group identity/priority drives permission resolution, so it must
    // not be mass-assignable from request data. Written only by GroupSeeder / the Acl test helper.
    protected $fillable = ['slug', 'name', 'type', 'priority', 'is_system', 'auto_promotion'];

    protected $casts = [
        'is_system' => 'boolean',
        'priority' => 'integer',
        'auto_promotion' => 'array',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('is_primary');
    }
}
