<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only record of a registration anti-spam evaluation (ADR-0007 / data-model §6). Carries PII
 * (IP/email) under a configurable retention window — purged by `novfora:antispam:purge` (security §2.6).
 */
class RegistrationCheck extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'provider_scores' => 'array',
        'degraded' => 'boolean',
        'created_at' => 'datetime',
    ];
}
