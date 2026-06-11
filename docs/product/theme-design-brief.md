<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# NovFora default theme — design brief

> Owner-approved direction for the **default theme / UI polish pass** (the phase after the RH-5/Dusk hygiene
> run). This brief is the taste contract: the implementation kickoff derives from it. The theme rides the
> existing override layer (`NOVFORA_THEME`, [THEME-API.md](../THEME-API.md)) and changes **no behavior** —
> Blade/Tailwind/CSS only, plus the two small user settings named below.

## 1. Direction (owner-chosen)

**A deliberate blend of three influences:**

- **Clean modern SaaS** — the base. Crisp typography, generous whitespace, restrained chrome, fast pages.
  Discourse/Linear-adjacent quality bar, never "enterprise gray."
- **Warm community** — the *feel*, carried by shape and rhythm rather than color: soft rounded corners,
  comfortable line-heights, friendly empty-states and microcopy, human avatars given visual priority.
  It should feel like a place, not a dashboard.
- **Classic forum, modernized** — the *structure*. Recognizable forum information architecture: category
  sections → forum rows with topic/post counts and last-post info; topic lists with author/replies/activity;
  linear post streams. A phpBB veteran should feel instantly at home; a newcomer should find it obvious.

**Palette: cool indigo/slate.** Deep indigo accent on a slate-neutral scale (evolving the current placeholder
hue, done properly). Warmth comes from geometry, spacing, and voice — not from the palette.

## 2. Hard requirements

| Requirement | Detail |
|---|---|
| **Light + dark, day one** | Both modes from a single token set (CSS custom properties). Auto (`prefers-color-scheme`) + manual toggle: per-user setting when signed in, `localStorage` for guests, no flash-of-wrong-theme (inline boot snippet). |
| **Density: comfortable default + compact toggle** | Comfortable, mobile-first rhythm by default; a user-selectable **compact** mode (denser rows, smaller paddings) for the classic-forum crowd. Implemented as a root modifier scaling spacing tokens — not parallel templates. |
| **Mobile-first, fully responsive** | Every page usable and *good* at 360 px. Nav collapses sanely; tables (topic lists) reflow rather than scroll horizontally. |
| **WCAG 2.1 AA** | Contrast verified in BOTH modes; visible focus states everywhere; touch targets ≥ 44 px; reduced-motion respected. (Roadmap Phase 5 audits; we build to it now.) |
| **Progressive enhancement** | Styling never requires JS. The dark/density toggles degrade gracefully (server-side setting still applies). No baseline feature gains a JS dependency. |
| **Performance budget** | One compiled CSS bundle, target ≤ ~50 KB gzipped; **no external fonts/CDNs** (system-ui stack — self-hosted privacy is a product value); prebuilt assets committed (RH-5 CI guard enforces freshness). |
| **Theme-API contract intact** | All colors/spacing/radii/shadows as CSS variables (design tokens) so child themes override tokens without touching templates. No breaking changes to the override layer (it is a semver'd public contract). |

## 3. Design tokens (the deliverable's foundation)

- **Color:** slate neutral scale (≈10 steps) + indigo accent scale + semantic tokens (`--surface`, `--surface-raised`,
  `--ink`, `--ink-muted`, `--line`, `--accent`, `--accent-ink`, success/warn/danger) — each with light + dark values.
- **Typography:** `system-ui` stack; type scale (~13/14/16/18/22/28); tabular numerals for counts.
- **Space/shape:** 4 px-based spacing scale (density-scaled); radii (6/10/16 — soft, the "warmth" carrier);
  two elevation shadows max.
- Implemented with **Tailwind 4** (already in the stack) configured *from* the tokens — utilities in templates,
  tokens in one CSS file.

## 4. Scope — pages and components

**Global shell:** header (wordmark "NovFora" as text for now, search, notifications bell, user menu, sign-in),
mobile nav, breadcrumbs, footer, flash/notice styling.
**Core pages:** forum index (category sections + forum rows) · forum view (topic list: title/author/replies/
views/last activity, pinned/locked badges, pagination) · topic view (post stream: avatar, author meta, body
typography, signatures, attachments, reply editor) · search results · profile page · auth pages (login,
register, 2FA setup, password reset) · user settings (incl. the new appearance settings) · notifications list.
**Staff surfaces (functional polish, not bespoke):** moderation dashboard/queue/reports, admin System panels —
consistent tables/forms/badges via the same components.
**States:** empty states with friendly copy, error pages (404/403/500), loading (Livewire) states.
**Components:** buttons (primary/ghost/danger), inputs + validation states, badges, alerts, pagination, avatar
(+initials fallback), dropdown menus, modal/confirm, tabs, toggle.
**Installer:** keep its standalone self-contained styling; align tokens only (it must never depend on app CSS).

## 5. Out of scope (this pass)

Visual theming/layout **configurator** (Phase 3) · per-site accent-color admin UI (note as Phase 3 candidate;
tokens make it cheap later) · email templates · logo/brand-mark design (text wordmark now) · any behavior or
markup-contract changes beyond the appearance settings above.

## 6. Acceptance

Every in-scope page passes: looks intentional at 360/768/1280 px · both modes AA-contrast · density toggle
works · Dusk journeys still green (selectors stable or updated) · CSS budget met · assets rebuilt + committed
(CI guard green) · screenshots (light/dark × mobile/desktop of the four core pages) attached to the PR/report.
