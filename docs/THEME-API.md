<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# NovFora Theme API (developer override layer)

> **Status:** stable, **semver'd public contract** (CLAUDE.md / ADR-0009 §3.2). **API version: `1.0`**
> (`App\Theme\ThemeManager::API_VERSION`). A breaking change to the overridable-view set or manifest is a
> **major-version event**. The *visual* point-and-click configurator (DB `themes.settings` tokens) is Phase 3;
> this document covers the **developer Blade-override layer** shipped in Phase 1 (M4).

## Principle — no core edits, ever

A theme **never edits core files**. It is a filesystem package that *overrides* the views it wants to change;
everything else falls through to core. Upgrades therefore never clobber a theme, and a theme only carries the
handful of views it actually customises (XenForo-style diff inheritance, native to Blade's view finder).

## Resolution order

When NovFora renders a view it resolves it **active theme → parent theme → … → core**. The active theme's
`views/` directory is checked first; the first file found wins. So a theme shipping
`themes/<slug>/views/forum/topic.blade.php` replaces core's `resources/views/forum/topic.blade.php` with no
core change. Activation is config-only: set `NOVFORA_THEME=<slug>` (or `config('novfora.theme.active')`).

## Package layout

```
themes/<slug>/
  theme.json          # manifest (below)
  views/              # Blade overrides, mirroring core's resources/views/ tree
  assets/             # optional; built with Vite and shipped PREBUILT (no Node on the host)
```

### Manifest (`theme.json`)

```jsonc
{
  "slug": "acme/aurora",     // unique; the directory under themes/
  "name": "Aurora",
  "version": "1.2.0",        // the theme's own semver
  "api_version": "^1.0",     // the NovFora THEME API major(s) it targets — checked before a core upgrade
  "parent": "acme/base"      // optional: inherit (fall back to) another theme, then core
}
```

`api_version` participates in the **same pre-upgrade compatibility check as modules** (ADR-0008): a theme
targeting an incompatible API major is disabled with a clear report, never silently broken.

## Overridable views (the public surface, v1.0)

Any view under `resources/views/` may be overridden, but the **stable, supported** override points are:

| Slot | View | Notes |
|---|---|---|
| Page shell | `layouts.app` | Must keep the a11y floor (below). |
| Forum index | `forum.index`, `forum.partials.forum-row` | |
| Forum view | `forum.show` | |
| Topic view | `forum.topic` | Keep the SEO `@push('head')` block or re-provide it. |
| Composers (shell) | `forum.create-topic`, `forum.edit-post` | The editor island itself is JS; restyle via CSS. |
| Search | `search.index` | |
| Notifications | `notifications.index`, `settings.notifications` | |
| Auth | `auth.*` | |
| Mail | `mail.notification` | |

New supported slots are added in **minor** versions; removing or renaming one is a **major**.

## Accessibility floor (ADR-0009 §3.3 / ADR-0016) — restyle, don't remove

Core bakes in a WCAG 2.1 AA floor that themes **may restyle but must not strip**:

- a **skip link** (`<a href="#main" class="skip-link">`) and a single `#main` landmark in `layouts.app`;
- a **visible focus** rule (`:focus-visible`) and a screen-reader-only utility (`.sr-only`);
- **AA-contrast design tokens** as CSS custom properties (`--novfora-fg`/`--novfora-bg`/`--novfora-accent`/…),
  the home for the Phase 3 visual configurator. Default combinations meet AA (≥ 4.5:1 body, ≥ 3:1 UI).

A theme that overrides `layouts.app` must preserve the skip link, the `#main` landmark, and focus visibility.

## Assets

Themes ship **prebuilt** CSS/JS (Vite) so the baseline host needs no Node runtime. Reference them from the
overridden `layouts.app` (or a partial) as the theme sees fit.
