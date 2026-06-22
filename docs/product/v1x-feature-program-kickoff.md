<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# NovFora v1.x Feature Program — EXECUTABLE KICKOFF (for Claude Code, run cold, unattended)

> **What this is.** The next multi-hour unattended program after the Design-Polish run. It builds the **functional
> backlog the polish deferred**, in four tracks: **S** stabilize → **A** ACP v4 member management → **M** member-UX
> functional → **T** admin content tooling. Same discipline that worked for design-polish: **independent branch per
> slice off `main`, gated green, committed at a green boundary, nothing pushed/merged** (owner reviews + merges).
>
> **Provenance of the backlog:** `docs/product/audit-ips-gap-analysis-2026-06-22.md` (the gap map + code anchors) and
> `docs/product/design-polish-kickoff.md` (which deferred these as "functional, not owned here"). Read both once.
>
> **VERIFY-FIRST (critical):** the code anchors below were captured **before** the polish run. Polish slices 4 & 5
> built the `x-ui.table`, the ACP shell, and *some* member-UX form (unread indicator / excerpt / notification dropdown).
> **Each slice's Step 1 is: inventory current `main` and build only the genuine gap** — if a thing already exists, gate
> it and move on. Prefer the codebase over this spec; flag deviations in the commit + report (exactly as the polish run did).

---

## Run protocol (unattended) — same as the polish run
Full version: `design-polish-kickoff.md` §"Unattended session — run protocol". Essentials:

- **Pre-flight:** `git switch main && git pull`; clean tree; `git config user.name "Tommy Huynh"` + `…email tommy@saturnhq.net`; confirm the `forum-dev`/VPS gate toolchain + `npm`; confirm next-free ADR numbers (expect **0095+** — 0093/0094 are the polish program + attachments).
- **First commit (Track R1):** prepend this block to the TOP of `PROJECT-STATE.md` on canonical `main`, so a cold session self-discovers the program:
  > `## ▶ ACTIVE TASK (unattended) — v1.x Feature Program — 2026-06-22`
  > Execute `docs/product/v1x-feature-program-kickoff.md` end-to-end in order (**R → S → A → M → T**). Independent branch per slice off `main`, gated, committed at a green boundary, **nothing pushed/merged** (owner reviews). Verify each slice against current `main` first and prefer the codebase. Apex slices (A1/A2 member data + ban/warn, M2 subscription fan-out, T2 email-template render) get an in-session adversarial verify-then-refute review — **HALT + report on any unresolved HIGH**. Write the morning report back to `PROJECT-STATE.md` top when done.
- **Per slice:** `git switch -c <branch> main` (independent — a RED in one never blocks another); small commits; at each boundary run the gate set, `tail` it, fix forward; **commit only at full green** (DCO `-s`, conventional, `Tommy Huynh`, no AI trailers). Never merge, never push.
- **Gate set (forum-dev/VPS, cap output):** `php artisan test --parallel` · `pint` · `phpstan` L max (app/) · `migrate` apply+rollback+re-apply (slices with a migration) · `npm run build` + asset-drift (only slices touching CSS/JS — most here are PHP/Blade and need no rebuild) · **Dusk** (composer/topic/ACP browser paths) · the **a11y page gate** (new front-end/ACP surfaces).
- **Apex slices** (flagged ⚠ below) get an **in-session adversarial verify-then-refute** review before commit, recorded like P5.1; **HALT + report on an unresolved HIGH**.
- **RED/stop:** stuck gate → leave the slice WIP, note it, move to the next independent slice. Hard stop on an unresolved HIGH or a failing `migrate` rollback.
- **End:** write the **morning report** (branch table + gate status, ADRs proposed, apex-review outcomes, anything halted, "what the owner does next") to the top of `PROJECT-STATE.md`.

## Standing rules
As `design-polish-kickoff.md` §"Standing rules": baseline-runnable every slice; **progressive enhancement** (no Redis/queue/Reverb/Meili/S3 hard-dep — detect + degrade); reversible migrations; **tokens-only** UI (auto dark + density), reuse the `<x-ui.*>` library incl. the new `table`/`skeleton`; **clean-room**; tests with every feature (permission/fan-out/visibility paths get dedicated tests).

## ADR allocation
Parent **ADR-0095 — v1.x Feature Program** (confirm next-free first). Children as warranted: **0096** ACP v4 member-management surface (apex), **0097** topic/forum subscriptions (the notification fan-out boundary), **0098** canned replies, **0099** admin-editable email templates (the render-sanitisation boundary). Propose, don't lift until owner review.

---

## Sequence

```
Track R (hygiene)    ── run FIRST; trim PROJECT-STATE/docs for tokens + prune spent artifacts (reversible)
Track S (stabilize)  ── S-validate items are owner/live; the code fixes are autonomous
        │
Track A (ACP v4)     A1 member table → A2 per-member view → A3 warnings-in-ACP        ⚠ apex
Track M (member-UX)  M1 quote-reply · M2 subscriptions(⚠ fan-out) · M3 unread/excerpt/slug finish
Track T (tooling)    T1 canned replies · T2 email templates(⚠ sanitise) · T3 analytics charts
```
Order: **R (hygiene, first) → S (code fixes) → A1→A2→A3 → M1 → M2 → M3 → T1 → T3 → T2**. R/S/A/M/T are independent branches; A & M may interleave; T last. Highest leverage: **A1 member table** (audits' #1 admin gap, table ready) and **M1 quote-reply** (audits' "essential").

---

## Track R — Repo hygiene & token efficiency (run FIRST; reversible, decision-preserving)
**Why:** the docs that load every session have grown heavy — `PROJECT-STATE.md` is ~1.1k lines — so every Code/Cowork
start burns context re-reading spent history. Trim the session-loaded surface and prune spent artifacts **without losing
any decision** (every ADR, security-review writeup, migration, and the VALIDATE-BEFORE-GO-LIVE list stays; all moves are
git-reversible).

### R1 — Trim the session-loaded docs · `claude/v1x-r1-doc-trim` · rung: Sonnet
- **`PROJECT-STATE.md`:** move every COMPLETED milestone block below the current state into `PROJECT-HISTORY.md` (the
  existing pattern), leaving PROJECT-STATE = {the ACTIVE TASK trigger (above) · the latest run · the
  VALIDATE-BEFORE-GO-LIVE list · open follow-ups}. Goal: a session reads it in seconds.
- Prepend the **ACTIVE TASK trigger** (pre-flight block) to the top.
- Tighten `CLAUDE.md` / `ROADMAP.md` dead weight (retired-codename prose, superseded notes) — but keep **every locked
  decision + the model-routing rules verbatim in intent**.
- Gate: all internal doc links resolve (nothing points at a moved/again-missing file); Pint/markdown clean; the full
  suite is untouched (docs-only).

### R2 — Prune spent artifacts · `claude/v1x-r2-prune` · rung: Sonnet
- After S3 lifts the polish ADRs into `DECISIONS.md`, **delete** `docs/product/design-polish-adrs-DRAFT.md`.
- Move spent `docs/product/*-kickoff.md` (completed runs) into `docs/product/archive/` (move, don't delete — keep the
  record) with a one-line index of what shipped from each.
- **Security:** verify the RH-4-flagged foreign WIP is gone — `app/Http/Controllers/ImportForumSeedController.php`,
  `app/Console/Commands/ImportForumSeedCommand.php`, and the **unauthenticated `/forums/import-seed` routes**. If still
  present, remove or gate them (it was flagged as a possible unauthenticated upload endpoint).
- Confirm `/*.zip` + build artifacts stay gitignored; no stray committed binaries or `.env*`.
- **Never delete** an ADR, a security finding, a migration, or the validate-list.
- Gate: full suite green (prove nothing referenced the removed WIP); `migrate` clean.

## Track S — Stabilize the polish

### S1 — Attachments hardening follow-through · `claude/v1x-s1-attach-hardening` · ⚠ apex-adjacent · rung: Opus xhigh
**Verify-first:** read the merged Slice-2 attachment code + its apex-review notes. **Do:** close the recorded **LOW scheduled-post orphan-prune** (a scheduled/draft post that never publishes must not strand its attachments — extend `novfora:attachments:prune` to cover it, with a test); add `SCOUT_QUEUE`-style **graceful-degrade on a storage outage** if the upload path can throw inline. **Don't:** re-architect the subsystem. **Owner/live (flag, not autonomous):** the end-to-end live-host upload validation (real file up/down, private-club no-leak, oversize/MIME/traversal) belongs to the deploy validation, not this branch. **Gate:** Pest (prune + degrade cases) + migrate if touched.

### S2 — Polish Dusk specs in CI · `claude/v1x-s2-dusk-ci` · rung: Sonnet
**Verify-first:** the polish run wrote/extended Dusk specs but couldn't run them (no CI Chrome). **Do:** ensure the editor-toolbar, attachment drop-zone, and topic-render (M0 no-scroll-trap) Dusk specs run green in the CI Chrome lane; fix any that only ever compiled. **Gate:** Dusk green in CI + the existing suite.

### S3 — Lift the ADRs · `claude/v1x-s3-adrs` · rung: Haiku/Sonnet
Lift the design-polish ADRs into `DECISIONS.md` **renumbered** (program **0093**, attachments **0094**) and correct `docs/product/design-polish-adrs-DRAFT.md` (it still says 0092/0093). Docs-only; no gate beyond Pint/markdown. *(R2 deletes the draft after this lift.)*

### S4 — ACP groups action-icon consistency · `claude/v1x-s4-groups-icons` · rung: Sonnet
**Why:** in the `/admin/groups/manage` actions column the **Clone** action renders as a text button while its siblings
are icons, and the Administrators row's add-members control renders as a wide labeled `subtle` button instead of the
compact icon every other row uses — inconsistent + wraps oddly. (Supersedes the standalone one-line handoff.)
**Verify-first:** check `resources/views/components/admin/⚡groups.blade.php` on current `main` — if a polish slice
already iconified these, gate + skip.
**Do (Blade-only, no asset rebuild):**
- Add a `copy` glyph to `resources/views/components/ui/icon.blade.php`:
  `'copy' => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',`
- Turn the **Clone** text button into an icon button (`size="sm" icon` + `<x-ui.icon name="copy" class="h-4 w-4"/>`),
  keeping its `title` and `acp-group-clone-*` dusk hook.
- Collapse the `$g->slug === 'admins'` labeled `subtle` button into the same icon-only `users` `ghost` button as the
  other rows — preserve the `acp-admins-members` dusk hook and a descriptive `title` ("Add or remove administrators" for
  admins, else "Add or remove members").
**Gate:** Pint; **update any Dusk test** that asserted the old "Add / manage members" label or `subtle` variant (the
click selectors are unchanged). **Acceptance:** the ACTIONS column is all consistent icon buttons; labels show on hover
via `title`; the column no longer wraps.

---

## Track A — ACP v4: Member management ⚠ (apex — member PII, ban/warn, rank/permission guards)

### A1 — In-admin member directory/table · `claude/v1x-a1-member-table` · ⚠ apex · rung: Fable @ max
**Why:** the audits' single largest admin gap. Today `/admin/members/directory` is only a *visibility setting* (`⚡members-directory.blade.php`); there's no member data table in the ACP.
**Verify-first:** confirm the Slice-4 `x-ui.table` + ACP shell exist; build the directory ON them.
**Do:** an `/admin/members` (route `admin.members.*`) **server-paginated, sortable, filterable** table — columns username · email · joined · primary group/role · trust level · last active; filters by group / trust / date / search; per-row actions (view → A2, edit, ban, warn) gated by capability + the rank guard. Reuse `x-ui.table`. **Apex fences:** every query + action re-asserts `admin.access` + the relevant capability (Livewire actions bypass route middleware — mirror the `⚡groups` `ensureAdmin()` pattern); never leak a hidden/blocked field beyond the actor's ceiling; the bulk/export path (if any) is rate-aware + audited.
**Tests:** authz on every column/action, rank-guard on row actions, pagination/filter correctness, no-PII-leak. **Adversarial review before commit.** **ADR-0096.**

### A2 — Per-member admin management view · `claude/v1x-a2-member-admin` · ⚠ apex · rung: Fable @ max
**Why:** the **recorded v3-b deferral** ("per-user Moderation tab on the member-edit screen"). Aggregates the actions scattered in the front-end mod CP.
**Do:** a per-member admin screen (`admin.members.show`/`edit`) consolidating: account summary, **group membership** (reuse the existing `⚡edit-primary-group`), **ban** (issue/lift — reuse `BanController` service), **warnings** (issue + history — reuse `WarningService`), **IP/devices** (read-only), **force password reset**. Each action behind the capability + rank guard + the locked guards already in the services. **Apex fences:** reuse the engine's guards (don't re-implement); a restricted admin can act only within ceiling; self-action guards (can't ban/strip yourself out of access — mirror the last-owner guard). **Tests + adversarial review.** Rides A1's row "view" action.

### A3 — Warnings in the ACP · `claude/v1x-a3-warnings-acp` · rung: Opus high
**Verify-first:** the warning engine is shipped front-end only (`Warning`/`WarningType`/`WarningService`, `/warnings`). **Do:** an ACP surface to manage **warning types** (CRUD: points, decay days, the three consequence thresholds) + view a member's warning history (linked from A2). No engine change — surface the existing one. **Tests:** type CRUD + threshold validation + the consequence wiring still fires.

---

## Track M — Member-UX functional

### M1 — Per-post quote-reply · `claude/v1x-m1-quote-reply` · rung: Opus high
**Why:** the audits called this "essential for threaded conversations." The polish stubbed the seam — `reply-composer` takes only `topicId`.
**Do:** a **Quote** action per post (in the post footer next to Report) that pre-fills the reply composer with a quoted excerpt (canonical-JSON `blockquote` + an attribution link to the source post) and a `reply_to_post_id` linkage; the composer scrolls into view + focuses. Render the quote through the existing canonical→sanitise pipeline (no raw HTML). Optionally a "jump to quoted post" anchor. **Files:** `forum/topic.blade.php` (footer), `⚡reply-composer` (+`quotePostId`/`replyTo`), the TipTap quote node (already in schema), `CanonicalRenderer`. **Tests:** quote round-trips to canonical JSON + renders sanitised; reply links to the source; works at the bottom composer.

### M2 — Topic/forum follow-subscribe · `claude/v1x-m2-subscriptions` · ⚠ apex-adjacent (notification fan-out) · rung: Fable @ max
**Why:** members can't currently get notified of new replies without replying (only user→user follow + bookmarks exist).
**Do:** a `subscriptions` table (user × subscribable topic|forum, additive/reversible) + a **Follow/Unfollow** control on the topic header (and forum index); on a new approved reply, notify subscribers via the existing notification system + the digest. **⚠ Fan-out fence (the P5.1 `@mention` lesson):** a hot topic can have many subscribers — the notify path MUST be **bounded + queued** (cap per event / chunk through the cron-drained queue; never a synchronous unbounded fan-out), and respect per-user notification prefs + visibility (no notifying a member who can't see the forum). **Tests:** subscribe/unsubscribe, notify-on-reply, the fan-out cap, visibility filter, digest inclusion. **Adversarial review on the fan-out + visibility.** **ADR-0097.**

### M3 — Finish unread / excerpt / slug · `claude/v1x-m3-list-finish` · rung: Opus high
**Verify-first:** check exactly what Slice 5 shipped for the topic-list **unread indicator**, the **first-post excerpt**, and the **notification dropdown** — build only the gaps. **Do (the likely remainders):** the **unread per-row data** (join `TopicRead` watermark into the board-list query without N+1 — respect the HotPathQuery budget), the **excerpt** projection (a cached first-post text snippet on the list), and **slug topic URLs** — `/topics/{id}-{slug}` with the numeric `{id}` still resolving + a 301 to the slugged form (add a `slug` accessor/route-key without breaking `withTrashed()` resolution). **Tests:** unread correctness + no N+1 (extend HotPathQueryTest), excerpt render, slug 301 + old-URL resolve.

---

## Track T — Admin content tooling

### T1 — Canned / stock moderator replies · `claude/v1x-t1-canned-replies` · rung: Sonnet
**Do:** a `canned_replies` table (title + canonical body, additive/reversible) + an ACP CRUD (under Moderation or Appearance→Editor) + an **insert control in the composer/reply** that drops the canned body in (canonical JSON, sanitised on render). **Tests:** CRUD + insertion + sanitise.

### T2 — Admin-editable email templates · `claude/v1x-t2-email-templates` · ⚠ apex-adjacent (render-injection boundary) · rung: Opus xhigh
**Verify-first:** the `SiteTemplate` sandbox (`⚡templates`) is the existing safe-template pattern; the Mailables are `app/Mail/{DigestMail,NotificationMail}` + `resources/views/mail/*`. **Do:** an ACP editor to customise the transactional email bodies through the **same sandboxed, variables-only, auto-escaped** mechanism as `SiteTemplate` (no PHP, allow-listed variables) — never raw Blade/PHP from the admin into a Mailable. Fall back to the shipped default when unset. **⚠ Fence:** the admin string is untrusted-to-the-renderer — reuse the SiteTemplate sandbox/escaping; test injection (no variable escapes the sandbox, no header injection via subject). **ADR-0099.**

### T3 — Analytics charts · `claude/v1x-t3-analytics-charts` · rung: Sonnet
**Verify-first:** `⚡analytics` renders 4 stat cards + a 30-day table (`AnalyticsService`/`DailyMetric`). **Do:** add **time-series charts** (members / topics / posts / active over the window). **Constraint (no host Node, prebuilt assets, clean-room):** prefer **hand-authored inline-SVG** area/line charts (matching the `x-ui.icon` ethos) or a small Apache-2.0 chart lib **bundled via Vite** (then this slice DOES need `npm run build` + the asset-drift gate). No external CDN. **Tests:** chart renders from the metric series; empty/short-range states; a11y (the data table stays as the accessible equivalent).

---

## Cross-cutting
- **Fan-out / DoS:** M2 (and any new notify path) reuses the bounded+queued pattern from the P5.1 `@mention` cap — never a synchronous unbounded fan-out.
- **No host Node:** only T3 (if it bundles a chart lib) touches JS/assets → that branch rebuilds + passes asset-drift; everything else is PHP/Blade (server-rendered, no rebuild).
- **Clean-room** throughout; **tokens-only** UI; reuse `x-ui.*` (incl. `table`/`skeleton`/`dropdown`/`modal`/`empty`).
- **Boundary already drawn:** these are the functional engines; the *form* (components, post-card, composer chrome, ACP shell) is the polish program's and is already in `main`.

## Definition of done (program)
Every slice: gates green + acceptance met; apex slices have a recorded adversarial review with no open HIGH; new notify/visibility/permission paths have dedicated tests; baseline-runnable; reversible migrations proven; owner pushes/merges.
