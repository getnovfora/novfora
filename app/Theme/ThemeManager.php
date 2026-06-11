<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Theme;

use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;

/**
 * The developer theme layer (ADR-0009 §3.2) — a SEMVER'd public contract.
 *
 * A child theme is a filesystem package (themes/<slug>/ with a theme.json manifest + a views/ dir) that
 * overrides core Blade views WITHOUT editing core. Activation prepends the active theme's view dir — and its
 * parent chain — onto Blade's view finder, so resolution is **active theme → parent theme → core**. A child
 * therefore stores only the views it changes; upgrades never clobber it. The default "theme" is core itself
 * (no active theme). Themes declare the API major they target (`api_version`) against {@see self::API_VERSION},
 * which participates in the same pre-upgrade compatibility check as modules.
 */
final class ThemeManager
{
    /** The theme API contract version. A breaking change to the overridable-view set bumps the MAJOR. */
    public const API_VERSION = '1.0';

    public function __construct(private readonly Factory $view) {}

    /** Activate a theme (defaults to config('novfora.theme.active')); returns the active Theme, or null for core. */
    public function boot(?string $slug = null): ?Theme
    {
        $slug ??= config('novfora.theme.active');
        if (! is_string($slug) || $slug === '') {
            return null; // no active theme → core views only
        }

        $chain = $this->chain($slug); // parentmost → active
        if ($chain === []) {
            return null;
        }

        $active = end($chain); // the chain is non-empty here → the active theme

        $finder = $this->view->getFinder();
        if ($finder instanceof FileViewFinder) {
            foreach ($chain as $theme) {
                if (is_dir($theme->viewPath())) {
                    $finder->prependLocation($theme->viewPath()); // last prepended is checked first → active wins
                }
            }
            $finder->flush(); // drop cached view→path resolutions so overrides take effect
        }

        return $active;
    }

    /** @return list<Theme> the active theme and its ancestors, ordered parentmost → active */
    private function chain(string $slug): array
    {
        $chain = [];
        $seen = [];
        while ($slug !== '' && ! isset($seen[$slug])) {
            $seen[$slug] = true;
            $theme = $this->load($slug);
            if (! $theme instanceof Theme) {
                break;
            }
            array_unshift($chain, $theme);
            $slug = (string) $theme->parent;
        }

        return $chain;
    }

    private function load(string $slug): ?Theme
    {
        $base = rtrim((string) config('novfora.theme.path', base_path('themes')), '/\\');
        $dir = $base.DIRECTORY_SEPARATOR.$slug;
        $manifest = $dir.DIRECTORY_SEPARATOR.'theme.json';
        if (! is_file($manifest)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($manifest), true);
        if (! is_array($data)) {
            return null;
        }

        return new Theme(
            slug: (string) ($data['slug'] ?? $slug),
            name: (string) ($data['name'] ?? $slug),
            version: (string) ($data['version'] ?? '0.0.0'),
            apiVersion: (string) ($data['api_version'] ?? '^1.0'),
            parent: isset($data['parent']) ? (string) $data['parent'] : null,
            path: $dir,
        );
    }
}
