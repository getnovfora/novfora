<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Theme;

/**
 * The THEME API — the semver'd public contract that filesystem child themes (ThemeManager), DB style themes
 * (StyleThemeManager), and layout widgets all build on (ADR-0009 / ADR-0032). Two stable surfaces:
 *
 *  1. The **token contract** — the CSS custom properties a theme or widget may rely on and override. These are
 *     produced AA-safe by App\Support\AccentPalette (light + dark) and aliased in app.css; a theme restyles by
 *     overriding them, never by editing core markup.
 *  2. The **layout regions** — named outlets (`<x-region>`) an admin fills with widgets.
 *
 * Versioning mirrors the module API: adding a token or region = MINOR; renaming/removing one = MAJOR.
 */
final class ThemeApi
{
    public const VERSION = '1.0.0';

    /**
     * The stable CSS-variable token contract. A theme/widget may read or override any of these and rely on it
     * existing within this API major. They resolve AA-safe in both colour modes (AccentPalette).
     *
     * @return list<string>
     */
    public static function tokens(): array
    {
        return [
            // Semantic aliases (app.css) — the recommended override points.
            '--novfora-bg', '--novfora-fg', '--novfora-muted',
            '--novfora-accent', '--novfora-accent-fg', '--novfora-border', '--novfora-radius',
            // The AA-derived accent palette (AccentPalette) the aliases point at.
            '--accent', '--accent-ink', '--accent-hover', '--accent-soft', '--accent-soft-ink', '--focus',
        ];
    }
}
