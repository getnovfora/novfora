<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Theme;

use App\Models\SiteTheme;

/**
 * The shipped style presets (U10 · NOV-108): a "NovFora" base style plus two user-selectable descendants —
 * Daylight (a cleaner-paper light palette) and Midnight (an OLED-black dark layer) — demonstrating the
 * parent/child style tree with brand-guideline, AA-safe values. Installed idempotently BY SLUG from the
 * DatabaseSeeder (fresh installs) and the ACP Themes page (existing installs); nothing is auto-activated —
 * the operator opts in. Blueprint values are code-controlled constants (pre-validated hex), created directly.
 */
final class StylePresets
{
    /**
     * The preset blueprints, parent-first so install() can link children in one pass.
     *
     * @return list<array{slug:string,name:string,parent:?string,is_user_selectable:bool,tokens:?array<string,string>,tokens_dark:?array<string,string>}>
     */
    public static function definitions(): array
    {
        return [
            [
                'slug' => 'novfora',
                'name' => 'NovFora',
                'parent' => null,
                'is_user_selectable' => false, // the base look — children refine it
                'tokens' => null,              // no overrides: the built-in brand palette
                'tokens_dark' => null,
            ],
            [
                'slug' => 'novfora-daylight',
                'name' => 'NovFora Daylight',
                'parent' => 'novfora',
                'is_user_selectable' => true,
                // A cleaner, whiter paper — ink #221c13 on these surfaces is ≥ the built-in's AA margin.
                'tokens' => [
                    'surface' => '#faf7f0',
                    'surface_raised' => '#ffffff',
                    'surface_sunken' => '#f0ebdf',
                    'line' => '#e8e0d0',
                ],
                'tokens_dark' => null,
            ],
            [
                'slug' => 'novfora-midnight',
                'name' => 'NovFora Midnight',
                'parent' => 'novfora',
                'is_user_selectable' => true,
                'tokens' => null,
                // OLED true black — ink #f3e8dd on #000000 exceeds the built-in dark's AA margin.
                'tokens_dark' => [
                    'surface' => '#000000',
                    'surface_raised' => '#0c0c12',
                    'surface_sunken' => '#000000',
                    'line' => '#1c1a26',
                ],
            ],
        ];
    }

    /**
     * Create any preset not already present (matched by slug), linking children to their parent. Existing
     * rows are never modified — an operator's edits to an installed preset are theirs. Returns the slugs
     * created this run.
     *
     * @return list<string>
     */
    public static function install(): array
    {
        $created = [];

        foreach (self::definitions() as $def) {
            if (SiteTheme::query()->where('slug', $def['slug'])->exists()) {
                continue;
            }

            $parentId = null;
            if ($def['parent'] !== null) {
                $parentId = SiteTheme::query()->where('slug', $def['parent'])->value('id');
            }

            SiteTheme::query()->create([
                'name' => $def['name'],
                'slug' => $def['slug'],
                'parent_id' => $parentId,
                'is_user_selectable' => $def['is_user_selectable'],
                'tokens' => $def['tokens'],
                'tokens_dark' => $def['tokens_dark'],
                'is_active' => false,
            ]);

            $created[] = $def['slug'];
        }

        return $created;
    }
}
