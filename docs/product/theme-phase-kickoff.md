<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# Default theme / UI polish phase — Claude Code kickoff

> The engine is proven on real hosting (RH-1…RH-9 closed); this phase makes NovFora *look* like the product
> the brief promised. The taste contract is [theme-design-brief.md](theme-design-brief.md) — read it first
> and treat it as binding. Appearance only: no behavior changes beyond the two user settings named below.

---

```
Implement NovFora's default theme per docs/product/theme-design-brief.md. Work on a BRANCH and open a PR —
this change is reviewed visually (screenshots) before merge. Appearance/presentation only.

STEP 0: read PROJECT-STATE.md, docs/product/theme-design-brief.md (the binding taste contract),
docs/THEME-API.md, and CONTRIBUTING.md (assets rule). main must contain merge 68d5b9e (hygiene closeout).
Suite green, tree clean. COMMIT IDENTITY per CLAUDE.md: git config user.name "Tommy Huynh" &&
git config user.email tommy@saturnhq.net BEFORE the first commit; DCO -s; no AI attribution.

DIRECTION (from the brief — the blend, resolved):
  • Base: clean modern SaaS — crisp type, generous whitespace, restrained chrome.
  • Feel: warm community — carried by SHAPE and RHYTHM (soft radii, comfortable line-heights, friendly
    empty-states/microcopy), NOT by color.
  • Structure: classic forum, modernized — category sections → forum rows with counts/last-post; topic
    lists with author/replies/activity; linear post streams. Instantly familiar to forum veterans.
  • Palette: cool indigo/slate, light + dark from one token set.

PART 1 — DESIGN TOKENS (foundation; one CSS file of custom properties):
  • Slate neutral scale + indigo accent scale + semantic tokens (--surface, --surface-raised, --ink,
    --ink-muted, --line, --accent, --accent-ink, success/warn/danger) with LIGHT and DARK values.
  • Type scale on system-ui (no external fonts — see PART 5), tabular numerals for counts; 4px spacing
    scale that responds to the density setting; radii 6/10/16; two elevation shadows max.
  • Configure Tailwind 4 FROM the tokens (utilities in templates, tokens in CSS). Child themes override
    tokens without touching templates — the THEME-API override layer is a semver'd contract; do not break it.

PART 2 — APPEARANCE SETTINGS (the only behavior additions, per the brief):
  • Color mode: auto (prefers-color-scheme) / light / dark. Per-user setting when signed in; localStorage
    for guests; inline boot snippet so there is NO flash of wrong theme; degrades gracefully without JS
    (server-side setting still applies for signed-in users).
  • Density: comfortable (default) / compact — a root modifier scaling spacing tokens, NOT parallel templates.
  • Both live in user settings with tests (persistence + rendering effect).

PART 3 — PAGES & COMPONENTS (scope checklist from the brief §4):
  • Global shell: header (text wordmark "NovFora", search, notification bell, user menu, sign-in), mobile
    nav, breadcrumbs, footer, flash notices.
  • Core: forum index · forum view (topic list w/ pinned/locked badges, pagination) · topic view (post
    stream: avatar, author meta, body typography, signatures, attachments, reply editor) · search results ·
    profile · auth pages (login/register/2FA/reset) · user settings (incl. new appearance section) ·
    notifications list.
  • Staff surfaces (consistent components, not bespoke design): moderation dashboard/queue/reports,
    admin System panels.
  • States: friendly empty states, 404/403/500, Livewire loading states.
  • Component set: buttons (primary/ghost/danger), inputs + validation, badges, alerts, pagination, avatar
    (+initials fallback), dropdowns, modal/confirm, tabs, toggle.
  • Installer: keep it standalone/self-contained — align its inline tokens to the new palette ONLY; it must
    never depend on the app CSS bundle.

PART 4 — HARD REQUIREMENTS (brief §2 — acceptance gates):
  • Mobile-first; every page good at 360px; topic tables reflow, no horizontal scroll.
  • WCAG 2.1 AA contrast verified in BOTH modes; visible focus states; 44px touch targets; reduced motion.
  • Progressive enhancement: styling never requires JS.
  • Budget: ONE compiled CSS bundle ≤ ~50 KB gzipped; report the final number.

PART 5 — BUILD DETERMINISM (banked from the hygiene run — fix the class for good):
  • Rewrite resources/css/app.css's @source list DELIBERATELY: scan the app's own blade/js sources; decide
    explicitly about vendor pagination views (publish + restyle our own pagination views is cleaner); REMOVE
    the compiled-views (storage/framework/views) source so build output no longer depends on machine state.
  • The brief mandates system-ui: REMOVE the build-time external font fetching (the bunny.net plugin
    dependency) so builds are fully offline-deterministic and the assets-fresh guard can never flake on a
    CDN. Delete the now-unused committed font assets + manifest entries. If you find a strong reason to keep
    them, STOP and flag instead of deciding unilaterally.
  • assets-fresh and the manifest test must stay green; update the CI assets job if the build inputs change.

PROCESS & DELIVERY:
  • Branch + PR. Small conventional commits per area (tokens → shell → core pages → staff → states/settings).
  • Existing Dusk journeys must stay green — update selectors/page objects where markup changed, without
    weakening assertions. Add the appearance-settings tests.
  • SCREENSHOTS are a merge gate: light/dark × mobile(360px)/desktop(1280px) for forum index, topic view,
    auth, and settings — captured via the Dusk harness — attached to the PR description for owner review.
  • public/build changes: rebuild + commit per the assets rule; rebuild scripts/build-release.sh + run
    scripts/verify-release.sh (cold boot → 302 /install); report bundle size + sha256 (this becomes the next
    live deploy).
  • Docs: update PROJECT-STATE.md; note the @source/fonts determinism change in CONTRIBUTING.md if build
    inputs changed.

SCOPE FENCE: presentation + the two appearance settings + the PART 5 build hygiene only. No feature work,
no route/data changes, no theme-API breaking changes, no installer logic changes. If the brief is ambiguous
somewhere, choose the lower-churn reading and note it in the PR.
```

---

## After this

Owner reviews the PR screenshots against the brief, iterates if needed, merges, and deploys the themed
bundle to the live host — the first deploy that *looks* like NovFora. Then: **RH-4** (subdirectory install —
design spike + ADR), and Phase 2 (Community) per the roadmap.
