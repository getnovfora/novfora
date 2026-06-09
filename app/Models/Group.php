<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Group extends Model
{
    // Explicit allowlist (phase-1.5 F-G): group identity/priority drives permission resolution, so it must
    // not be mass-assignable from request data. Written only by GroupSeeder / GroupManager / the Acl test
    // helper. `color`/`description` (ACP v2) are cosmetic — they don't feed resolution — so they're safe to
    // include; the structural slug/type/priority/is_system stay server-written only.
    protected $fillable = ['slug', 'name', 'color', 'description', 'type', 'priority', 'is_system', 'auto_promotion'];

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
