<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Theme;

use App\Content\ContentSanitizer;
use App\Models\SiteTheme;
use App\Models\User;
use App\Support\AccentPalette;
use App\Support\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Manages DB-backed "style themes" (the ACP visual theme editor) — named visual presets (an AA-safe accent
 * plus an optional block of custom CSS) an admin can create, edit, and activate WITHOUT touching the
 * filesystem. Distinct from the filesystem child-theme mechanism (App\Theme\ThemeManager, which overrides
 * Blade views): a style theme only emits CSS into the document head. Exactly ONE theme is the SITE DEFAULT
 * (`is_active`, a single-active invariant); since U10 a style may also descend from a parent (per-key token
 * inheritance, child wins, depth-capped + cycle-safe) and be offered to members via the per-user chooser
 * (`users.style_theme_id`, resolved by resolveFor()). Compiled CSS is cached per resolved theme under a
 * monotonic VERSION key — every write bumps the version, atomically invalidating every descendant's cache —
 * and read once per request by the layout, the same discipline App\Settings\Settings uses for its bag.
 */
final class StyleThemeManager
{
    /** The monotonic cache generation — bumped on every write so all per-theme entries orphan at once. */
    public const CACHE_VERSION_KEY = 'novfora:style-theme:v';

    /** Inheritance walks stop here (write-guarded too) — a chain deeper than this is a config error. */
    public const MAX_DEPTH = 5;

    /** @var array<string,string> asset kind => the column it lives in */
    private const ASSET_COLUMNS = ['logo' => 'logo_path', 'favicon' => 'favicon_path', 'background' => 'background_path'];

    /** Per-instance memo of the resolved active theme (the layout asks css()/chrome()/assets() of ONE
     *  instance, so this collapses their cold-cache lookups to a single site_themes query). Reset on write. */
    private ?SiteTheme $activeMemo = null;

    private bool $activeResolved = false;

    /** Per-instance memo of per-user resolutions ('u:<id>' → theme|null), same request-collapse purpose. */
    private array $resolveMemo = [];

    /** Per-instance memo of the cache generation (reset on write). */
    private ?int $versionMemo = null;

    /** The site-default style theme, or null when none is active / the table isn't ready (pre-install).
     *  Resolved at most once per instance — css(), chrome(), and assets() share the lookup on a cold cache. */
    public function active(): ?SiteTheme
    {
        if ($this->activeResolved) {
            return $this->activeMemo;
        }

        try {
            $this->activeMemo = SiteTheme::query()->where('is_active', true)->first();
            $this->activeResolved = true;
        } catch (\Throwable) {
            // Pre-install / mid-migration: the table isn't ready. Don't memoise a transient failure.
            return null;
        }

        return $this->activeMemo;
    }

    /**
     * The style theme to render for this viewer (U10): the user's chosen style IF it still exists and is
     * user-selectable (an admin un-sharing or deleting a style demotes every chooser back to the default),
     * else the site default. Guests always get the site default — per-guest variance would defeat the
     * server-rendered CSS cache and is a deliberate non-goal.
     */
    public function resolveFor(?User $user): ?SiteTheme
    {
        $choice = $user?->style_theme_id;
        if ($choice === null) {
            return $this->active();
        }

        $memoKey = 'u:'.$choice;
        if (array_key_exists($memoKey, $this->resolveMemo)) {
            return $this->resolveMemo[$memoKey] ?? $this->active();
        }

        try {
            $picked = SiteTheme::query()->whereKey($choice)->where('is_user_selectable', true)->first();
        } catch (\Throwable) {
            return null; // pre-install / mid-migration
        }

        $this->resolveMemo[$memoKey] = $picked;

        return $picked ?? $this->active();
    }

    /**
     * The styles members may choose from (the per-user chooser's option list), name-ordered.
     *
     * @return list<SiteTheme>
     */
    public function selectable(): array
    {
        try {
            return SiteTheme::query()->where('is_user_selectable', true)->orderBy('name')->get()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Resolve a theme's EFFECTIVE visual values through its parent chain (U10): token maps merge per-key
     * with the child winning; accent, chrome HTML, and asset paths inherit per-field (nearest set value
     * wins); custom CSS concatenates root→child so the child wins ties by cascade order. The walk is
     * depth-capped and cycle-safe (writes guard both, this is defence for hand-edited rows).
     *
     * @return array{accent_color:?string, tokens:?array<string,string>, tokens_dark:?array<string,string>,
     *               custom_css:string, header_html:string, footer_html:string,
     *               logo_path:?string, favicon_path:?string, background_path:?string}
     */
    public function effective(SiteTheme $theme): array
    {
        // Child-first chain, capped + cycle-safe.
        $chain = [];
        $seen = [];
        $node = $theme;
        while ($node instanceof SiteTheme && count($chain) < self::MAX_DEPTH && ! isset($seen[$node->getKey()])) {
            $chain[] = $node;
            $seen[$node->getKey()] = true;
            $node = $node->parent_id !== null ? SiteTheme::query()->find($node->parent_id) : null;
        }

        // Fold root→child so child values overwrite.
        $eff = [
            'accent_color' => null, 'tokens' => [], 'tokens_dark' => [], 'custom_css' => '',
            'header_html' => '', 'footer_html' => '', 'logo_path' => null, 'favicon_path' => null, 'background_path' => null,
        ];
        foreach (array_reverse($chain) as $t) {
            foreach (['accent_color', 'header_html', 'footer_html', 'logo_path', 'favicon_path', 'background_path'] as $field) {
                $v = $t->{$field};
                if (is_string($v) && $v !== '') {
                    $eff[$field] = $v;
                }
            }
            foreach (['tokens', 'tokens_dark'] as $map) {
                if (is_array($t->{$map})) {
                    $eff[$map] = array_merge($eff[$map], $t->{$map});
                }
            }
            if (is_string($t->custom_css) && $t->custom_css !== '') {
                $eff['custom_css'] .= ($eff['custom_css'] !== '' ? "\n" : '').$t->custom_css;
            }
        }

        $eff['tokens'] = $eff['tokens'] === [] ? null : $eff['tokens'];
        $eff['tokens_dark'] = $eff['tokens_dark'] === [] ? null : $eff['tokens_dark'];

        return $eff;
    }

    /**
     * The compiled CSS for the active theme (accent palette for both colour modes + sanitised custom CSS),
     * cached forever and invalidated on every write. Returns '' when no theme is active. Defensive against a
     * missing table (pre-install / mid-migration), mirroring Settings::all(): on any failure return '' and do
     * not poison the cache.
     */
    public function css(?User $user = null): string
    {
        try {
            $id = $this->resolvedId($user);
            $key = $this->cacheKey('css', $id);
            $cached = Cache::get($key);
            if (is_string($cached)) {
                return $cached;
            }

            $css = $this->buildCss($this->themeById($id));
            Cache::forever($key, $css);

            return $css;
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * The active theme's custom header / footer HTML (Theme Studio 1.2) — already sanitised at write time
     * (ContentSanitizer allowlist), so the layout renders it raw. Cached forever, invalidated on every write;
     * defensive against a missing table (pre-install) exactly like css().
     *
     * @return array{header:string,footer:string}
     */
    public function chrome(?User $user = null): array
    {
        try {
            $id = $this->resolvedId($user);
            $key = $this->cacheKey('chrome', $id);
            $cached = Cache::get($key);
            if (is_array($cached) && isset($cached['header'], $cached['footer'])) {
                return $cached;
            }

            $theme = $this->themeById($id);
            $eff = $theme instanceof SiteTheme ? $this->effective($theme) : null;
            $chrome = [
                'header' => $eff !== null ? $eff['header_html'] : '',
                'footer' => $eff !== null ? $eff['footer_html'] : '',
            ];
            Cache::forever($key, $chrome);

            return $chrome;
        } catch (\Throwable) {
            return ['header' => '', 'footer' => ''];
        }
    }

    /**
     * The active theme's logo + favicon URLs (the background is emitted via CSS in buildCss). Cached forever,
     * invalidated on every write; defensive against a missing table exactly like css()/chrome().
     *
     * @return array{logo:?string,favicon:?string}
     */
    public function assets(?User $user = null): array
    {
        try {
            $id = $this->resolvedId($user);
            $key = $this->cacheKey('assets', $id);
            $cached = Cache::get($key);
            if (is_array($cached) && array_key_exists('logo', $cached) && array_key_exists('favicon', $cached)) {
                return $cached;
            }

            $theme = $this->themeById($id);
            $eff = $theme instanceof SiteTheme ? $this->effective($theme) : null;
            $url = static fn (?string $p): ?string => $p !== null && $p !== '' ? Storage::disk('public')->url($p) : null;
            $assets = [
                'logo' => $eff !== null ? $url($eff['logo_path']) : null,
                'favicon' => $eff !== null ? $url($eff['favicon_path']) : null,
            ];
            Cache::forever($key, $assets);

            return $assets;
        } catch (\Throwable) {
            return ['logo' => null, 'favicon' => null];
        }
    }

    /** Store an uploaded asset on the public disk, replacing any previous file, and bind it to the theme. */
    public function storeAsset(SiteTheme $theme, string $kind, UploadedFile $file): void
    {
        $column = self::ASSET_COLUMNS[$kind] ?? throw new \InvalidArgumentException("Unknown theme asset '{$kind}'.");

        $old = (string) ($theme->{$column} ?? '');
        $path = (string) $file->store('theme-assets', 'public');
        if ($path === '') {
            throw new \RuntimeException('Could not store the theme asset.');
        }

        $theme->update([$column => $path]);
        if ($old !== '' && $old !== $path) {
            Storage::disk('public')->delete($old);
        }

        $this->invalidate();
        Audit::log('theme.asset.updated', $theme, ['kind' => $kind]);
    }

    /** Remove a bound asset (delete the file + clear the column). */
    public function clearAsset(SiteTheme $theme, string $kind): void
    {
        $column = self::ASSET_COLUMNS[$kind] ?? throw new \InvalidArgumentException("Unknown theme asset '{$kind}'.");

        $old = (string) ($theme->{$column} ?? '');
        if ($old !== '') {
            Storage::disk('public')->delete($old);
        }

        $theme->update([$column => null]);
        $this->invalidate();
        Audit::log('theme.asset.removed', $theme, ['kind' => $kind]);
    }

    private function buildCss(?SiteTheme $theme): string
    {
        if (! $theme instanceof SiteTheme) {
            return '';
        }

        // U10: compile from the EFFECTIVE values through the parent chain (child wins; custom CSS cascades).
        $eff = $this->effective($theme);

        $css = '';

        // Accent → AA-safe CSS variables for light + dark, the SAME machinery the site Appearance accent uses
        // (so a theme accent can never fail a colour mode). Emitted first; custom CSS can still override it.
        $accent = AccentPalette::for((string) $eff['accent_color']);
        if ($accent !== null) {
            $vars = fn (array $v): string => collect($v)->map(fn ($val, $k) => '--'.$k.':'.$val.';')->implode('');
            $css .= ':root{'.$vars($accent['light']).'}';
            $css .= "@media (prefers-color-scheme: dark){:root:not([data-theme='light']){".$vars($accent['dark']).'}}';
            $css .= ":root[data-theme='dark']{".$vars($accent['dark']).'}';
        }

        // Core token overrides (Theme Studio 1.1 + U9 dark layer). The light map is a plain :root{} block
        // AFTER app.css, so it wins in light mode while the higher-specificity dark rules preserve the tuned
        // dark palette; the optional dark map re-states those higher-specificity dark selectors after app.css
        // so an explicit dark override wins too. Values are already strict-validated (cleanTokens), so this
        // can never inject beyond a declaration.
        $css .= self::tokenCss($eff['tokens'], $eff['tokens_dark']);

        // Background image (Theme Studio 1.5) — a full-page background behind the board. The path is one we
        // generated (hashed name on the public disk); addcslashes is belt-and-braces against the url() quote.
        if (is_string($eff['background_path']) && $eff['background_path'] !== '') {
            $bg = Storage::disk('public')->url($eff['background_path']);
            $css .= "body{background-image:url('".addcslashes($bg, "'\\\n\r")."');background-size:cover;background-position:center;background-attachment:fixed;}";
        }

        // Every source in the chain was sanitised at write time; re-sanitise the concatenation anyway
        // (cheap defence-in-depth — the same posture as single-theme custom CSS).
        $css .= self::sanitizeCss($eff['custom_css']);

        return $css;
    }

    /** The versioned per-theme cache key (id 0 = "no theme resolves"): one version bump orphans them all. */
    private function cacheKey(string $kind, int $themeId): string
    {
        return 'novfora:style-theme:'.$kind.':'.$this->version().':'.$themeId;
    }

    /** The current cache generation, memoised per instance (one cache read per request). */
    private function version(): int
    {
        return $this->versionMemo ??= (int) Cache::get(self::CACHE_VERSION_KEY, 1);
    }

    /**
     * Resolve WHICH theme id this viewer gets using only versioned cache entries — the steady-state request
     * path costs ZERO site_themes queries (the query budgets depend on this; the model is loaded only on a
     * compile-cache miss). 0 = no theme.
     */
    private function resolvedId(?User $user): int
    {
        $choice = $user?->style_theme_id;
        if ($choice !== null && in_array((int) $choice, $this->selectableIds(), true)) {
            return (int) $choice;
        }

        return $this->activeId();
    }

    /** The user-selectable theme ids, cached under the current generation. @return list<int> */
    private function selectableIds(): array
    {
        $key = 'novfora:style-theme:selectable:'.$this->version();
        $ids = Cache::get($key);
        if (is_array($ids)) {
            return $ids;
        }

        $ids = SiteTheme::query()->where('is_user_selectable', true)->pluck('id')
            ->map(fn ($i): int => (int) $i)->all();
        Cache::forever($key, $ids);

        return $ids;
    }

    /** The site-default theme id (0 = none), cached under the current generation. */
    private function activeId(): int
    {
        $key = 'novfora:style-theme:active:'.$this->version();
        $id = Cache::get($key);
        if (is_int($id)) {
            return $id;
        }

        $id = (int) ($this->active()?->getKey() ?? 0);
        Cache::forever($key, $id);

        return $id;
    }

    /** Load a theme row for a compile-cache miss (0 → null). */
    private function themeById(int $id): ?SiteTheme
    {
        return $id === 0 ? null : SiteTheme::query()->find($id);
    }

    /**
     * Compile the validated token maps into override blocks (or '' when empty). Only keys in the ThemeApi
     * editable-token contract are emitted, each as its REAL core CSS variable. The light map emits a plain
     * `:root{}` block; the dark map (U9) emits the SAME two dark selectors app.css uses (the OS media query
     * and the explicit data-theme attribute), so an override wins in dark mode by document order while a
     * blank dark map keeps the tuned built-in dark palette untouched.
     *
     * @param  array<string,string>|null  $tokens
     * @param  array<string,string>|null  $tokensDark
     */
    public static function tokenCss(?array $tokens, ?array $tokensDark = null): string
    {
        $registry = ThemeApi::editableTokens();
        $decls = static function (?array $map) use ($registry): string {
            $out = '';
            foreach ($map ?? [] as $key => $value) {
                if (isset($registry[$key]) && $value !== '') {
                    $out .= $registry[$key]['var'].':'.$value.';';
                }
            }

            return $out;
        };

        $css = '';
        $light = $decls($tokens);
        if ($light !== '') {
            $css .= ':root{'.$light.'}';
        }

        $dark = $decls($tokensDark);
        if ($dark !== '') {
            $css .= "@media (prefers-color-scheme: dark){:root:not([data-theme='light']){".$dark.'}}';
            $css .= ":root[data-theme='dark']{".$dark.'}';
        }

        return $css;
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
            'parent_id' => $this->cleanParent($data['parent_id'] ?? null, null),
            'accent_color' => $this->cleanAccent($data['accent_color'] ?? null),
            'custom_css' => self::sanitizeCss((string) ($data['custom_css'] ?? '')) ?: null,
            'tokens' => $this->cleanTokens($data['tokens'] ?? null),
            'tokens_dark' => $this->cleanTokens($data['tokens_dark'] ?? null),
            'header_html' => $this->cleanHtml($data['header_html'] ?? null),
            'footer_html' => $this->cleanHtml($data['footer_html'] ?? null),
            'is_active' => false,
            'is_user_selectable' => (bool) ($data['is_user_selectable'] ?? false),
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
            'parent_id' => $this->cleanParent($data['parent_id'] ?? null, $theme),
            'accent_color' => $this->cleanAccent($data['accent_color'] ?? null),
            'custom_css' => self::sanitizeCss((string) ($data['custom_css'] ?? '')) ?: null,
            'tokens' => $this->cleanTokens($data['tokens'] ?? null),
            'tokens_dark' => $this->cleanTokens($data['tokens_dark'] ?? null),
            'header_html' => $this->cleanHtml($data['header_html'] ?? null),
            'footer_html' => $this->cleanHtml($data['footer_html'] ?? null),
            'is_user_selectable' => (bool) ($data['is_user_selectable'] ?? false),
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

        // U10: a parent with children can't be deleted — a silent re-root would change every child's look.
        if (SiteTheme::query()->where('parent_id', $theme->getKey())->exists()) {
            throw new \InvalidArgumentException('This style has child styles — delete or re-parent them first.');
        }

        // Clean up any bound asset files so a deleted theme leaves nothing orphaned on disk.
        foreach (self::ASSET_COLUMNS as $column) {
            $path = (string) ($theme->{$column} ?? '');
            if ($path !== '') {
                Storage::disk('public')->delete($path);
            }
        }

        // Members who chose this style fall back to the site default (chooser writes are validated against
        // live selectable rows, so a stale id would already resolve to the default — this keeps rows clean).
        User::query()->where('style_theme_id', $theme->getKey())->update(['style_theme_id' => null]);

        $theme->delete();
        $this->invalidate();
        Audit::log('theme.deleted', null, ['name' => $name]);
    }

    /** Orphan every cached compiled CSS / chrome / asset entry by bumping the version. Called on every
     *  write — a parent edit must invalidate every descendant, and the single bump does so atomically. */
    public function invalidate(): void
    {
        // Drop the per-instance memos too, so a read after a write on the same instance re-resolves.
        $this->activeMemo = null;
        $this->activeResolved = false;
        $this->resolveMemo = [];
        $this->versionMemo = null;
        try {
            Cache::forever(self::CACHE_VERSION_KEY, ((int) Cache::get(self::CACHE_VERSION_KEY, 1)) + 1);
        } catch (\Throwable) {
            // Cache store unavailable — the versioned keys simply miss and rebuild.
        }
    }

    /**
     * Sanitise admin-authored header/footer HTML through the SAME user-content allowlist as posts
     * (ContentSanitizer) BEFORE storage — <script>/<style> and any non-allowlisted element are dropped, so
     * what is stored (and later rendered raw) is always safe. Returns null when nothing survives.
     */
    private function cleanHtml(mixed $value): ?string
    {
        $html = is_string($value) ? trim($value) : '';
        if ($html === '') {
            return null;
        }

        $clean = trim(app(ContentSanitizer::class)->sanitize($html));

        return $clean === '' ? null : $clean;
    }

    /**
     * Validate a proposed parent (U10): it must exist, not be the theme itself, not create a cycle (the
     * proposed parent's chain may not contain the theme), and not exceed the depth cap. Invalid → throws,
     * so the editor surfaces the reason instead of silently mis-rooting.
     */
    private function cleanParent(mixed $value, ?SiteTheme $theme): ?int
    {
        $id = is_numeric($value) ? (int) $value : null;
        if ($id === null || $id <= 0) {
            return null;
        }

        if ($theme !== null && $id === (int) $theme->getKey()) {
            throw new \InvalidArgumentException('A style cannot be its own parent.');
        }

        $parent = SiteTheme::query()->find($id);
        if (! $parent instanceof SiteTheme) {
            throw new \InvalidArgumentException('The chosen parent style no longer exists.');
        }

        // Walk the proposed parent's chain: cycle + depth guard.
        $depth = 1; // the parent itself
        $node = $parent;
        $seen = [];
        while ($node->parent_id !== null && ! isset($seen[$node->getKey()])) {
            if ($theme !== null && (int) $node->parent_id === (int) $theme->getKey()) {
                throw new \InvalidArgumentException('That parent would create a style cycle.');
            }
            $seen[$node->getKey()] = true;
            $node = SiteTheme::query()->find($node->parent_id);
            if (! $node instanceof SiteTheme) {
                break;
            }
            $depth++;
        }

        if ($depth >= self::MAX_DEPTH) {
            throw new \InvalidArgumentException('Style trees are limited to '.self::MAX_DEPTH.' levels.');
        }

        return $id;
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
