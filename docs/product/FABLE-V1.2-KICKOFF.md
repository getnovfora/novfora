<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# NovFora — Claude Fable Build Session: **v1.2.0** (UI-Audit + Beta bugs + Phase-6 quick wins)

> Executable session brief for an **unattended multi-hour run on the VPS** (`dev@129.121.115.222`, native
> PHP 8.3 + MySQL toolchain). Read this file **fully**, then the source docs in §0, **before writing any code.**
> You are **Claude Fable @ max** (apex rung) under the `ultracode` default — downgrade as you see fit once a
> turn is clearly pattern-replication, not correctness-load-bearing. **Fallback to Opus 4.8 `xhigh` is permitted
> ONLY if Fable genuinely cannot run in this environment** (the standing `claude-fable-5` env error) — treat it
> as a hard guardrail fallback, not a convenience. Do not pin a low rung in advance for apex-adjacent work.
>
> **This session has full git authority:** build → merge → **push `origin main`** → cut the **v1.2.0** release
> artifact. It does **NOT** deploy to any live host. See §6.

---

## 0. Read first (in this order)
1. `CLAUDE.md` (operating contract, model/effort routing) and `@docs/PROJECT-BRIEF.md`
2. `PROJECT-STATE.md` — the current handoff state. **Read the top morning report carefully** (the 2026-07-01
   Fable Phase-0 + U7 + U17 run): it records the four unmerged branches you will consolidate in §1, and it
   **REFUTES NOV-120** (see the ⚡ warning in §3).
3. `ROADMAP.md`, `docs/product/DEFINITIVE-ROADMAP-2026-06-27.md` (Tracks UA / BETA / quick wins), and
   `docs/product/ui-ux-fixes-spec.md`
4. **`UI-AUDIT-FIX-SPEC.md`** — **at the repo ROOT** (tracked + pushed; ~402 lines). Every Track-UA bug
   root-caused with `file:line`, a **locked-decisions block** (2026-06-19: BUG-002/003 = non-breaking **dual
   resolver**, no 301s; BUG-019 = **display-name editing only**, username stays read-only — so these product
   calls are settled, don't relitigate them), and a theme/slice build-order list. **This is your per-bug source
   of truth — read it in full before touching any Track-UA bug.**
5. `DECISIONS.md` — recent ADRs; the module/theme API and permission engine are semver'd public contracts.
6. Linear: team **NovFora** — https://linear.app/novfora (issue bookkeeping in §8).

---

## 1. Where the repo is, and STEP 0 (sync + verify the foundation) — DO THIS BEFORE ANYTHING ELSE

**Verified 2026-07-01 (confirm it, don't trust it — this brief may be stale by run time):**
`origin/main == main == 4da8f00`, **version 1.1.0**, working tree clean at the tracked level.
**The four prior branches are ALREADY MERGED AND PUSHED into `main`** — there is nothing left to consolidate:

```
main 4da8f00 (== origin/main, v1.1.0)   ← merge of the U7 + U17 lines; all four tips are ancestors:
  04e3ec6 baseline-green   fixed the 21 pre-existing test reds (ext-gd, NavigationManager cache, MemberDirectory 302)
  bed6f96 nov-120          the BelongsToMany fix, in the live ⚡members.blade.php (NOV-120 REFUTED — see §3)
  b193699 U7 (nov-106)     embeds (◆ APEX, ADR-0103) — review GO, 0 open HIGH/MED
  0d1eb7b U17 (nov-115)    zip-install + trust gate (◆ APEX, ADR-0104) — review GO, 0 HIGH/MED
```

ADR-0103 (U7) + ADR-0104 (U17) are already in `DECISIONS.md`. **So the v1.2 foundation already exists in `main`;
v1.2.0 will include U7 embeds + U17 zip-install by virtue of already being merged, plus everything you land below.
Do NOT re-merge or rebuild any of the four branches.**

### Step 0 — sync-and-verify-green gate (ABORT the run if it can't pass)
1. `git fetch origin`. Confirm the working tree is **clean** and local `main` **equals `origin/main`**. If the
   tree is dirty or the two have diverged in a way you cannot cleanly reconcile, **STOP and write a handoff** —
   do not force anything.
2. Re-establish the **true** current HEAD/version (the block above may be stale): if `origin/main` has moved past
   `4da8f00`, adapt — never rebuild or re-merge something already in `main`.
3. **Gate the current `main` green (§4) as-is, before building anything** — this is your known-good baseline. A
   green run confirms `baseline-green` did its job (the 21 reds are gone). If `main` is not green as-is, **STOP
   and hand off** — do not build new work on a red baseline.

**Working-tree scratch to leave alone** (owner's call — never stage): `docs/NOVFORA-multi_sonnet.md`,
`docs/NovFora_MultiAgent_Workflow.md`, `docs/product/GPT_UIUX_HANDOFF.md`, `brand/site-preview/`, `scripts/.claude/`,
any nested `novfora-docs/` repo, and the kickoff docs themselves (`docs/product/FABLE-U7-U17-KICKOFF.md`,
`docs/product/FABLE-V1.2-KICKOFF.md` — this brief). The kickoff docs are untracked notes, not code; commit them as
docs only if you want them in the release, otherwise ignore.

---

## 2. Model routing
- **`ultracode` default:** start at **Fable @ max**; downgrade to Sonnet 4.6 only for clearly pattern-replication
  view/CRUD scaffolding once the design is locked. Opus 4.8 `xhigh`/`high` is the heavy rung below the apex.
- **Fallback:** if `claude-fable-5` errors in this env, run apex work on **Opus 4.8 `xhigh`** — but only as a true
  guardrail fallback. Note in the handoff which rung actually executed the apex reviews.
- Docker/native gates are **free and deterministic** — they are the correctness signal, not the model. Prefer
  *write → run gate → read `tail -n N` → fix* over reasoning to perfection. **Never re-read a file you just edited.**

---

## 3. Working discipline (non-negotiable)
- **One independent branch per slice off the consolidated `main`.** Use the Linear branch name where an issue
  exists; otherwise `claude/v12-<slug>`. A slice is committed **only at a fully-green gate boundary**.
- **Max breadth, hard gate.** Attempt as many in-scope slices (§5) as fit the window. **A slice only counts if it
  gates fully green.** Never leave reds on a branch you intend to merge. If a slice won't gate, **park it** (below)
  rather than degrading the suite.
- **Park-on-ambiguity.** If you hit an ambiguous product/design/permission call with no one to ask, **do NOT guess
  on product intent** — skip that slice, leave a clear `TODO` + the open question in the handoff, and move to the
  next unblocked slice. Keep going; do not halt the whole run for one ambiguous item.
- **Reproduction discipline.** For each bug, implement the fix its `file:line` diagnosis prescribes **and add a
  regression test that encodes the corrected behavior** (red→green where you can reproduce it). For BETA/live-only
  bugs you **cannot** reproduce or validate with a test in this env, **park them** (per the ambiguity rule) with a
  note — do not ship an unverifiable fix.
- **Apex adversarial review — triggered by the diff, not the label.** Run the **full per-finding
  verify-then-refute** review (halt-on-open-HIGH; record HIGH/MED findings + fixes) on any slice whose diff
  actually touches: `acl_entries` / `PermissionResolver` / a rank or ceiling guard; a **presence/visibility fence**
  (`VisibleForumIds`, `OnlineMembers`, who's-online); an **untrusted-input boundary** (CAPTCHA/anti-spam,
  request-bound routing, structured-data emission that could leak private content). Slices flagged **◆** in §5 are
  the expected triggers — but verify against the actual diff. Non-seam slices get a standard regression test + gate,
  no full review.
- **Commit identity (mandatory):** author **and** committer `Tommy Huynh <tommy@saturnhq.net>`; DCO sign-off `-s`;
  **no AI co-author/attribution trailers.** Small, reviewable conventional commits (one logical change each).
  `git config user.name "Tommy Huynh"` / `git config user.email tommy@saturnhq.net` first.
- **Guardrails always on:** strict clean-room (reimplement, never copy from any reference forum); progressive
  enhancement (no Baseline feature may hard-depend on Redis / a WebSocket server / a persistent worker / an
  external search engine — detect and degrade); **reversible, non-destructive migrations** (guard `Schema::create`
  so a re-run can't 1050-stall the MySQL baseline upgrade); security-by-default; **tests with every feature.**

> **⚡ DO NOT PURGE THE `⚡`-PREFIXED ADMIN VIEWS.** The ~45 U+26A1-prefixed `.blade.php` files under
> `resources/views/components/admin/` are the **LIVE ACP** (Livewire 4 single-file components, each mapped 1:1 to a
> `<livewire:admin.*>` tag) — **NOT** a dead shadow copy. NOV-120 was **REFUTED** on 2026-07-01. Never delete them
> and never add a "non-ASCII blade filename" CI guard (it would fail every live Livewire SFC).

---

## 4. The canonical gate (run after each commit and at every branch HEAD)
`php artisan route:clear` **FIRST** (a stale `bootstrap/cache/routes-v7.php` causes false Subdir/PWA reds), then:
- `php artisan test --parallel` → **0 failed** (Dusk spec is CI-only-skipped)
- `pint --test` clean
- `phpstan`/larastan at the configured level → **0** in `app/`
- `php artisan migrate` **apply + rollback(all) + re-apply** clean (only if the slice adds a migration)
- `npm run build` OK → commit rebuilt `public/build`; **ViteManifest / asset-budget gate** green
- **a11y page gate** green (grow the surface list if the slice adds an admin/member page)
- **Dusk = CI-only** (no Chrome on the VPS): write/extend the specs and mark them **CI-pending**; do not treat a
  skipped Dusk spec as a red.

Cap all gate output (`tail -n N` / `Select-Object -Last N`).

---

## 5. Scope — build order: **bugs first, then quick wins**

**Reconcile against `main` first, for every item.** Several Track-UA bugs were "likely covered by PR #49," and
per the 2026-07-01 state **BUG-001 (P0), BETA-5, U5, and PR #49 have already shipped.** For each item below:
**verify against the consolidated `main` whether it is already fixed; if it is, SKIP it and say so explicitly in
the handoff** (the owner is reconciling PR #49 overlap by report, not by rebase). Build only what is genuinely
still broken. **Explain the PR-#49 overlap explicitly** in the handoff for BUG-007/008/015 and BUG-019.

### Track UA — UI-audit bugs (execute in this order)
| ID | Bug | Rung | Note |
|---|---|---|---|
| BUG-001 | Admin section landing bare `<svg>` (blocks admin nav) | — | **Verify DONE on main → SKIP.** |
| BUG-004/005 | Structure title `&amp;` literal + breadcrumb `Content`→`Forums` | Sonnet | |
| BUG-013/021 | Breadcrumb literal cluster — ~9 edits across 8 view files | Sonnet | |
| BUG-007/008/015 | Pluralization sweep (topics/posts/views/reports → `trans_choice`) | Sonnet | **Likely in PR #49 — verify/skip; explain overlap.** |
| BUG-011/009 | Seed-data artifacts: `post_count` backfill + importer `view_count` hardening | Sonnet | |
| BUG-012/020 | Activity-feed limit setting + `RecentActivityWidget` | Sonnet | |
| BUG-016 | Settings 10-tab wrap → left sidebar nav | Sonnet | |
| BUG-017/018 | Profile tabs (Activity/Posts/About) + collapse staff-tools | Sonnet · **◆ posts query** | The posts-visibility query is permission-scoped → ◆ on that part only. |
| BUG-019 | Display-name field (username stays read-only) | Sonnet | **Likely in PR #49 — verify/skip; explain overlap.** |
| **BUG-010** | Presence/privacy: Who's-Online badge vs count mismatch (**privacy leak**) | **◆ Opus** | Full apex review — presence/visibility fence. |
| **BUG-002/003** | Dual route binding — forum slug + user username (non-breaking, **no 301s**) | **◆ Opus** | Full apex review — request-bound routing / enumeration. |
| **BUG-014** | Draft banner on blank editor (**reproduce first**) | **◆ Opus** | Park if not reproducible in-env. |
| BUG-006 | **NOT a defect — do not touch reaction-count logic.** | — | Leave alone. |

### Track BETA — live beta-tester bugs (reproduce-with-a-test-or-park)
| ID | Bug | Rung | Note |
|---|---|---|---|
| **BETA-1** | Notification read-state doesn't auto-update (topic #31) | **◆ Opus** | Livewire reactivity; add a feature test that proves the read-state transition. |
| BETA-2 | Mobile portrait nav spillover / second row (regression) | Sonnet | CSS/layout; verify at **390px** (Dusk CI) + a markup regression assertion. |
| **BETA-3** | DM 403 error | **◆ Opus** | Permission/policy seam → full apex review; distinguish a real gate from a ghost/misapplied one. |
| **BETA-4** | Lock-thread action shown to users without permission (ghost UI) | **◆ Opus** | Gate seam → full apex review; the action must show / show-disabled / hide per the real capability. |
| BETA-5 | Scheduled-reply error | — | **Verify DONE (NOV-89 / PR #51) → SKIP.** |

### Phase-6 quick wins (build AFTER the bugs)
- **U8 — username history + revert.** Store prior usernames + an admin revert path. Reversible migration.
- **U18 — finish CAPTCHA drivers (hCaptcha / reCAPTCHA) + Gravatar.** The driver-verification path is an
  **untrusted-input boundary → ◆** (verify the provider token server-side, fail-closed, no secret leak; keep the
  existing null/DB driver as the Baseline default — progressive enhancement).
- **U20 — SEO polish:** JSON-LD / OpenGraph tags, social-share metadata, "find content by user." **Light privacy
  check (◆-lite):** structured data and OG tags must **never** emit content from a hidden/private forum or a club
  the guest can't see — reuse the existing visibility fence; add a test that a private topic yields no JSON-LD leak.

Each quick win records an ADR in `DECISIONS.md` (confirm the next-free number before writing).

---

## 6. Release — merge → push → cut **v1.2.0** (artifact only; no live deploy)
Once the in-scope slices are built and each is green on its own branch:
1. **Merge** the green slices into `main` in a sensible dependency order; **re-gate the union green** (§4).
   **Only merged-green slices go into the release** — parked/ambiguous slices are excluded and listed in the
   handoff for a later cycle. The release always reflects a green `main`.
2. **Version bump → `1.2.0`** (update the version constant/source of truth + `CHANGELOG.md` with the landed
   slices, grouped UA / BETA / quick wins / U7+U17). Lift any PROPOSED ADRs referenced by landed slices.
3. **Push `origin main`** (fast-forward). This session's git mandate ends at a green, pushed `main`.
4. **Cut the release artifact:** `scripts/build-release.sh` → `scripts/verify-release.sh`; **tag `v1.2.0`.**
5. **STOP before any live host.** Do **NOT** deploy to demo.novfora.com or production — leave deployment to the
   owner. Note in the handoff that recent batches carry migrations, so the owner's path is the **cron
   auto-upgrade** (backup-first), not assets-only.

If **no** net-new quick-win feature lands green (only fixes), keep the version at **1.2.0** anyway — the owner
requested v1.2 explicitly.

---

## 7. Definition of done (per slice)
Gate green · regression test present · apex review clean where the diff touches a seam (§3) · ADR recorded where
the slice warrants one · already-fixed items verified-and-skipped-with-a-note · branch merged into a green `main`.

## 8. Handoff & bookkeeping
- **Always** write a full **morning-report handoff to the top of `PROJECT-STATE.md`** in the established style:
  branch/merge topology, the union gate result, per-slice table (ID · branch · head · gate · notes), every
  **apex review outcome** (findings + fixes), every **verified-and-skipped** item (with the PR-#49 overlap
  explained explicitly), every **parked** item (with its open question), the **v1.2.0 release** result
  (build/verify/tag), and a crisp **"what the owner does next"** (deploy demo backup-first; anything left parked).
- **Attempt Linear** issue moves (In Progress at start → Done on merge) for every item that maps to an issue —
  but **do not fail the run** if the auto-mode classifier blocks a write; **log exactly what could not be set** so
  the owner can finish the board by hand.
- Update `ROADMAP.md` notes where a landed slice changes the roadmap picture.

---

### One-paragraph summary for the operator
Run unattended on the VPS. **Step 0:** fetch, verify a clean tree with `main == origin/main` (`4da8f00`, v1.1.0),
and gate the current `main` green as-is — the four prior branches (`baseline-green → nov-120 → U7 → U17`) are
**already merged**, so the v1.2 foundation exists; do not re-merge (abort only if `main` won't gate green). Then,
**bugs first**, work the still-broken Track-UA and Track-BETA items (verify each against
`main`, skip what PR #49 / prior sessions already fixed and say so), then the **U8 / U18 / U20** quick wins — one
green branch per slice, max breadth, park anything ambiguous or unreproducible. Full apex verify-then-refute review
only where a diff touches a permission mask, a presence/visibility fence, or an untrusted-input boundary. Merge the
green slices, **bump to 1.2.0**, push `origin main`, and cut the **v1.2.0** release artifact (build + verify + tag)
— **but do not deploy to any live host.** Write the PROJECT-STATE morning report; attempt Linear, log what's
blocked. Commit as `Tommy Huynh <tommy@saturnhq.net>`, DCO `-s`, no AI trailers.
