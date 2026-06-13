<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A DB-backed "style theme" (ACP visual theme editor): a named accent colour + optional custom CSS, emitted
 * into the document head by StyleThemeManager when active. Cosmetic only — it feeds no permission resolution.
 * Written exclusively by StyleThemeManager (admin-gated); the narrow fillable set is defence-in-depth since
 * there is no request-driven mass-assignment path.
 */
class SiteTheme extends Model
{
    protected $fillable = ['name', 'slug', 'accent_color', 'custom_css', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
