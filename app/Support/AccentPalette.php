<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support;

/**
 * Derive an AA-conscious accent palette (light + dark) from a single brand hex, for the site-level
 * Appearance setting (ACP v1, PART 3.6). The forum accent is a CSS custom property; when an operator
 * picks a colour the layout emits an override for BOTH colour modes that keeps text-on-accent readable —
 * the ink is chosen as white or near-black by whichever has the higher WCAG contrast, the hover shifts a
 * shade, and the soft chip tints toward each mode's surface. Returns null for an empty/invalid value, so
 * the built-in Nova Blue accent simply stays.
 */
final class AccentPalette
{
    /**
     * @return array{light:array<string,string>,dark:array<string,string>}|null
     */
    public static function for(?string $hex): ?array
    {
        $rgb = self::parse($hex);
        if ($rgb === null) {
            return null;
        }

        $light = [
            'accent' => self::hex($rgb),
            'accent-ink' => self::contrastInk($rgb),
            'accent-hover' => self::hex(self::mix($rgb, [0, 0, 0], 0.14)),
            'accent-soft' => self::hex(self::mix($rgb, [255, 255, 255], 0.88)),
            'accent-soft-ink' => self::hex(self::mix($rgb, [0, 0, 0], 0.35)),
            'focus' => self::hex($rgb),
        ];

        // Dark mode: lighten the accent so it reads on a dark surface; ink near-black; soft tints to dark.
        $lifted = self::mix($rgb, [255, 255, 255], 0.30);
        $dark = [
            'accent' => self::hex($lifted),
            'accent-ink' => '#0c1018',
            'accent-hover' => self::hex(self::mix($lifted, [255, 255, 255], 0.25)),
            'accent-soft' => self::hex(self::mix($rgb, [13, 17, 26], 0.80)),
            'accent-soft-ink' => self::hex(self::mix($lifted, [255, 255, 255], 0.20)),
            'focus' => self::hex($lifted),
        ];

        return ['light' => $light, 'dark' => $dark];
    }

    /**
     * The WCAG 2.1 contrast ratio (1.0–21.0) between two #rgb / #rrggbb colours, or null if either is
     * invalid. Public so Theme Studio can show a live AA badge while editing and tests can pin the maths.
     */
    public static function contrastRatio(string $a, string $b): ?float
    {
        $ra = self::parse($a);
        $rb = self::parse($b);
        if ($ra === null || $rb === null) {
            return null;
        }

        $la = self::luminance($ra);
        $lb = self::luminance($rb);
        [$hi, $lo] = $la >= $lb ? [$la, $lb] : [$lb, $la];

        return ($hi + 0.05) / ($lo + 0.05);
    }

    /** Does foreground-on-background meet WCAG AA? 4.5:1 for normal text, 3:1 for large/UI. */
    public static function passesAA(string $foreground, string $background, bool $large = false): bool
    {
        $ratio = self::contrastRatio($foreground, $background);

        return $ratio !== null && $ratio >= ($large ? 3.0 : 4.5);
    }

    /** @return array{0:int,1:int,2:int}|null */
    private static function parse(?string $hex): ?array
    {
        if (! is_string($hex)) {
            return null;
        }
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if (! preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return null;
        }

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }

    /** @param  array{0:int,1:int,2:int}  $rgb */
    private static function contrastInk(array $rgb): string
    {
        $lum = self::luminance($rgb);
        $withWhite = (1.0 + 0.05) / ($lum + 0.05);
        $withBlack = ($lum + 0.05) / 0.05;

        return $withBlack >= $withWhite ? '#0c1018' : '#ffffff';
    }

    /** @param  array{0:int,1:int,2:int}  $rgb */
    private static function luminance(array $rgb): float
    {
        $channel = static function (int $c): float {
            $s = $c / 255;

            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel($rgb[0]) + 0.7152 * $channel($rgb[1]) + 0.0722 * $channel($rgb[2]);
    }

    /**
     * @param  array{0:int,1:int,2:int}  $a
     * @param  array{0:int,1:int,2:int}  $b
     * @return array{0:int,1:int,2:int}
     */
    private static function mix(array $a, array $b, float $t): array
    {
        return [
            (int) round($a[0] * (1 - $t) + $b[0] * $t),
            (int) round($a[1] * (1 - $t) + $b[1] * $t),
            (int) round($a[2] * (1 - $t) + $b[2] * $t),
        ];
    }

    /** @param  array{0:int,1:int,2:int}  $rgb */
    private static function hex(array $rgb): string
    {
        return sprintf('#%02x%02x%02x',
            max(0, min(255, $rgb[0])),
            max(0, min(255, $rgb[1])),
            max(0, min(255, $rgb[2])),
        );
    }
}
