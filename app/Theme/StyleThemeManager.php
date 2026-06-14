<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Theme;

use App\Models\SiteTheme;
use App\Support\AccentPalette;
use App\Support\Audit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Manages DB-backed "style themes" (the ACP visual theme editor) — named visual presets (an AA-safe accent
 * plus an optional block of custom CSS) an admin can create, edit, and activate WITHOUT touching the
 * filesystem. Distinct from the filesystem child-theme mechanism (App\Theme\ThemeManager, which overrides
 * Blade views): a style theme only emits CSS into the document head. Exactly ONE theme is active at a time
 * (a single-active invariant); the active theme's compiled CSS is cached and read once per request by the
 * layout, and invalidated on every write — the same discipline App\Settings\Settings uses for its bag.
 */
final class StyleThemeManager
{
    public const CACHE_KEY = 'novfora:style-theme:css';

    /** The active style theme, or null when none is active / the table isn't ready (pre-install). */
    public function active(): ?SiteTheme
    {
        try {
            return SiteTheme::query()->where('is_active', true)->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The compiled CSS for the active theme (accent palette for both colour modes + sanitised custom CSS),
     * cached forever and invalidated on every write. Returns '' when no theme is active. Defensive against a
     * missing table (pre-install / mid-migration), mirroring Settings::all(): on any failure return '' and do
     * not poison the cache.
     */
    public function css(): string
    {
        try {
            $cached = Cache::get(self::CACHE_KEY);
            if (is_string($cached)) {
                return $cached;
            }

            $css = $this->buildCss($this->active());
            Cache::forever(self::CACHE_KEY, $css);

            return $css;
        } catch (\Throwable) {
            return '';
        }
    }

    private function buildCss(?SiteTheme $theme): string
    {
        if (! $theme instanceof SiteTheme) {
            return '';
        }

        $css = '';

        // Accent → AA-safe CSS variables for light + dark, the SAME machinery the site Appearance accent uses
        // (so a theme accent can never fail a colour mode). Emitted first; custom CSS can still override it.
        $accent = AccentPalette::for((string) $theme->accent_color);
        if ($accent !== null) {
            $vars = fn (array $v): string => collect($v)->map(fn ($val, $k) => '--'.$k.':'.$val.';')->implode('');
            $css .= ':root{'.$vars($accent['light']).'}';
            $css .= "@media (prefers-color-scheme: dark){:root:not([data-theme='light']){".$vars($accent['dark']).'}}';
            $css .= ":root[data-theme='dark']{".$vars($accent['dark']).'}';
        }

        // Core token overrides (Theme Studio 1.1). Emitted as a plain :root{} block AFTER app.css, so they win
        // in light mode while the higher-specificity dark rules preserve the tuned dark palette. Values are
        // already strict-validated (cleanTokens), so this can never inject beyond a declaration.
        $css .= self::tokenCss(is_array($theme->tokens) ? $theme->tokens : null);

        $css .= self::sanitizeCss((string) $theme->custom_css);

        return $css;
    }

    /**
     * Compile the validated token map into a `:root{}` override block (or '' when empty). Only keys in the
     * ThemeApi editable-token contract are emitted, each as its REAL core CSS variable.
     *
     * @param  array<string,string>|null  $tokens
     */
    public static function tokenCss(?array $tokens): string
    {
        if (empty($tokens)) {
            return '';
        }

        $registry = ThemeApi::editableTokens();
        $decls = '';
        foreach ($tokens as $key => $value) {
            if (isset($registry[$key]) && $value !== '') {
                $decls .= $registry[$key]['var'].':'.$value.';';
            }
        }

        return $decls === '' ? '' : ':root{'.$decls.'}';
    }

    /**
     * Custom CSS is admin-authored (trusted) but defence-in-depth still strips anything that could break out
     * of the surrounding <style> element — a literal </style> close tag (any casing/spacing) and HTML comment
     * markers. The style tag also carries the CSP nonce, so even an injected <script> would not execute.
     */
    public static function sanitizeCss(string $css): string
    {
        $css = preg_replace('#</\s*style#i', '', $css) ?? '';
        $css = str_replace(['<!--', '-->'], '', $css);

        return trim($css);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): SiteTheme
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('A theme name is required.');
        }

        $theme = SiteTheme::create([
            'name' => $name,
            'slug' => $this->uniqueSlug($name),
            'accent_color' => $this->cleanAccent($data['accent_color'] ?? null),
            'custom_css' => self::sanitizeCss((string) ($data['custom_css'] ?? '')) ?: null,
            'tokens' => $this->cleanTokens($data['tokens'] ?? null),
            'is_active' => false,
        ]);

        if (! empty($data['activate'])) {
            $this->activate($theme);
        }

        $this->invalidate();
        Audit::log('theme.created', $theme, ['name' => $name]);

        return $theme;
    }

    /** @param array<string,mixed> $data */
    public function update(SiteTheme $theme, array $data): SiteTheme
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('A theme name is required.');
        }

        $theme->update([
            'name' => $name,
            'accent_color' => $this->cleanAccent($data['accent_color'] ?? null),
            'custom_css' => self::sanitizeCss((string) ($data['custom_css'] ?? '')) ?: null,
            'tokens' => $this->cleanTokens($data['tokens'] ?? null),
        ]);

        $this->invalidate();
        Audit::log('theme.updated', $theme, ['name' => $name]);

        return $theme->refresh();
    }

    /** Make this the one active theme, clearing the flag on every other row (the single-active invariant). */
    public function activate(SiteTheme $theme): void
    {
        DB::transaction(function () use ($theme): void {
            SiteTheme::query()->where('id', '!=', $theme->getKey())->where('is_active', true)->update(['is_active' => false]);
            $theme->forceFill(['is_active' => true])->save();
        });

        $this->invalidate();
        Audit::log('theme.activated', $theme, ['name' => $theme->name]);
    }

    /** Deactivate whatever is active → the forum falls back to the built-in default look. */
    public function deactivate(): void
    {
        SiteTheme::query()->where('is_active', true)->update(['is_active' => false]);
        $this->invalidate();
        Audit::log('theme.deactivated', null, []);
    }

    public function delete(SiteTheme $theme): void
    {
        $name = (string) $theme->name;
        $theme->delete();
        $this->invalidate();
        Audit::log('theme.deleted', null, ['name' => $name]);
    }

    /** Drop the cached compiled CSS. Called on every write. */
    public function invalidate(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** Normalise an accent to a lowercase #rrggbb hex, or null when empty/invalid (→ inherit the built-in). */
    private function cleanAccent(mixed $value): ?string
    {
        $hex = is_string($value) ? trim($value) : '';
        if ($hex === '') {
            return null;
        }
        if ($hex[0] !== '#') {
            $hex = '#'.$hex;
        }

        return preg_match('/^#[0-9a-fA-F]{6}$/', $hex) === 1 ? strtolower($hex) : null;
    }

    /**
     * Validate the editor's token map down to a safe, storable form: only keys in the ThemeApi editable-token
     * contract survive, and each value must be a strict #rrggbb hex (colour) or a `<number><px|rem|em>` length.
     * Anything else is dropped — so a token value can NEVER carry a `;`/`}`/`:` that would break out of the
     * emitted declaration (CSS-injection defence; admins are trusted but this is cheap defence-in-depth).
     *
     * @return array<string,string>|null null when nothing valid remains (so the column clears)
     */
    private function cleanTokens(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $clean = [];
        foreach (ThemeApi::editableTokens() as $key => $meta) {
            $raw = $value[$key] ?? null;
            if (! is_string($raw) || trim($raw) === '') {
                continue;
            }
            $raw = trim($raw);

            if ($meta['type'] === 'color') {
                $hex = $this->cleanAccent($raw); // reuses the strict #rrggbb normaliser (null if invalid)
                if ($hex !== null) {
                    $clean[$key] = $hex;
                }
            } elseif (preg_match('/^\d{1,4}(\.\d{1,2})?(px|rem|em)$/', $raw) === 1) {
                $clean[$key] = $raw;
            }
        }

        return $clean === [] ? null : $clean;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'theme';
        $slug = $base;
        $n = 2;
        while (SiteTheme::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
