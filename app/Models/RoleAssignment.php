<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoleAssignment extends Model
{
    // Explicit allowlist (phase-1.5 F-G): who holds which role where — never request-driven.
    protected $fillable = ['role_id', 'holder_type', 'holder_id', 'scope_type', 'scope_id'];
}
