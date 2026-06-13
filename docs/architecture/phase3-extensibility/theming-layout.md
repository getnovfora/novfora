<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# Phase 3 — Visual theming + layout configurator (B2)

> Extends the shipped theme system with a versioned theme-API contract and a region/widget layout
> configurator. **Status: Accepted — owner-authorized overnight build; flagged for review (ADR-0032).**

## 1. The theme system at a glance (three layers, no core edits)

| Layer | Where | What |
|---|---|---|
| Filesystem child themes (A4 / ADR-0009 §3.2) | `themes/<slug>/` | Blade view overrides (`ThemeManager`), e.g. `themes/aurora` |
| DB style themes (ADR-0029) | `site_themes` table | named accent + sanitised custom CSS (`StyleThemeManager`), edited in the ACP |
| **Layout configurator (B2)** | `layout_widgets` table | **widgets placed into named regions**, edited in the ACP |

## 2. Theme-API contract (`App\Theme\ThemeApi`)

A semver'd public surface (`ThemeApi::VERSION`). Two stable parts:

- **Token contract** — `ThemeApi::tokens()` lists the CSS custom properties a theme or widget may rely on and
  override: the semantic aliases (`--novfora-accent`, `--novfora-bg`, …) and the AA-derived accent palette
  (`--accent`, `--accent-ink`, …). They resolve **AA-safe in both colour modes** because they come from
  `App\Support\AccentPalette` (the same machinery the Appearance accent and the DB style editor use).
- **Regions** — the named outlets templates expose (see below).

Versioning: adding a token or region = **minor**; renaming/removing one = **major**.

## 3. Layout / widget configurator

```
WidgetRegistry  ── built-in widgets (HTML block, board stats) + module-registered widgets
LayoutManager   ── the audited writer of layout_widgets + the region renderer
<x-region name="forum_top" />  ── a template outlet that renders a region's enabled widgets in order
ACP "Layout"    ── admins-only (admin.access + 2FA) add / reorder / toggle / edit / remove
```

- A **widget** (`App\Theme\Widget`) has a stable `key()`, a `name()`, declared settings `fields()`, and a
  `render(settings): string`. Built-ins: `html` (an admin HTML/text block) and `stats` (a board-statistics
  card). Modules register their own via `WidgetRegistry` — the same extension stance as B1 slots.
- A **placement** (`layout_widgets`) binds a widget to a region at a position with its own settings and an
  enabled flag. The renderer skips disabled placements and placements whose widget is no longer registered.
- **Regions** ship for the forum index (`forum_top`, `forum_bottom`); adding more is a minor theme-API change.

## 4. Security

- **Settings are constrained to the widget's declared `fields()` on write** — `LayoutManager::updateSettings`
  drops any key the widget didn't declare, so a placement can never carry arbitrary/smuggled settings.
- **The one untrusted-input path is the HTML-block widget's admin HTML**, which is run through the same
  post-HTML allowlist sanitiser as user content (`<script>`/`<style>`/handlers stripped). Built-in widgets
  escape every dynamic value, so `<x-region>`'s `{!! !!}` only emits trusted, code-authored output.
- The ACP configurator is **admins-only** (`admin.access` + staff-2FA), self-guarded in mount + every action;
  every write is audited (`layout.widget.added|updated|enabled|disabled|removed`).

## 5. Tests

`tests/Feature/Theme/LayoutWidgetTest.php` (registry, render, settings constraint, admin-HTML sanitisation,
reorder, on-page region, the token contract) and `LayoutAdminTest.php` (ACP authz + the add/configure/remove
flow).
