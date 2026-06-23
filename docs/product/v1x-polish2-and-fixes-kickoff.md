<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# v1.x — Targeted fixes + Polish-2 / a11y — EXECUTABLE KICKOFF (Claude Code, unattended)

> **One run, two tracks.** **Track F** = the owner's specific fixes. **Track P** = a design-critique + accessibility
> pass over the new v1.x surfaces **and** the F-changed ones (so P runs last). Run protocol + standing rules as
> `docs/product/v1x-feature-program-kickoff.md`: independent branch per slice off `main`, gated green at each boundary
> (commit as `Tommy Huynh`, DCO `-s`, no AI trailers; **`php artisan route:clear` before trusting subdir/PWA reds**),
> **nothing pushed/merged** — leave branches local for review + the morning report atop `PROJECT-STATE.md`. **Verify each
> slice against current `main` first; prefer the codebase.** Apex slices get an in-session adversarial verify-then-refute
> review — **HALT + report on any unresolved HIGH**.
>
> **ADRs:** next-free is ~**0101** (0095–0100 are taken). F2 (new capability keys) needs one; confirm before lifting.

## Order
`F1 → F3 → F5 → F6 → F4 → F2`, then **`P1 → P2`** (P last, so the critique + a11y cover the F changes too). All independent
branches off `main`; the owner merges.

---

## Track F — targeted fixes

### F1 — Remove the ACP "Recent sections" rail element · `claude/fix-acp-recents` · rung: Sonnet
The Pillar-2 polish added a **RECENT / Clear recent** list of recently-visited admin sections to the ACP sub-sidebar.
It's confusing — **remove it entirely**: the rail block, its tracking/recording, any per-user store, and any nav wiring.
Verify-first: locate it (likely `AdminNavigation` + the section-sidebar view + a small recents store/localStorage). Remove
cleanly (no dead refs/tests). Gate: Pint · Pest (drop/adjust any recents test) · a11y.

### F2 — Manual trust + reputation editing (admin) · `claude/fix-manual-trust-rep` · ⚠ APEX · rung: Fable @ max
**Why:** admins (and any holder of the new capability) should manually set a member's **trust level** and **adjust
reputation**. It's a manual staff action, so it is **not** anti-spam-gated. Lives on the per-member admin view (A2).
**Do:** (a) a trust-level selector (admin override) and (b) a reputation adjust (signed delta + a required reason). Add
**new capability keys** (`members.trust.manage`, `members.reputation.manage`) to the catalog (additive `PermissionSync`);
gate via the engine + the rank guard; **audit every change** (actor, target, old→new, reason).
**Apex correctness (assumptions — confirm in the ADR):** trust ↔ effective permissions, so a manual change MUST flush the
membership/resolver cache via the established `MembershipCache` seam. Interaction with `GroupAutoPromoter` (promotion-only):
a manual set is a **sticky admin override** recorded + audited; the auto-promoter may still promote ABOVE it but never
silently demotes it. Reputation writes go through the **existing reputation ledger/event** (reuse, don't bypass). Fail-closed;
no ALLOW beyond the actor's ceiling.
**Review:** verify-then-refute (cache-flush correctness, capability escalation, audit completeness, auto-promoter race).
**Tests:** gated set/adjust; cache invalidation flips the inspector verdict; auto-promoter interaction; audit rows. **New ADR.**

### F3 — Moderation panel width = forum width · `claude/fix-mod-width` · rung: Sonnet
The `/moderation/*` pages render narrower/centered than the rest of the forum. Wrap them in the **standard forum container**
(`<x-ui.container size="lg">` / the `--layout-max-width` token) so the Dashboard / Queue / Reports tabs match the board
width. Verify-first: the mod-CP layout/container. Gate: visual + a11y.

### F4 — Reported-post review UX · `claude/fix-report-review` · ⚠ apex-adjacent (mod permissions) · rung: Opus high
**Why:** the Reports tab only shows "Post #N reported by X" + Resolve/Dismiss — no context, no actions.
**Do:** each report card shows the **reported post's content** (rendered excerpt/body), the **reporter + reason**, and a
**link to the original post in its topic** (`route('topics.show', $topic)` + the `#post-{id}` anchor). Add the **standard
moderator actions the viewer is permitted**, each `@can`-gated via the EXISTING policies/services (don't reimplement):
Lock/Unlock topic, Pin, Edit post, Delete post, Delete/Move topic, Warn author — alongside Resolve/Dismiss. Only render
actions the viewer can perform.
**Tests:** each action gated correctly (mod vs non-mod); the post link resolves to the right anchor; resolve/dismiss
unaffected; no private-club leak in the rendered post. a11y on the card + actions.

### F5 — Info Center tidy + collapsible + Who's-Online colors · `claude/fix-infocenter` · rung: Sonnet
**Why:** tidy the board-index **Info Center** (Statistics + Who's Online) and make it nicer to scan.
**Do:**
- **Tidy** the layout into a clean two-column block on the design tokens/components (study ProBoards' + SMF's Info-Center
  layouts for patterns — compact stat rows, online list — but **clean-room: build independently**, no copied markup/CSS).
- **Collapsible (SMF-style):** a collapse/expand control on the Info Center, **persisted per-user in `localStorage`** (the
  same no-flash pattern as the density/theme toggles; **no migration**).
- **Who's Online group colors:** render each online member through **`<x-ui.user-name :user>`** (it already applies the
  group name colour + staff flair + visibility rules) instead of a plain username — so e.g. an Administrator shows red.
Gate: a11y (the collapse control is a real `<button aria-expanded>`); reduced-motion; dark + density.

### F6 — Latest-activity shows who + which topic · `claude/fix-latest-activity` · ⚠ perf (board-index hot path) · rung: Opus high
**Why:** the forum-index rows show only a relative timestamp for "Latest activity" (the member audit's flagged gap).
**Do:** show the **last post's author + the topic title**, linked to the latest post — e.g. "{topic} · by {user} · {time}".
Reuse `<x-ui.user-name>` for the coloured author (ties to F5). Apply to the forum rows and the Info-Center "Recent" where
applicable.
**Perf (mandatory):** this is the board-index hot path — resolve last-post author+topic **without N+1** (eager-load or a
denormalised `last_post_*` on the forum; a bounded query). **Extend `HotPathQueryTest`** to hold the budget. Respect
visibility — never leak a hidden forum's last post/author. Gate: HotPathQueryTest green + the suite.

---

## Track P — Polish-2 + a11y (run LAST; covers the F changes too)

### P1 — Design-critique + fix on the new surfaces · `claude/polish2-critique` · rung: Sonnet
A `design:design-critique`-structured pass + fixes over the surfaces built/changed after the design-polish program:
the **ACP** (member table, per-member view incl. the new F2 controls, warnings), **member-UX** (quoted-block render,
subscribe controls, excerpt rows), **tooling** (canned-reply insertion, email-template editor, analytics charts), and the
**F-changed** surfaces (mod CP, Info Center, forum rows, reports). Catch: token/spacing drift, **action-weighting**
(ban/warn/delete must read destructive — `danger`/`danger-soft`, not equal to neutral), empty/loading/skeleton states,
mobile, dark mode, consistent use of the `<x-ui.*>` library. Tokens-only; no new colours. Gate: Pint · Pest · asset-drift
(if any CSS) · the public/ACP smoke tests.

### P2 — Accessibility to WCAG 2.1 AA · `claude/polish2-a11y` · rung: Sonnet
Extend the **automated a11y page gate** (currently 27 surfaces) to every new/changed surface above, and do a manual pass:
keyboard operation of the member-table row actions, the quote button, the subscribe toggle, the report-card actions, the
Info-Center collapse, the analytics chart, and the email editor; focus order + visible focus; ARIA names; contrast of new
elements; reduced-motion; the **analytics chart keeps its data-table as the accessible equivalent**. Fix findings. Gate:
the grown a11y gate green; manual residue recorded in `docs/architecture/accessibility.md`.

---

## Land it (same run) — merge → gate → push
After every slice is green on its branch: `git switch main`; `git branch backup/pre-polish2 main`; merge `--no-ff` in the
order **F1 · F3 · F5 · F6 · F4 · F2 → P1 · P2**, resolving the additive hand-merges keeping BOTH sides (expect overlap in
the mod-CP views, the per-member view `F2×P1`, and the Info-Center/forum-row `F5×F6×P1`). `php artisan route:clear`, then
re-gate merged `main` as a **union**: Pest · Pint · PHPStan L max · migrate apply+rollback+re-apply · `npm run build` +
asset-drift · Dusk · a11y. Lift the F2 ADR (next-free). If an apex slice had an unresolved HIGH, **do NOT merge it** —
land the rest and report it. Then **push `origin main`** (fast-forward). The release-zip **deploy is the owner's only
remaining step**.

## Morning report (atop PROJECT-STATE.md, after the push)
The merged `main` HEAD + the union gate result; the branch table + per-slice gate status; the **F2 apex review** outcome
(confirm no open HIGH) + the new ADR; the a11y gate surface count before→after; every conflict resolution; anything held
back. Then "what the owner does next" — the still-pending **release-zip deploy to demo.novfora.com backup-first**
(`docs/product/live-deploy-kickoff.md`; this batch adds the F2 trust/reputation capability + audit migration).
