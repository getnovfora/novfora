<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support;

/**
 * ACP v2 — the fixed, AA-safe palette for staff/group name colours. A group stores a palette KEY (e.g.
 * "indigo"), never a raw hex, so every colour is guaranteed readable in BOTH light and dark: each key maps
 * to a `--group-{key}` CSS custom property defined for both modes in resources/css/app.css (light value is
 * AA on the light --surface, dark value is AA on the dark --surface — the same discipline the accent tokens
 * use). The hex pairs here are the source of truth for the swatch picker + the app.css tokens; rendering
 * always goes through the CSS variable, never an inline hex, so a colour can never fail a mode.
 */
final class GroupColor
{
    /**
     * key => [label, light-hex (AA on light surface), dark-hex (AA on dark surface)].
     *
     * @var array<string, array{0:string,1:string,2:string}>
     */
    public const PALETTE = [
        // Light hex is AA (≥4.5:1) on the WORST light surface a name lands on (--surface-sunken #ebe3d3 in the
        // NovFora brand, used by row hover + the ACP panels); dark hex is AA on the dark surfaces. teal/blue/
        // violet were nudged darker when the brand surfaces warmed to cream (less headroom). Verified by
        // GroupColorTest.
        'slate' => ['Slate', '#475569', '#94a3b8'],
        'red' => ['Red', '#b91c1c', '#f87171'],
        'amber' => ['Amber', '#92400e', '#fbbf24'],
        'green' => ['Green', '#166534', '#4ade80'],
        'teal' => ['Teal', '#0e7069', '#2dd4bf'],
        'blue' => ['Blue', '#245fbb', '#60a5fa'],
        'indigo' => ['Indigo', '#4f46e5', '#818cf8'],
        'violet' => ['Violet', '#7a39e8', '#a78bfa'],
        'pink' => ['Pink', '#be185d', '#f472b6'],
    ];

    /** @return list<string> the valid colour keys (for validation `in:` + the picker). */
    public static function keys(): array
    {
        return array_keys(self::PALETTE);
    }

    public static function isValid(?string $key): bool
    {
        return $key !== null && $key !== '' && isset(self::PALETTE[$key]);
    }

    /** The human label for a key, or '' if none/invalid. */
    public static function label(?string $key): string
    {
        return self::isValid($key) ? self::PALETTE[$key][0] : '';
    }

    /** The CSS custom-property reference for a key (e.g. "var(--group-indigo)"), or null when unset/invalid. */
    public static function cssVar(?string $key): ?string
    {
        return self::isValid($key) ? "var(--group-{$key})" : null;
    }
}
