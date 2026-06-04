<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    // Explicit allowlist (phase-1.5 F-G): the permission catalog (reference list) — never request-driven.
    protected $fillable = ['key', 'label', 'scope_kind', 'group', 'description'];
}
