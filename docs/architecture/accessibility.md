# Accessibility — WCAG 2.1 AA (Wave 8.2)

> Status: automated parser-level audit shipped + gating; manual checklist below is owner/QA responsibility.
> ADR-0044.

NovFora targets **WCAG 2.1 Level AA**. Accessibility is enforced in two complementary layers — an automated
gate for everything a machine can verify, and a manual checklist for everything it cannot.

## Automated audit (the gate)

`App\Accessibility\AccessibilityAuditor` parses rendered HTML (DOMDocument) and reports deterministic
violations. It is exercised two ways:

- **`tests/Feature/Accessibility/WcagAuditTest.php`** renders the high-traffic surfaces (board, search +
  facets + save form, saved searches, topic, forum listing, create-topic form, appearance/profile settings,
  profile, members, login, register) through the real layout and asserts **zero findings**. This runs in the
  normal `pest` gate, so an a11y regression on these pages fails CI.
- **`php artisan novfora:a11y:audit <url|file>`** runs the same engine ad hoc against a live URL or an HTML
  file (add `--fragment` for a partial). Non-zero exit on findings, so it can gate a cron/CI step too.

Engine unit tests (`tests/Unit/Accessibility/AccessibilityAuditorTest.php`) prove it both **catches** each
violation class and does **not** false-positive on conformant markup.

### What the audit checks (machine-verifiable)

| WCAG SC | Check |
| --- | --- |
| 1.1.1 | every `<img>` has an `alt` (empty `alt=""` allowed for decorative); image buttons have alt/aria |
| 1.3.1 | exactly one `main` landmark; `<label for>` / `aria-describedby` resolve to a real id |
| 2.4.1 | a skip-link / bypass mechanism exists |
| 2.4.2 | the document has a non-empty `<title>` |
| 2.4.3 | no positive `tabindex` |
| 2.4.4 / 4.1.2 | every link & button has an accessible name (text, `aria-label`, or a labelled icon) |
| 3.1.1 | `<html lang>` is present and non-empty |
| 4.1.2 | every visible form control has an associated label or aria name; `aria-labelledby` resolves |

### Bugs this wave found and fixed

- The header **colour-mode toggle** had only an Alpine `:aria-label` binding — no accessible name in the
  server HTML (pre-hydration / no-JS). Added a static `aria-label` that Alpine still enhances.
- The **"Save this search"** name field (Wave 6.1) had only a `placeholder` (not an accessible name). Added
  `aria-label`.
- The **create-topic tag input** had a visible "Tags" label that was not associated with the field. Wired it
  with `for`/`id`.

## Manual checklist (NOT machine-verifiable — owner/QA each release)

Static HTML cannot prove these; verify them by hand (keyboard + a screen reader + the browser a11y panel):

- **1.4.3 Contrast (AA).** Text ≥ 4.5:1, large text/UI ≥ 3:1. The accent system already enforces a WCAG
  contrast floor (`App\Theme\AccentPalette`); re-check any **custom theme tokens** an admin sets.
- **1.4.10 Reflow / 1.4.4 Resize.** Usable at 320px wide and 200% zoom with no loss of content/function.
- **2.1.1 / 2.1.2 Keyboard.** Every control reachable and operable by keyboard; no focus traps (check the
  mobile nav, dropdowns, the editor, modals).
- **2.4.7 Focus visible.** A clear focus ring on every interactive element.
- **1.4.13 Hover/focus content.** Dismissable, hoverable, persistent popovers/tooltips.
- **2.3.1 / `prefers-reduced-motion`.** No content flashing > 3×/s; honour reduced-motion.
- **4.1.3 Status messages.** Live regions announce async updates (Livewire validation, toasts).
- **Screen-reader pass.** Read the core journeys with NVDA/VoiceOver: post a reply, search, change settings.
- **RTL visual pass.** With an RTL locale (Wave 8.1), confirm layouts mirror correctly — the `dir` switch is
  automated, the visual result is not.

## Phase 5 (P5.2) — coverage expansion + fixes

The automated gate was extended from the original 14 high-traffic surfaces to **27**, now covering the Phase
3/4 + remaining user-facing flows: the clubs directory / create form / club page / roster, the membership
(tiers) page, notifications, the PM inbox + compose, the notification + general preferences forms, the
top-members leaderboard, the activity home feed, trending, what's-new, saved/bookmarks, and a tag page. The
sweep found and fixed **three** WCAG 4.1.2 (accessible-name) failures the overnight Phase-4 builds introduced:

- The **PM compose** "To" recipient `<input>` had a visible label that was not associated with the field —
  wired with `for`/`id`.
- The **club roster** role `<select>` (per member) had no accessible name — added an `aria-label` naming the
  member (`Change role for :name`).
- The **push enable/disable** buttons on the notification-settings page set their label via Alpine `x-text`, so
  the pre-hydration / no-JS HTML had an empty button — added a static `aria-label` (mirrors the Wave-8.2
  colour-toggle fix); Alpine still drives the visible label.

After the fixes the full 27-surface gate is green (zero machine-detectable violations).

### Residual MANUAL-only items (owner/QA each release — static HTML cannot prove these)

The automated auditor is a **floor, not conformance**. The manual checklist above (contrast 1.4.3, keyboard
2.1.1/2.1.2, visible focus 2.4.7, hover/focus content 1.4.13, reduced-motion 2.3.1, status-message live
regions 4.1.3, the screen-reader journey pass, and the RTL visual pass) remains owner/QA responsibility and is
**not** covered by CI. Re-check any **admin-set custom theme tokens** against the contrast floor, and re-run the
screen-reader + keyboard passes on the newly-covered Phase 3/4 flows (clubs, PMs, memberships) before 1.0.

### Wave 8.3 — v1.x batch + targeted-fixes a11y pass (P2)

The automated gate grew from **27 to 30** surface cases, now covering the new/changed STAFF surfaces from the
v1.x batch + the targeted fixes: the **moderation control panel** (dashboard / queue / the F4 reported-post
review), the **per-member admin screen** (F2 trust / reputation / ban / warn), and the **ACP analytics
dashboard**. (The board index + board listing already cover the F5/F6 Info-Center collapse, coloured
who's-online, and latest-activity author/topic.)

Extending the gate to the ACP exposed and fixed one machine-detectable failure:

- **WCAG 1.3.1 (multiple `main` landmarks)** on EVERY ACP page: the `x-admin.shell` content column emitted its
  own `<main>` while the app layout already provides the single `<main id="main">` (the skip-link target), so
  every ACP page had two main landmarks. The shell's element is now a plain `<div>` — one main per page. (Latent
  until now because the ACP pages were not previously in the auditor's surface list.)

Manual pass on the new/changed controls (the items the static auditor cannot prove):

- **Info-Center collapse (F5)** — a real `<button>` with `:aria-expanded` + `aria-controls="info-center-body"`;
  the open/close animation is `x-collapse`, and the global `prefers-reduced-motion` block already neutralises
  its (and the chevron's) motion. The no-flash hook sets a `<html>` attribute before paint — no focus or
  reading-order impact.
- **Reported-post review actions (F4)** — every moderator action is a real button/link with visible text; the
  inline **Move** and **Warn** `<select>`s each carry an `sr-only <label for>` (id-matched, so no broken-ref);
  the post excerpt is server-sanitised prose. Actions render only when permitted, so the tab order never
  includes a control the viewer can't use.
- **Per-member trust + reputation controls (F2)** — every `<select>`/`<input>` has an associated `<label for>`;
  the reputation reason is required + length-capped server-side.
- **Coloured names (F5/F6)** — who's-online + latest-activity authors render through `<x-ui.user-name>`, whose
  group colours are the AA-safe `--group-*` token palette (verified in both modes by `GroupColorTest`), not a
  raw hex.
- **Analytics chart (T3)** — re-confirmed: the sparkline SVGs are `aria-hidden` / `role="presentation"` and the
  **data table below is the accessible equivalent** (unchanged by this batch).
- **Visible focus (2.4.7)** — added a focus ring to the ACP member-table **sort buttons** (were focus-colour
  only) and the **subscribe/follow toggle** (was ring-less); both now show `focus-visible:ring-2 ring-accent`.

**Residual MANUAL-only items for the new surfaces** (owner/QA, each release): a screen-reader pass on the
reported-post review flow (does the excerpt + the action group read coherently per card?); keyboard operation
of the Info-Center collapse + the report-card Move/Warn selects on a real device; and re-confirming the
group-colour name contrast on the row-hover surfaces under any **admin-set custom theme** (the default palette
is AA-verified, custom tokens are not).

## Scope

The automated gate covers the surfaces listed above. Extending it to every view is mechanical (add a case to
`WcagAuditTest`). The auditor is a **floor, not a guarantee** — passing it means no machine-detectable
violation on the audited pages, not full AA conformance, which requires the manual pass above.
