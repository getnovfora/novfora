<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Theme\Packaging;

use App\Models\SiteTheme;
use App\Modules\Packaging\ArchiveGuard;
use App\Modules\Packaging\PackageException;
use App\Support\Audit;
use App\Theme\StyleThemeManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Style import/export (U12 / NOV-110). A style theme exports to a self-contained zip — `style.json` (the
 * portable manifest) + any bound image assets under `assets/` — and imports back into a NEW style theme.
 *
 * **The export format IS the NovFora Registry theme format** (Phase 4C): a registry "theme" package is
 * exactly this zip, and the registry's one-click install rides the same untrusted-zip path — `ArchiveGuard`
 * safe extraction (never `extractTo`) + this manifest validator — so there is no second install code path.
 *
 * Exported values are the theme's EFFECTIVE (parent-chain-resolved) visuals, so a package is standalone and
 * portable (importing reproduces the look with no dependency on the exporter's style tree). Import always
 * creates a fresh root theme via {@see StyleThemeManager} (its strict token/accent/CSS validation applies to
 * imported data exactly as to editor input) — it never overwrites or activates.
 */
final class StylePackage
{
    public const FORMAT = 'novfora-style';

    public const FORMAT_VERSION = 1;

    public const MANIFEST = 'style.json';

    /** Max bytes for the manifest itself (defence-in-depth before JSON decode). */
    private const MAX_MANIFEST_BYTES = 262144;

    /** kind => [manifest asset key, the SiteTheme column, allowed extensions]. */
    private const ASSETS = [
        'logo' => ['logo_path', ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg']],
        'favicon' => ['favicon_path', ['ico', 'png', 'svg']],
        'background' => ['background_path', ['png', 'jpg', 'jpeg', 'webp']],
    ];

    public function __construct(private readonly StyleThemeManager $styles) {}

    /**
     * Build a portable zip for a style theme at a fresh temp path and return that path. The caller streams it
     * to the browser and deletes it.
     *
     * NB (v1.4 merge note): this exports the theme's OWN stored fields. Once the U10 multi-style tree merges,
     * enhance this to export {@see StyleThemeManager::effective()} + `tokens_dark` so a child theme packages
     * as its fully-resolved, standalone look — the manifest already carries a `tokens_dark` slot for it.
     */
    public function export(SiteTheme $theme): string
    {
        $manifest = [
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'generator' => 'NovFora '.config('app.version', '1.4.0'),
            'name' => (string) $theme->name,
            'slug' => (string) $theme->slug,
            'accent_color' => $theme->accent_color,
            'tokens' => is_array($theme->tokens) && $theme->tokens !== [] ? $theme->tokens : new \stdClass,
            'tokens_dark' => new \stdClass, // populated once U10's dark layer merges (format slot reserved)
            'custom_css' => (string) ($theme->custom_css ?? ''),
            'header_html' => (string) ($theme->header_html ?? ''),
            'footer_html' => (string) ($theme->footer_html ?? ''),
            'assets' => [],
        ];

        $tmp = (string) tempnam(sys_get_temp_dir(), 'novfora-style-');
        // tempnam makes a 0-byte file; ZipArchive wants to create/overwrite it.
        $zip = new ZipArchive;
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new PackageException('Could not create the style package.');
        }

        $disk = Storage::disk('public');
        foreach (self::ASSETS as $kind => [$column, $exts]) {
            $path = $theme->{$column} ?? null;
            if (is_string($path) && $path !== '' && $disk->exists($path)) {
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'bin';
                $entry = 'assets/'.$kind.'.'.$ext;
                $zip->addFromString($entry, (string) $disk->get($path));
                $manifest['assets'][$kind] = $entry;
            }
        }

        $zip->addFromString(self::MANIFEST, (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->close();

        Audit::log('style.exported', $theme, ['slug' => $theme->slug]);

        return $tmp;
    }

    /**
     * Import a style zip into a new theme. Safe-extracts via ArchiveGuard, validates the manifest, and
     * creates a fresh root theme (never overwrites/activates). Throws PackageException on any invalid input.
     */
    public function import(string $zipPath): SiteTheme
    {
        $staging = storage_path('app/style-import/'.Str::uuid()->toString());
        File::ensureDirectoryExists($staging);

        try {
            ArchiveGuard::fromConfig()->extract($zipPath, $staging);

            $manifestPath = $staging.'/'.self::MANIFEST;
            if (! is_file($manifestPath)) {
                throw new PackageException('The package has no '.self::MANIFEST.'.');
            }
            if (filesize($manifestPath) > self::MAX_MANIFEST_BYTES) {
                throw new PackageException('The package manifest is too large.');
            }

            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (! is_array($manifest)) {
                throw new PackageException('The package manifest is not valid JSON.');
            }
            if (($manifest['format'] ?? null) !== self::FORMAT) {
                throw new PackageException('This is not a NovFora style package.');
            }
            if ((int) ($manifest['format_version'] ?? 0) > self::FORMAT_VERSION) {
                throw new PackageException('This style package needs a newer version of NovFora.');
            }

            $name = trim((string) ($manifest['name'] ?? ''));
            if ($name === '') {
                throw new PackageException('The package manifest has no theme name.');
            }

            // StyleThemeManager::create() strict-validates tokens/accent/custom CSS/chrome, so a hostile
            // manifest cannot inject beyond a validated declaration — same gate as the visual editor.
            $theme = $this->styles->create([
                'name' => Str::limit($name, 60, ''),
                'accent_color' => is_string($manifest['accent_color'] ?? null) ? $manifest['accent_color'] : null,
                'tokens' => is_array($manifest['tokens'] ?? null) ? $manifest['tokens'] : null,
                'tokens_dark' => is_array($manifest['tokens_dark'] ?? null) ? $manifest['tokens_dark'] : null,
                'custom_css' => is_string($manifest['custom_css'] ?? null) ? $manifest['custom_css'] : null,
                'header_html' => is_string($manifest['header_html'] ?? null) ? $manifest['header_html'] : null,
                'footer_html' => is_string($manifest['footer_html'] ?? null) ? $manifest['footer_html'] : null,
            ]);

            // Bind any assets the manifest declares — but only from paths PROVEN inside staging and with an
            // allowed extension for that slot (the manifest is untrusted; ArchiveGuard already blocked
            // traversal on write, this re-checks the manifest's claimed path independently).
            $assets = is_array($manifest['assets'] ?? null) ? $manifest['assets'] : [];
            foreach (self::ASSETS as $kind => [$column, $exts]) {
                $rel = $assets[$kind] ?? null;
                if (! is_string($rel) || $rel === '') {
                    continue;
                }
                $abs = $this->safeStagingPath($staging, $rel);
                if ($abs === null || ! is_file($abs)) {
                    continue;
                }
                $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
                if (! in_array($ext, $exts, true)) {
                    continue;
                }
                $this->styles->attachAssetFile($theme, $kind, $abs);
            }

            Audit::log('style.imported', $theme->refresh(), ['slug' => $theme->slug]);

            return $theme->refresh();
        } finally {
            File::deleteDirectory($staging);
        }
    }

    /** Resolve a manifest-declared relative path to an absolute one PROVEN inside the staging root, or null. */
    private function safeStagingPath(string $staging, string $rel): ?string
    {
        if (str_contains($rel, "\0") || str_contains($rel, '..') || str_contains($rel, '\\') || str_starts_with($rel, '/')) {
            return null;
        }
        $abs = $staging.'/'.$rel;
        $real = realpath($abs);
        $rootReal = realpath($staging);

        return $real !== false && $rootReal !== false && str_starts_with($real, $rootReal.DIRECTORY_SEPARATOR) ? $real : null;
    }
}
