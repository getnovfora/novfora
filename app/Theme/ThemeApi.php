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
    public const VERSION = '1.2.0';

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
            // v1.1 — the REAL core tokens Tailwind utilities read; Theme Studio 1.1 overrides these directly.
            '--surface', '--surface-raised', '--surface-sunken', '--ink', '--ink-muted', '--line', '--radius-md',
        ];
    }

    /**
     * The design tokens Theme Studio's visual editor exposes (Wave 1.1). Each maps to a REAL core CSS custom
     * property (the one Tailwind utilities actually read — the `--novfora-*` aliases are one-way and do not
     * cascade). Overriding a token customises the LIGHT palette; the tuned dark palette is preserved (a theme
     * supplies one value per token). The accent stays separate (AccentPalette derives both colour modes).
     *
     * `type` drives both validation (StyleThemeManager) and the editor control; `default` is the built-in
     * light value (used for the AA preview when a token is left blank). This map is part of the v1.1 contract.
     *
     * @return array<string, array{var:string, label:string, group:string, type:string, default:string}>
     */
    public static function editableTokens(): array
    {
        return [
            'surface' => ['var' => '--surface', 'label' => 'Page background', 'group' => 'Surfaces', 'type' => 'color', 'default' => '#f6f8fc'],
            'surface_raised' => ['var' => '--surface-raised', 'label' => 'Raised / cards', 'group' => 'Surfaces', 'type' => 'color', 'default' => '#ffffff'],
            'surface_sunken' => ['var' => '--surface-sunken', 'label' => 'Sunken / insets', 'group' => 'Surfaces', 'type' => 'color', 'default' => '#eef1f7'],
            'ink' => ['var' => '--ink', 'label' => 'Text', 'group' => 'Ink', 'type' => 'color', 'default' => '#141a2b'],
            'ink_muted' => ['var' => '--ink-muted', 'label' => 'Muted text', 'group' => 'Ink', 'type' => 'color', 'default' => '#555d72'],
            'line' => ['var' => '--line', 'label' => 'Borders', 'group' => 'Lines', 'type' => 'color', 'default' => '#e3e7f0'],
            'radius' => ['var' => '--radius-md', 'label' => 'Corner radius', 'group' => 'Shape', 'type' => 'length', 'default' => '10px'],
        ];
    }
}
