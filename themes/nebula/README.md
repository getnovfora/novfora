<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# Nebula — first-party example theme

A polished **filesystem child theme** built purely on the NovFora **theme API** (Phase-3 dogfood) — zero core
edits. It demonstrates the two stable theme seams:

- **Token overrides** (`views/partials/theme-head.blade.php`) — a distinct, AA-safe violet accent derived by
  `App\Support\AccentPalette` (the same machinery the Appearance accent and the DB style editor use), plus two
  of the documented `App\Theme\ThemeApi::tokens()` semantic aliases (`--novfora-accent`, `--novfora-radius`).
  A theme restyles by overriding these CSS custom properties, never by editing core markup.
- **Footer branding** (`views/partials/footer-tagline.blade.php`) — a second view override.

It **coexists** with the module slot system (`SlotRegistry` slots still render) and the admin **layout/region
configurator** (`<x-region>` widgets still render) — a theme changes presentation only.

## Activate

```
NOVFORA_THEME=nebula            # .env (or Admin → Appearance → Active theme)
```

Resolution is **active theme → parent theme → core** (`ThemeManager`), so Nebula ships only the views it
changes and upgrades never clobber it.
