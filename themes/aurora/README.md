<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# Aurora — example filesystem child theme

A small, shipped example of NovFora's **filesystem child-theme** layer (the `ThemeManager` Blade
view-override API). It is **distinct from** the in-admin DB style editor (ADR-0029): a child theme
overrides *views*; the style editor stores accent + custom CSS in the database. Both can coexist.

## What it demonstrates

- **A manifest** — [`theme.json`](theme.json): `slug`, `name`, `version`, and the semver `api_version`
  (`^1.0`) that the engine checks against `ThemeManager::API_VERSION` (the public theme contract).
- **View overrides** — any file under `themes/aurora/views/` shadows the core view at the same dotted
  path. Aurora overrides two seams:
  - `views/partials/theme-head.blade.php` — injects a **distinct, AA-safe accent palette** into `<head>`.
    It reuses `App\Support\AccentPalette`, so the light *and* dark accent inks are computed to meet WCAG
    2.1 AA contrast rather than hand-picked. CSP-nonce-aware.
  - `views/partials/footer-tagline.blade.php` — rebrands the footer line.

## Activating it

Set the active theme to `aurora` — either `NOVFORA_THEME=aurora` in `.env`, or
*Admin → Settings → Appearance → Theme*. With no active theme the core defaults render and these
files are never read. Overrides resolve through `FileViewFinder::prependLocation`, so **no core file is
edited**.

## Writing your own

Copy this folder to `themes/<your-slug>/`, edit `theme.json`, and add `views/<dotted/path>.blade.php`
files that mirror the core view you want to override. Optionally set `"parent": "<slug>"` to inherit
another theme's overrides (resolution order is active → parent → core).
