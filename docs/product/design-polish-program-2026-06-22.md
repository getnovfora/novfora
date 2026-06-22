<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# Design-Polish Program — NovFora (proposed, 2026-06-22)

> **Companion to** [`audit-ips-gap-analysis-2026-06-22.md`](audit-ips-gap-analysis-2026-06-22.md). That doc covers
> *functional* gaps. This one makes **look-and-feel a first-class, tracked deliverable** — form weighted equally with
> function — across the admin panel and the member experience, with the rich-text editor as the flagship.
>
> **Status:** proposed / awaiting approval. Not yet an ADR.
>
> **Clean-room (hard rule, CLAUDE.md):** every item here is **independently designed to a standard**. We study what
> capable software *does* (affordances, density, richness) and rebuild it in NovFora's own design language. We never
> copy markup, CSS, themes, or assets from IPS/XenForo/any reference forum.

---

## 0. Principle — form = function

The bar: a new admin should navigate the ACP as easily as Invision's, and a member should feel the composer, topic, and
profile are as polished as any commercial forum — while staying unmistakably NovFora (clean, keyboard-first, privacy-first,
no sprawl).

Important: this is **not** a rescue job. NovFora's design *foundation* is already strong (see §1). The program is about
(a) fixing **polish bugs**, (b) raising **affordance richness** to the incumbent standard where it's genuinely thin, and
(c) **systematizing** both so quality stops being per-surface luck and can't silently regress.

---

## 1. Honest baseline — what we're starting from

**Already strong (build on it, don't redo):**

- A real **semantic design-token system** in `resources/css/app.css`: one token set, two modes; `bg-surface`/`text-ink`/
  `border-line`/`ring-accent` etc. resolve per colour-mode with no `dark:` variants.
- **Dark mode** done properly: OS detection + per-user override + no-flash SSR; AA-verified in both modes.
- **Density** as a root modifier (`--spacing` scaling) — comfortable/compact with a ≥44px touch floor preserved.
- A **type scale, radii, two-tier elevation**, tabular-nums, reduced-motion handling, and an **a11y floor** (skip link,
  `:focus-visible`, sr-only) that's asserted by tests.
- A **Theme-API public contract** (`--novfora-*` override points, `docs/THEME-API.md`) and an `<x-ui.*>` component set
  (`card`, `badge`, `container`, `user-name`, `staff-flair`, …).

**Uneven / thin (the program's targets):**

- **Polish bugs** — e.g. the `.novfora-prose` height-cap leak (M0 below): the editor box's `max-height:28rem; overflow-y:auto`
  rides the shared class onto *rendered posts*, clipping every long post into an inner scrollbar. Small cause, worst-rated defect.
- **Editor richness** — the composer is deliberately minimal (single image-only click-upload, no drag-drop attach zone,
  H2-only headings, no insert/style menus) even though the render schema already supports tables/embeds/details.
- **Interaction & state coverage** — hover/active/pressed, transitions, and especially **empty / loading / skeleton / error**
  states are not yet a systematic, documented set.
- **Data-table styling** — there's no reusable polished table component yet (the ACP member table, gap A1, needs one).
- **ACP section-switch feel** — the secondary sidebar appears/disappears on navigation, which reads as slightly unstable.

---

## 2. The polish bug to ship now (M0)

| Item | Diagnosis | Fix | Where |
|---|---|---|---|
| **M0 — inner-post scroll trap** (audit's #1 "critical"; I previously mis-called it a false positive) | Rendered posts use the bare `.novfora-prose` class, which carries `max-height: 28rem; overflow-y: auto` (`app.css:404`). That cap is meant for the editor's *editing box*; it leaks onto posts, so any post over ~28rem renders inside a sub-scroller → scroll-trapping. | Scope the height cap to the editor only — e.g. move it to `.novfora-editor .novfora-prose` (or onto `.novfora-mount`), leaving bare `.novfora-prose` (posts at `topic.blade.php:175,178`, signatures at `profiles/show.blade.php:147`) uncapped. Preserve the `EditorJourneyTest` Dusk contract on `.novfora-prose`. | `resources/css/app.css:404`; `forum/topic.blade.php`; `profiles/show.blade.php` |

Ship as a **1.0.x polish hotfix** — tiny, isolated, high-impact, no schema change. It's also the worked example of why this
program exists.

---

## 3. The four pillars

### Pillar 1 — Design-system foundation *(cross-cutting; underpins everything)*
Mature the existing token system into a **documented, gap-filled component library** so polish is reusable, not re-invented.

- **Component audit** of `<x-ui.*>`: inventory every variant/state; fill the missing primitives the rest of the program
  needs — **data-table**, dropdown/popover, modal/dialog, tabs, toast/inline-alert, **skeleton loader**, **empty-state**.
- **Interaction & motion tokens**: standard transition durations/easings, hover/active/pressed, keep `:focus-visible`,
  honour `prefers-reduced-motion` (already handled globally).
- **State coverage as a contract**: every list/table/page surface defines empty / loading / error / success.
- **Iconography**: one icon set + sizing scale (the ACP rail + member nav + editor all draw from it).
- **Document** in `docs/THEME-API.md` + a short design-system reference; optional visual-regression guard.

### Pillar 2 — ACP navigability + polish *(Invision-level ease, cleaner aesthetic)*
The IA is already Invision-style (ACP v3-h). This is the **feel** pass.

- **Section-switch stability**: a persistent sidebar shell so moving between sections doesn't reflow the frame (fixes the
  "slightly unstable" weakness the audit named).
- **Polished `x-ui.table`**: sortable, filterable, sticky header, density-aware, row actions, bulk-select, pagination —
  lands *with* the member table (gap A1) and is reused everywhere admin shows tabular data.
- **Card-grid landing** visual refinement + consistent **breadcrumbs** + a more prominent **global ACP search** affordance.
- **Quick-links / recents** (audit gap) in the rail or top bar.
- **One form-layout system** (label, help, required, validation, grouping) applied across every settings page so they read
  as one product, not N pages.

### Pillar 3 — Member-experience polish
- **M0 scroll-trap fix** (above).
- **Post card**: tighten author block + action hierarchy — **de-weight `Delete` vs `Edit`** (danger-soft, not equal weight);
  add the per-post **quote/reply** affordance (functional gap M1) as a first-class control.
- **Topic list**: first-post **excerpt** + **unread/read** state (functional gaps M2/M3), density-aware rows.
- **Notifications**: inline **dropdown/popover** polish (functional gap M4).
- **Profile + member directory**: card and hero refinement, consistent stats, skeletons.
- **Micro-interactions + mobile**: reaction feedback, toasts, sticky composer affordances, touch targets.

### Pillar 4 — FLAGSHIP: the rich-text editor *(curated-rich, keyboard-first, clean-room)*

**Current state** (`resources/views/components/content-editor.blade.php` + `resources/js/editor/novfora-editor.js`): a
**TipTap island** syncing canonical JSON into Livewire via `wire:ignore` + deferred `$wire.set`; toolbar = bold / italic /
strike / **H2-only** / bullet / ordered / quote / code-block / link / spoiler / **single image upload** (image-only,
click-to-pick — **no drag-drop, no general attachments**); plus slash-commands, `@`-mentions, draft autosave, and oEmbed.
The render schema *already* supports tables, `details`, `hr`, mentions, embeds — they're just not exposed in the toolbar.

**Target — match incumbent richness, keep our voice:**

- **Attachments (the headline, per the screenshot):** a real **drag-and-drop attach zone** — multi-file, non-image types,
  upload progress, **max-size display**, paste-to-upload, click-to-browse fallback, basic reorder/remove. Replaces the
  single image-only picker. *Tier-aware:* Baseline → local disk; Enhanced → S3/MinIO. **This path is apex** (untrusted file
  input → MIME/size/extension validation, path-traversal safety, sanitised render) per CLAUDE.md routing.
- **Toolbar architecture, not sprawl:** group marks (B/I/S/code), and add a tidy **"Text style"** menu (paragraph / **H1–H3** /
  quote) and an **"Insert"** menu (link / image / **table** / embed / spoiler / hr). Power via two menus, not twenty buttons.
- **Expose schema we already render:** heading levels H1 & H3 (not just H2), table insert, horizontal rule, details.
- **Emoji picker** in the composer (reuse the 6-type reaction set + a fuller set).
- **Affordance polish:** visible **tooltips** (`title`) layered on the existing `aria-label`s; a proper **link dialog**;
  **smart paste** (URL → oEmbed facade, image → upload); mobile toolbar overflow into a `…` menu.
- **Keep (do not regress):** canonical-JSON sync, draft autosave, slash-commands, `@`-mentions, Markdown toggle,
  sanitise-on-render allowlist, the `.novfora-prose` Dusk contract.
- **A11y:** roving-tabindex toolbar (`role="toolbar"` already present), full keyboard operation, reduced-motion.
- **Anti-goals (stay NovFora):** no toolbar clutter; commands-first stays the identity; never clone a reference editor's UI.

Outcome: a composer that *feels* as capable as the richest incumbents, built independently, still clean and keyboard-first.

---

## 4. Roadmap placement

The Design-Polish Program runs as a **parallel track threaded through the post-1.0 functional milestones** (the 1.1/1.2/1.3
from the gap-analysis doc), plus a foundation slice and an immediate hotfix.

| When | Polish work | Rides with |
|---|---|---|
| **1.0.x (now)** | **M0 scroll-trap hotfix** | standalone polish hotfix |
| **Foundation slice (early, cross-cutting)** | Pillar 1: component audit + `x-ui.table` + empty/loading/skeleton states + motion tokens | precedes/*unblocks* 1.1 & 1.2 |
| **1.1 — Member UX** | Pillar 3 + **Pillar 4 editor (flagship)** | the functional member items M1–M6 |
| **1.2 — ACP v4** | Pillar 2 (section-switch stability, table, forms, quick-links) | the admin items A1–A3 (table polish lands with the A1 member table) |
| **1.3 — Admin tooling** | polish pass on canned-replies / email-template / analytics-charts surfaces | A4–A6 |

**Model routing (CLAUDE.md):** most of this is **Sonnet-class** (Blade/CSS/component work, multi-surface sweeps via Explore
sub-agents). The **editor attachment/upload path is apex (Fable @ max)** — it's an untrusted-input boundary. Each visual
change still passes the deterministic gates (Pint/Larastan/Pest/Dusk + the a11y page gate).

**Sequencing recommendation:** (1) ship M0 now → (2) Pillar 1 foundation slice **and** the editor attach-zone in parallel
→ (3) thread Pillars 2 & 3 through 1.1/1.2 as those milestones land. Highest visible payoff first: **M0**, then the
**editor drag-drop attachments**.

---

## 5. Definition of done (so "polish" is testable, not vibes)

- Every new/161 touched component has documented variants + empty/loading/error states.
- A11y page gate still green on touched surfaces; new interactive controls keyboard-operable with visible focus.
- The editor: attachments work on Baseline (local disk) with size/type enforcement + a passing upload-security test;
  toolbar fully keyboard-navigable; canonical-JSON + draft autosave unchanged; Dusk editor journey green.
- No `.novfora-prose`-style class leaks: editor-only styling is scoped to `.novfora-editor`.
- Density + dark mode honoured on every new surface (no hard-coded colours; tokens only).
