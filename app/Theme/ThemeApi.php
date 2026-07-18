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
    public const VERSION = '1.3.0';

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
            // v1.3 (U9) — the rest of the brand token drop-in joins the editable contract.
            '--ink-subtle', '--line-strong', '--ember', '--ember-ink',
            '--success', '--success-soft', '--success-ink',
            '--warn', '--warn-soft', '--warn-ink',
            '--danger', '--danger-soft', '--danger-ink', '--danger-strong',
        ];
    }

    /**
     * The ordered editor groups for the style-property registry (U9). Presentation order only — membership
     * lives on each token's `group` key.
     *
     * @return list<string>
     */
    public static function tokenGroups(): array
    {
        return ['Surfaces', 'Ink', 'Lines', 'Brand', 'Status', 'Shape'];
    }

    /**
     * The design tokens the Theme Studio visual editor exposes — the U9 typed/grouped style-property registry
     * (grown from Wave 1.1's seven tokens; adding a token = MINOR per this API's own rule). Each maps to a
     * REAL core CSS custom property (the one Tailwind utilities actually read — the `--novfora-*` aliases are
     * one-way and do not cascade). A theme supplies an optional LIGHT value per token and, since v1.3, an
     * optional DARK value (`tokens_dark`); a mode left blank keeps that mode's tuned built-in. The accent
     * family stays separate (AccentPalette derives both colour modes AA-safe from the single accent colour).
     *
     * `type` drives both validation (StyleThemeManager::cleanTokens) and the editor control; `default` /
     * `dark_default` are the built-in values (used for the AA preview when a token is left blank —
     * `dark_default` null means the token has no meaningful dark variant, e.g. lengths).
     *
     * @return array<string, array{var:string, label:string, group:string, type:string, default:string, dark_default:?string}>
     */
    public static function editableTokens(): array
    {
        return [
            // Surfaces
            'surface' => ['var' => '--surface', 'label' => 'Page background', 'group' => 'Surfaces', 'type' => 'color', 'default' => '#f4eee2', 'dark_default' => '#0b0b10'],
            'surface_raised' => ['var' => '--surface-raised', 'label' => 'Raised / cards', 'group' => 'Surfaces', 'type' => 'color', 'default' => '#fcfaf4', 'dark_default' => '#14151d'],
            'surface_sunken' => ['var' => '--surface-sunken', 'label' => 'Sunken / insets', 'group' => 'Surfaces', 'type' => 'color', 'default' => '#ebe3d3', 'dark_default' => '#08080c'],
            // Ink
            'ink' => ['var' => '--ink', 'label' => 'Text', 'group' => 'Ink', 'type' => 'color', 'default' => '#221c13', 'dark_default' => '#f3e8dd'],
            'ink_muted' => ['var' => '--ink-muted', 'label' => 'Muted text', 'group' => 'Ink', 'type' => 'color', 'default' => '#5c5346', 'dark_default' => '#cfc9be'],
            'ink_subtle' => ['var' => '--ink-subtle', 'label' => 'Subtle text', 'group' => 'Ink', 'type' => 'color', 'default' => '#6f6354', 'dark_default' => '#938c7e'],
            // Lines
            'line' => ['var' => '--line', 'label' => 'Borders', 'group' => 'Lines', 'type' => 'color', 'default' => '#e6dccb', 'dark_default' => '#242230'],
            'line_strong' => ['var' => '--line-strong', 'label' => 'Strong borders', 'group' => 'Lines', 'type' => 'color', 'default' => '#d3c6af', 'dark_default' => '#36333f'],
            // Brand
            'ember' => ['var' => '--ember', 'label' => 'Ember (signature)', 'group' => 'Brand', 'type' => 'color', 'default' => '#b5731f', 'dark_default' => '#eba94b'],
            'ember_ink' => ['var' => '--ember-ink', 'label' => 'Text on ember', 'group' => 'Brand', 'type' => 'color', 'default' => '#ffffff', 'dark_default' => '#1a1206'],
            // Status
            'success' => ['var' => '--success', 'label' => 'Success', 'group' => 'Status', 'type' => 'color', 'default' => '#2b774d', 'dark_default' => '#35b07a'],
            'success_soft' => ['var' => '--success-soft', 'label' => 'Success (soft)', 'group' => 'Status', 'type' => 'color', 'default' => '#dcefe2', 'dark_default' => '#11271c'],
            'success_ink' => ['var' => '--success-ink', 'label' => 'Success text', 'group' => 'Status', 'type' => 'color', 'default' => '#1f6b43', 'dark_default' => '#7fd3a8'],
            'warn' => ['var' => '--warn', 'label' => 'Warning', 'group' => 'Status', 'type' => 'color', 'default' => '#8f6207', 'dark_default' => '#e0ae3f'],
            'warn_soft' => ['var' => '--warn-soft', 'label' => 'Warning (soft)', 'group' => 'Status', 'type' => 'color', 'default' => '#faefc9', 'dark_default' => '#2a2206'],
            'warn_ink' => ['var' => '--warn-ink', 'label' => 'Warning text', 'group' => 'Status', 'type' => 'color', 'default' => '#785205', 'dark_default' => '#f0c766'],
            'danger' => ['var' => '--danger', 'label' => 'Danger', 'group' => 'Status', 'type' => 'color', 'default' => '#be3a2b', 'dark_default' => '#e5705b'],
            'danger_soft' => ['var' => '--danger-soft', 'label' => 'Danger (soft)', 'group' => 'Status', 'type' => 'color', 'default' => '#fae3de', 'dark_default' => '#2e1411'],
            'danger_ink' => ['var' => '--danger-ink', 'label' => 'Danger text', 'group' => 'Status', 'type' => 'color', 'default' => '#a12b1f', 'dark_default' => '#f2a293'],
            'danger_strong' => ['var' => '--danger-strong', 'label' => 'Danger (strong)', 'group' => 'Status', 'type' => 'color', 'default' => '#d2402e', 'dark_default' => '#cf3c28'],
            // Shape
            'radius' => ['var' => '--radius-md', 'label' => 'Corner radius', 'group' => 'Shape', 'type' => 'length', 'default' => '10px', 'dark_default' => null],
        ];
    }
}
