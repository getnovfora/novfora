<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A DB-backed "style theme" (ACP visual theme editor): a named accent colour + optional custom CSS, emitted
 * into the document head by StyleThemeManager when active. Cosmetic only — it feeds no permission resolution.
 * Written exclusively by StyleThemeManager (admin-gated); the narrow fillable set is defence-in-depth since
 * there is no request-driven mass-assignment path.
 */
class SiteTheme extends Model
{
    protected $fillable = [
        'name', 'slug', 'parent_id', 'accent_color', 'custom_css', 'tokens', 'tokens_dark', 'header_html', 'footer_html',
        'logo_path', 'favicon_path', 'background_path', 'is_active', 'is_user_selectable',
    ];

    protected $casts = [
        'tokens' => 'array',
        'tokens_dark' => 'array',
        'is_active' => 'boolean',
        'is_user_selectable' => 'boolean',
    ];

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
