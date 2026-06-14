<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An admin override of a sandbox-template contract key (ADR-0038). `source` is the restricted
 * sandbox-template language — NEVER PHP/Blade — and is validated by TemplateService before storage. Written
 * exclusively by TemplateService (admin-gated); the narrow fillable set is defence-in-depth.
 */
class SiteTemplate extends Model
{
    protected $fillable = ['template_key', 'source', 'is_enabled'];

    protected $casts = ['is_enabled' => 'boolean'];
}
