<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An admin-authored template-hook fragment (U11 / ADR-0112): a sandbox-language source attached to a named
 * anchor from TemplateContract::hooks(), rendered by <x-template-hook>. Written exclusively by
 * TemplateService (admin-gated, linted first); cosmetic only — it feeds no permission resolution.
 */
class SiteTemplateHook extends Model
{
    protected $fillable = ['hook_key', 'name', 'source', 'position', 'is_enabled'];

    protected $casts = [
        'position' => 'integer',
        'is_enabled' => 'boolean',
    ];
}
