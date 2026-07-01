<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use App\Navigation\NavigationManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

/**
 * One admin-managed public navigation link. Written through App\Navigation\NavigationManager; rendered in the
 * app layout as a shallow tree so themes can style it without owning the data model.
 *
 * @property int|null $parent_id
 * @property string $title
 * @property string $link_type
 * @property string|null $route_name
 * @property string|null $url
 * @property string|null $icon
 * @property int $position
 * @property bool $is_enabled
 * @property bool $show_on_desktop
 * @property bool $show_on_mobile
 * @property bool $opens_new_tab
 * @property string $visibility
 * @property array<int,int>|null $group_ids
 */
class NavigationItem extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        // Bust the layout's cached nav rows on EVERY write path, including direct model writes that
        // bypass NavigationManager (seeders, tests) — staleness here is a public-facing wrong menu.
        $bust = fn () => Cache::forget(NavigationManager::CACHE_KEY);
        static::saved($bust);
        static::deleted($bust);
    }

    /** @return array<string,string> */
    protected function casts(): array
    {
        return [
            'group_ids' => 'array',
            'is_enabled' => 'bool',
            'show_on_desktop' => 'bool',
            'show_on_mobile' => 'bool',
            'opens_new_tab' => 'bool',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<NavigationItem, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<NavigationItem, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position')->orderBy('id');
    }
}
