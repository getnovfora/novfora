<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# Design-Polish Program — EXECUTABLE KICKOFF (for Claude Code, run cold)

> **What this is.** A self-contained handoff Code can execute without re-deriving context. It turns the
> [Design-Polish Program](../design-polish-program-2026-06-22.md) into **sequenced, gated, branch-per-slice work**.
> Context (the *why*) is in that doc + [`audit-ips-gap-analysis-2026-06-22.md`](../audit-ips-gap-analysis-2026-06-22.md) —
> **read those once, don't restate them in commits.**
>
> **Prime directive:** every slice ends **runnable + green on the Baseline tier** (PHP 8.3 + MySQL + cron), one logical
> change per commit, **nothing pushed** (owner pushes). Form is a tracked deliverable here — a slice isn't done until it
> also *looks* right (the per-slice acceptance criteria include visual + a11y, not just passing tests).

---

## How to use this doc
- **One slice = one branch off `main` = one PR.** Branch names are given per slice. Slices are ordered by dependency
  (§Sequence); within a slice, commit at each green boundary.
- **Stop-for-approval gates:** Slice 2 (apex, untrusted upload) and any new public contract (the component API, the
  attachment schema) get an ADR + the owner's review before merge. Don't self-merge.
- If a step's reality differs from this spec (a file moved, infra already exists), **prefer the codebase** and note the
  deviation in the PR — this spec is a plan, not ground truth frozen in amber.

## Unattended session — run protocol (for one multi-hour autonomous Code run)
**Goal:** bank as much of the program as possible in a single long session — each slice an **independent branch off
`main`**, gated, committed at green, **nothing pushed or merged** (the owner reviews + merges). This mirrors the
PROJECT-STATE overnight-build discipline. **This order supersedes the team-oriented §Sequence diagram for an unattended run.**

**Pre-flight (once):**
- `git switch main && git pull`; clean working tree; `git config user.name "Tommy Huynh"` + `git config user.email tommy@saturnhq.net`.
- Confirm the gate toolchain is reachable: `forum-dev` Docker **or** the VPS native PHP 8.3 + Composer, plus `npm` for the asset build.
- Confirm next-free ADR numbers vs `DECISIONS.md` (expect 0092/0093 free per `design-polish-adrs-DRAFT.md`) — **don't lift them**; they ride the owner's review.
- Read once, don't restate: this doc, `design-polish-program-2026-06-22.md`, `audit-ips-gap-analysis-2026-06-22.md`, `CLAUDE.md` routing.
- **Already staged in the tree (verify, don't re-author):** the M0 CSS fix (`app.css`) and `components/ui/table.blade.php` + `skeleton.blade.php`.

**Per-slice loop (green-boundary discipline):**
1. `git switch -c <slice-branch> main` — **each slice branches off `main`, independent**, so a RED in one never blocks another and the owner merges in any order.
2. Build in small commits; at each boundary run the gate set, `tail` the output, fix forward. **Commit only at full green** — DCO `-s`, conventional subject, `Tommy Huynh` identity, **no AI trailers**.
3. Slice done = every acceptance criterion met + gates green. Record a one-line result. Next slice.
4. **Never merge, never push.** Leave each branch local.

**Gate set (run in `forum-dev`/VPS; cap output with `tail`/`Select-Object -Last N`):** `php artisan test --parallel` ·
`pint` · `phpstan` L max (app/) · `migrate` apply+rollback+re-apply (only if the slice adds a migration) · `npm run build`
+ asset-drift · **Dusk** (editor/topic slices) · the **a11y page gate** (any front-end/ACP surface).

**Order — bank safe value first, the apex slice last:**

| Run | Slice | Branch | Autonomy |
|---|---|---|---|
| 1 | 0 · M0 scroll-trap | `claude/polish-m0-prose-scope` | **Autonomous** — fast; proves the staged CSS + rebuild path |
| 2 | 1 · design-system (gate the 2 staged components + motion tokens + docs) | `claude/polish-p1-design-system` | **Autonomous** — unblocks 4 & 5 |
| 3 | 3 · editor toolbar + schema | `claude/polish-p4-toolbar` | **Autonomous** — non-apex; the *Attach* control **feature-detects** Slice 2's endpoint and stays hidden if absent, so NO hard dependency |
| 4 | 4 · ACP navigability + polish | `claude/polish-p2-acp` | **Autonomous** — consumes `x-ui.table` |
| 5 | 5 · member-experience polish | `claude/polish-p3-member` | **Autonomous** — consumes the components |
| 6 | 2 · editor attachments | `claude/polish-p4-attachments` | **PAUSE-FOR-REVIEW** — APEX untrusted input: build it, run the **mandated in-session adversarial verify-then-refute**, commit on the branch, **do NOT merge**; **HALT + report if any HIGH finding is unresolved** |

**RED / stop handling:** if a gate stays red after reasonable fix attempts, **stop that slice**, leave it WIP on its branch
with a one-line note, and move to the next independent slice — one failure must not burn the session. **Hard stop + write
the report** on: an unresolved **HIGH** on Slice 2, or a failing `migrate` rollback (data-safety).

**Morning report (write at session end, PROJECT-STATE style):** a table of branches built + per-branch gate status; ADRs
**proposed** (0092/0093, not lifted); the Slice-2 apex-review outcome (candidates → fixed/refuted); anything halted or
flagged; and **"what the owner does next"** (review, push, suggested merge order — independent, any order). Drop it as a new
dated block at the top of `PROJECT-STATE.md`.

## Standing rules (apply to EVERY slice — do not restate per commit)
- **Commit identity (mandatory):** author **and** committer `Tommy Huynh <tommy@saturnhq.net>`; conventional-commit
  subjects; **DCO sign-off (`-s`)**; **never** add AI co-author/attribution trailers (`.claude/settings.json` keeps them
  off — don't reintroduce).
- **Gates (run in `forum-dev` Docker or the VPS native toolchain; cap output with `tail`/`Select-Object -Last N`):**
  `php artisan test --parallel` (Pest) · `./vendor/bin/pint` · `phpstan`/Larastan **L max (app/)** · `php artisan migrate`
  **apply + rollback + re-apply** · `npm run build` then the **asset-drift gate** (the CI `assets` job — a CSS/JS source
  change that isn't rebuilt fails it) · **Dusk** where a slice touches an editor/topic browser path · the **automated
  a11y page gate** for any new/changed front-end or ACP surface. Commit only at a fully green boundary.
- **Progressive enhancement (hard rule):** no Baseline feature may hard-depend on Redis / a queue worker / Reverb /
  Meilisearch / S3. Detect + degrade. (Editor attachments: local disk on Baseline; S3/MinIO only on Enhanced.)
- **Reversible, non-destructive migrations** (`down()` proven by the apply→rollback→re-apply gate).
- **Clean-room (hard rule):** independently designed. Study what capable software *does*; never copy markup, CSS,
  themes, or assets from IPS/XenForo/any reference forum.
- **Tokens only:** no hard-coded colours/spacing — use the semantic tokens in `resources/css/app.css`
  (`bg-surface`/`text-ink`/`border-line`/`accent`/`danger-soft`…). Honour dark mode + density automatically (don't add
  `dark:` variants unless truly one-off).
- **Model rung (CLAUDE.md `ultracode`):** start apex, downgrade when a turn is pattern-replication. Per-slice floors are
  given below. The **editor upload path is apex (Fable @ max)** — untrusted input.

## ADR allocation
Parent **ADR-0092 — Design-Polish Program** (confirm it's the next-free number before lifting into `DECISIONS.md` — last
seen was 0091; apply the RH-4 "don't collide" check). Children: **ADR-0093** the attachment subsystem (apex). M0 is a pure
bugfix → **no ADR**, just a `CHANGELOG.md` line. Slice 1's component API is a public contract → fold its conventions into
ADR-0092 or a child as Code sees fit.

---

## Sequence & dependencies

```
Slice 0  M0 scroll-trap hotfix        ── ship first, standalone (source already staged)
              │
Slice 1  Design-system foundation     ── unblocks 4 & 5 (table, states, motion)
              ├──────────────► Slice 4  ACP navigability + polish
              └──────────────► Slice 5  Member-experience polish
Slice 2  Editor attachments (APEX)    ── parallel to 1; the flagship's load-bearing half
              │
Slice 3  Editor toolbar + schema       ── after 1 (uses menus/components) & 2 (attach button lives in the Insert menu)
```
Recommended order: **0 → (1 ∥ 2) → 3 → (4 ∥ 5)**. Highest visible payoff first: 0, then 2.

---

## Slice 0 — M0 scroll-trap hotfix  ·  branch `claude/polish-m0-prose-scope`  ·  rung: Haiku/Sonnet
**Why:** the audit's worst-rated defect. The editor box's height cap rode the shared `.novfora-prose` class onto rendered
posts, trapping long posts in an inner 28rem scroller (and forcing a dead 8rem min-height on short posts).

**Status:** the **CSS source change is already staged in the Cowork tree** (`resources/css/app.css`, the `.novfora-prose`
block — box constraints moved to `.novfora-editor .novfora-prose`). Treat this slice as **verify → rebuild → gate**, not
re-author. The diff, for idempotency:
```css
/* .novfora-prose now holds typography only: */
.novfora-prose { padding: 0.8rem 1rem; outline: none; line-height: 1.65; color: var(--ink); }
/* box constraints scoped to the editor: */
.novfora-editor .novfora-prose { min-height: 8rem; max-height: 28rem; overflow-y: auto; }
```
**Steps:** (1) confirm the change is present; (2) `npm run build` to regenerate `build/` (or the asset-drift gate fails);
(3) gates: Pint, Pest, **Dusk `EditorJourneyTest`** (the editor still scrolls at 28rem; the editable still has
`.novfora-prose`), asset-drift. (4) `CHANGELOG.md` line under a new "Unreleased / 1.0.x" fixes heading.
**Acceptance:** a 60-line post renders at full height with the *page* scrolling (no inner scrollbar); a 1-line post has no
8rem dead space; the **editor** textbox still caps at 28rem and scrolls internally; `EditorJourneyTest` green.

---

## Slice 1 — Design-system foundation (Pillar 1)  ·  branch `claude/polish-p1-design-system`  ·  rung: Sonnet
**Why:** make polish reusable so 4 & 5 don't reinvent it. The token system already exists — this **matures it into a
documented, gap-filled `<x-ui.*>` library**.

**Inventory (2026-06-22 — done):** `resources/views/components/ui/` already ships `alert` (inline/toast),
`dropdown`+`dropdown-item`, `modal`, `tabs`, `empty`, `field`, `avatar`, `badge`, `breadcrumbs`, `button`, `card`,
`container`, `icon`, `input`/`select`/`textarea`/`toggle`, `online-badge`, `staff-flair`, `user-name`. The only
genuinely-missing primitives are **`x-ui.table`** and **`x-ui.skeleton`** — so this slice is small and 4 & 5 mostly
*reuse* what exists (no need to build dropdown/modal/tabs/alert/empty).
**Scope — DO:** (a) **gate + refine the two pre-built components** already staged in the Cowork tree —
`components/ui/table.blade.php` (responsive shell, sticky-head option, density-aware cell padding via `[&_th]`/`[&_td]`
arbitrary variants, `head` slot, hover rows; sort/pagination driven by the host) and `components/ui/skeleton.blade.php`
(motion-safe pulse bars, `aria-hidden`, `lines` prop). Add a render test each and **confirm the arbitrary-variant cell
padding compiles under the `source(none)` + `@source '../views'` build** — if the scanner misses `[&_td]:px-3`, fall back
to a small `.ui-table` block in `app.css` mirroring the existing `.novfora-prose` table rules. (b) Add **motion tokens**
to `app.css` (`--dur-*`/`--ease-*` + a couple of transition utilities) respecting the existing `prefers-reduced-motion`
block. (c) **Audit + document** the existing components' variants/states in `docs/THEME-API.md` and fill **empty/loading/
error** gaps (wire `x-ui.skeleton` into list/table loading paths; standardise `x-ui.empty`).
**Scope — DON'T:** no business logic; no data fetching; these are presentational. No new colours (tokens only).
**Files:** `resources/views/components/ui/*.blade.php` (new), `resources/css/app.css` (motion tokens + any component
classes, after the existing token blocks), `docs/THEME-API.md` (document the component contract + states).
**Tests/gates:** a render test per component (`tests/Feature/Ui/*` — renders, variants, `x-cloak`/aria present, empty/loading
states); a11y page gate on a demo/storybook route if one is added; Pint/PHPStan/Pest/asset-drift.
**Acceptance:** each component documented with variants + **empty/loading/error** states; `x-ui.table` demonstrably
sortable + density-aware + dark-mode-correct; reduced-motion honoured; no hard-coded colours (grep clean).

---

## Slice 2 — Editor attachments (Pillar 4 flagship · **APEX, untrusted input**)  ·  branch `claude/polish-p4-attachments`  ·  rung: **Fable @ max**
**Why:** the screenshot's centrepiece — a real drag-and-drop, multi-file attach zone with a max-size readout — is the
single most visible richness gap. The **backend is an untrusted-file boundary** and gets the apex treatment.

**Step 1 — inventory before building.** The composer already has an image-only seam: `content-editor.blade.php`'s
`uploadUrl` + the single `<input accept="image/*">`, and `novfora-editor.js` `handlePaste`/`handleDrop` (lines ~256–264,
`imageFiles()` only). P5.1 also references existing post-attachment handling (the soft-deleted-post download gate, importer
path-traversal fix). **Find and EXTEND that infrastructure — do not duplicate it.** Map: the current upload controller/route
behind `uploadUrl`, any `Attachment` model/table, the existing serve/visibility gate.

**Step 2 — backend (apex).**
- **Schema (reversible migration):** an `attachments` table if none exists — `user_id`, polymorphic/`post_id` + a
  pre-publish `draft_token`, `disk`, `path` (random stored name), `original_name`, `mime`, `size`, `checksum`, timestamps;
  index `(draft_token)`, `(post_id)`.
- **Upload endpoint** (`auth`+`verified`, permission-gated, **`throttle`**): validate **size** (config cap surfaced to the
  UI), **extension allowlist + MIME sniff** (`finfo`) with mismatch rejection; **images** → re-encode + strip EXIF + clamp
  dimensions; **non-images** → store inert, force safe `Content-Disposition: attachment`, never an active content-type;
  **filename sanitised** (random stored name, reject `..`/schemes — mirror the importer fix); write to the **configured
  disk** (`local` Baseline / `s3` Enhanced); enforce a **per-post total-size cap**.
- **Serve endpoint:** authorize on the parent content's visibility (**private-club no-leak!**), mirror the **soft-deleted
  gate** (uploader/moderator only — the P5.1 precedent), signed/short-lived where appropriate.
- **Lifecycle:** draft attachments keyed by `draft_token`; associate to the post on publish; **orphan-prune cron**
  (`novfora:attachments:prune`, short-mutex, restore-skipped — the cron-baseline discipline).
**Step 3 — front-end drop zone (rung: Sonnet, but gated behind the apex backend).** A visible zone under the editor (multi-
file, progress, list with remove, **max-size readout**, click-to-browse fallback); generalise `handleDrop`/`handlePaste`
to all files (not just images) → POST to the endpoint; insert an **image node** for images and a **file-card node** for
others (extend the `EmbedNode`/`SpoilerNode` custom-node pattern in `novfora-editor.js`); render via `CanonicalRenderer` +
the post-HTML sanitise allowlist.
**Mandated review:** a **per-finding adversarial verify-then-refute** pass over the upload+serve surface BEFORE commit
(MIME/extension confusion, SVG/script, zip-bomb/size, path traversal, IDOR on serve, private-club leak, unauth, rate-limit
bypass, draft-token forgery). Record it like the P5.1 / RH-4 reviews.
**Tests/gates:** feature tests for the happy path **and every security case above**; tier-fallback test (S3 mocked vs local);
Dusk for the drop interaction; migrate apply/rollback/re-apply; Pint/PHPStan/Pest/asset-drift. **ADR-0093.**
**Acceptance:** on Baseline (local disk, no daemon) a member drags 3 mixed files in, sees progress + a max-size readout,
publishes, and they render + download safely; a non-member cannot fetch a private-club attachment; oversize/bad-MIME/
traversal/unauth/rate cases all rejected with tests; orphaned drafts pruned by cron.

---

## Slice 3 — Editor toolbar architecture + schema exposure (Pillar 4 cont.)  ·  branch `claude/polish-p4-toolbar`  ·  rung: Sonnet (Opus for paste/canonical edges)
**Why:** add incumbent-level richness **without sprawl** — power via two tidy menus, keeping the keyboard-first identity.
**Scope:** restructure `content-editor.blade.php`'s flat toolbar into grouped marks + a **"Text style"** menu (paragraph /
**H1–H3** / quote) + an **"Insert"** menu (link / image / **table** / embed / spoiler / hr / *attach* — the Slice-2 button
lands here). Expose schema TipTap already loads (`TableKit`, headings beyond H2, `hr`, `EmbedNode`, `SpoilerNode`). Add an
**emoji picker** (reuse the reaction set). Add **visible `title` tooltips** atop the existing `aria-label`s; a proper
**link dialog**; **smart paste** (URL→oEmbed facade, image→upload via Slice 2); **mobile overflow** into a `…` menu.
**Keep (do not regress):** canonical-JSON sync, `wire:ignore` island, draft autosave, slash-commands, `@`-mentions,
Markdown toggle, the **`.novfora-prose` Dusk contract**.
**Tests/gates:** extend `EditorJourneyTest` (each new command round-trips to canonical JSON + renders sanitised); roving-
tabindex toolbar a11y; Pint/PHPStan/Pest/Dusk/asset-drift.
**Acceptance:** every new control keyboard-operable with a visible tooltip; tables/H1–H3/hr round-trip; paste of a YouTube
URL yields the embed facade; toolbar collapses cleanly on mobile; canonical-JSON + draft autosave unchanged.

---

## Slice 4 — ACP navigability + polish (Pillar 2)  ·  branch `claude/polish-p2-acp`  ·  rung: Sonnet
**Why:** the IA is already Invision-style (ACP v3-h) — this is the **feel** pass so a new admin moves as easily, cleaner.
**Scope:** a **persistent sidebar shell** so section switches don't reflow the frame (the audit's "feels unstable"); apply
**`x-ui.table`** (Slice 1) as the standard ACP data-table — it lands with the **member table (gap A1, ACP v4)**; consistent
**breadcrumbs**; a more prominent **global ACP search** affordance; **quick-links / recents** in the rail or top bar; one
**form-layout system** (label/help/required/validation/grouping) applied across the settings SFCs.
**Scope — DON'T:** no permission-model changes (that's ACP v4 functional). Pure shell/presentation; if a surface needs new
data it's a reference to the functional milestone, not done here.
**Files:** the admin layout/shell view + `AdminNavigation` (rail/sidebar/search source), `SectionController` + the shared
`admin.section` card-grid view, the settings SFCs under `resources/views/components/admin/**`.
**Tests/gates:** the a11y page gate across touched ACP surfaces; a nav-integrity test (every rail/sidebar entry resolves —
extends the existing `Route::has` gating); Pint/PHPStan/Pest/asset-drift.
**Acceptance:** moving between sections keeps the shell fixed (no flash/reflow); the data-table renders sorted/filtered/
dense; quick-links present; settings pages share one form rhythm; keyboard-navigable; dark + density correct.

---

## Slice 5 — Member-experience polish (Pillar 3)  ·  branch `claude/polish-p3-member`  ·  rung: Sonnet (Opus where read-state writes)
**Why:** bring the everyday member surfaces to incumbent polish.
**Scope:** post-card **action hierarchy** — de-weight `Delete` (use `danger-soft`, not equal weight to `Edit`) in
`topic.blade.php`'s footer; reaction-row micro-interactions; **topic-list excerpt + unread/read state** (co-delivered with
functional M2/M3 — `TopicRead` exists; render an unread affordance in `forum/show.blade.php`); **notification dropdown**
polish (functional M4 — the bell at `components/⚡notification-bell.blade.php`); **profile + member-directory** card/hero
refinement + skeletons; mobile + touch-target pass.
**Boundary:** the *functional* engines for quote-reply (M1), topic-subscribe (M5), and the unread query belong to the **1.1
member-UX milestone**; this slice owns their **form** and co-delivers the small ones. Don't build the subscription model here.
**Tests/gates:** a11y page gate on topic/list/profile/notifications; Dusk for the post-action + dropdown; Pint/PHPStan/Pest/
asset-drift.
**Acceptance:** `Delete` reads as secondary/destructive (not equal to `Edit`); topic rows show an excerpt + unread state;
the bell opens a polished dropdown; profile/directory have skeletons; everything dark + density + reduced-motion correct.

---

## Boundary with the functional milestones (gap-analysis §3)
This program owns **form**; the **function** rides the 1.1/1.2/1.3 milestones. Co-delivered (form+function inseparable):
M0, the editor (Slices 2–3), post-action hierarchy, topic-list excerpt/unread, notification dropdown. **Referenced, not
owned here:** the ACP member table data layer (A1), per-member admin view (A2), topic subscriptions (M5), quote-reply
engine (M1) — those land in **ACP v4 / 1.1** and *consume* this program's components (`x-ui.table`, the dropdown, the
editor). Keep the seams clean so they slot together.

## Program definition of done
Per `design-polish-program-2026-06-22.md` §5: documented components with full state coverage; a11y gate green on touched
surfaces; the editor's attachment path has passing upload-security tests + the apex review on record; **no `.novfora-prose`-
style class leaks** (editor-only styling scoped to `.novfora-editor`); dark + density honoured everywhere; assets rebuilt
(no drift). Owner pushes; nothing auto-merged.
