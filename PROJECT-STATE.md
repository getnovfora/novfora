# PROJECT-STATE.md — NovFora (session resume / handoff)

> **Purpose:** single source of truth for where this project stands right now. Read this **first**, every
> session — both Claude Code and Claude Cowork. Keep it at the repo root. Whoever is working keeps it updated.
>
> **Completed milestone history → [`PROJECT-HISTORY.md`](PROJECT-HISTORY.md)** (reference-only; do not load every
> session). This file is kept lean: the active task, the latest run, the VALIDATE-BEFORE-GO-LIVE list, and open
> follow-ups. Everything below those lives in history.
>
> **Standing detail lives in the folder — read, don't restate:** `docs/PROJECT-BRIEF.md` (full spec) ·
> `CLAUDE.md` (rules, model/effort routing) · `DECISIONS.md` (ADR log) · `ARCHITECTURE.md` ·
> `docs/architecture/`, `docs/product/`, `docs/research/` (Stage A set).

---

## 🌅 Morning report — v1.x Feature Program EXECUTED (2026-06-22) — 14 branches off `main`, none pushed/merged

Ran [`docs/product/v1x-feature-program-kickoff.md`](docs/product/v1x-feature-program-kickoff.md) cold, unattended,
in order **R → S → A → M → T**. Each slice is an **independent branch off `main`** (two intentional stacks, noted
below), committed only at a fully-green gate boundary (`Tommy Huynh` identity, DCO `-s`, no AI trailers). **Nothing
pushed, nothing merged** — all 14 branches are local for owner review; `main` is untouched at `3b70653`. Gates run
on the **VPS native toolchain** (PHP 8.3 + MySQL; sqlite `:memory:` for the suite, isolated-sqlite for the migrate
gate). **Dusk could not run here** (no CI Chrome) — Dusk specs are written/extended + CI-pending.

| Slice | Branch | Head | Gate | Notes |
|---|---|---|---|---|
| R1 doc-trim | `claude/v1x-r1-doc-trim` | `be89224` | links✓ Pint✓ | PROJECT-STATE 1181→148 lines; milestone blocks → PROJECT-HISTORY |
| S3 lift ADRs | `claude/v1x-s3-adrs` | `27f7a50` | docs✓ | ADR-0093/0094 lifted into DECISIONS.md (detail blocks; index table is unmaintained past 0035) |
| R2 prune | `claude/v1x-r2-prune` | `a638a69` | suite 2005✓ migrate✓ links✓ | **stacked on R1+S3**; archived 45 kickoffs + index, deleted lifted draft, import-seed already gone |
| S1 attach harden | `claude/v1x-s1-attach-hardening` | `ddcb136` | suite 2007✓ Pint✓ PHPStan0 | prune reserves scheduled/draft refs (the ADR-0094 LOW) + storage-outage graceful-degrade |
| S2 Dusk CI | `claude/v1x-s2-dusk-ci` | `4730ad4` | php -l✓ Pint✓ | attach-zone + M0 no-scroll-trap specs added; **CI-pending (no Chrome here)** |
| S4 groups icons | — (none) | — | verify | **already merged to `main`** (`75d463a`/`8677b4b`) → gate-and-skip |
| A1 member table ⚠apex | `claude/v1x-a1-member-table` | `32d20c8` | 18✓ walk✓ suite 2022✓ | **review GO** — 1 MEDIUM (hidden-group leak) fixed in-session |
| A2 per-member view ⚠apex | `claude/v1x-a2-member-admin` | `7b6d2f6` | 13✓ suite 2038✓ | **stacked on A1**; **review GO** — fixed liftBan rank gap + added last-owner guard |
| A3 warnings ACP | `claude/v1x-a3-warnings-acp` | `a610c6b` | 8✓ walk✓ suite 2015✓ | warning-type CRUD + read-only thresholds |
| M1 quote-reply | `claude/v1x-m1-quote-reply` | `a51a02f` | 9✓ suite 2011✓ | blockquote+attribution+parent linkage; no JS rebuild |
| M2 subscriptions ⚠apex | `claude/v1x-m2-subscriptions` | `a62e283` | 9✓ migrate✓ suite 2016✓ | **review GO** — fail-closed hardening on the bounded+queued fan-out |
| M3 unread/excerpt/slug | `claude/v1x-m3-list-finish` | `2857bbd` | 6✓ HotPath✓ suite 2013✓ | excerpt + slug 301 (unread/dropdown already shipped → skipped) |
| T1 canned replies | `claude/v1x-t1-canned-replies` | `d18e9f8` | 10✓ walk✓ migrate✓ suite 2017✓ | ACP CRUD + composer ?canned insert (no JS rebuild) |
| T3 analytics charts | `claude/v1x-t3-analytics-charts` | `40f9506` | 5✓ suite 2012✓ | hand-authored inline-SVG sparklines; no asset rebuild |
| T2 email templates ⚠apex | `claude/v1x-t2-email-templates` | `517cd41` | 8✓ suite 2015✓ | **review GO** — reuses the SiteTemplate sandbox; no confirmed finding |

### Apex adversarial reviews (mandated; verify-then-refute, ~6 agents each) — all GO, no unresolved HIGH
- **A1** (member directory): 4 vectors refuted (authz, email-PII, SQL/orderBy, DoS); **1 MEDIUM fixed in-session** —
  the group filter leaked hidden-group names → gated behind the same `users.manage` ceiling as email (+ regression test).
- **A2** (per-member view): 5 vectors reviewed; **NEW MEDIUM fixed** (liftBan lacked the rank guard) + the kickoff's
  **last-owner guard added** (can't ban/warn the sole admin/co-owner). **FLAGGED, pre-existing (NOT new to A2):** the
  FRONT-END ban/warn paths (`BanController` + `WarningService` auto-ban consequence) still lack the last-owner guard —
  the strand is reachable there independently; fix belongs in `UserBanService::ban` + `WarningService` (mirror
  `AccountDeletionService`'s locked sole-admin/co-owner guards). **Engine fast-follow.**
- **M2** (subscription fan-out): 5 vectors; the soft-deleted-forum HIGH was **refuted as structurally unreachable**
  (`StructureService::delete` reparents all topics to a live board first) — applied the **fail-closed hardening anyway**
  (job returns when the forum can't be loaded) + a regression test.
- **T2** (email render-injection): 5 vectors, **no confirmed finding** — the SiteTemplate sandbox (escape + char-allowlist
  + helper registry + skeleton lint) holds; subject is code-controlled + CRLF-stripped. Non-blocking nit: the global
  context also merges `site.description`/`user.*`/`stats.*` (escaped, harmless) — left undeclared as not email-meaningful.

### ⚠ Environment finding (corrects the prior "2 inherited reds")
The `SubdirInstall` + `Pwa` subpath test failures were a **stale route-cache artifact** (`bootstrap/cache/routes-v7.php`),
not a code bug: `php artisan route:clear` makes the suite fully green (2008+ pass / **0 fail** / 1 skip). **The CI/gate
must `route:clear` (or not ship a cached route table) before testing.** Every per-slice suite count above is post-clear.

### ADRs (PROPOSED — referenced in commits, NOT lifted beyond S3's 0093/0094)
**0095** v1.x Feature Program (parent) · **0096** ACP v4 member-management (A1/A2/A3) · **0097** subscriptions (M2) ·
**0098** canned replies (T1) · **0099** email templates (T2). Lift into `DECISIONS.md` on ratification (S3 already lifted
0093/0094 as detail blocks — the index table stopped at 0035, so recent ADRs are detail-block-only).

### Cross-branch merge notes (all additive; hand-merge where flagged)
- **R-track stacking:** R2 contains R1+S3 (the kickoff makes R2 depend on S3's lift; R1's history move is needed for link
  integrity). Merge R1, S3, then R2 — or just R2 (it carries all three).
- **A-track:** A2 is stacked on A1 (A2 "rides A1's row" — the Manage link is live end-to-end). Merge A1 then A2; A3 off `main`.
- **`reply-composer` / `topic.blade.php`:** M1 (?quote), T1 (?canned), M2 (subscribe button) each extend the composer mount
  + the topic embed → a small hand-merge (all additive; keep all params + one `#reply-composer` anchor).
- **`AdminAccessWalkTest` sentinel:** A1, A3, T1 each add a `toContain('/admin/...')` after the co-owners line → trivial both-add.
- **`AdminNavigation` / `lang/en/admin.php` / `routes/web.php`:** A1/A3/T1 add adjacent nav items + lang keys + routes → adjacent, conflict-free or trivial.

### ☀️ What the owner does next
1. **Review + merge** the 14 branches (`git diff main..<branch>`). Suggested order: **R1 → S3 → R2** (hygiene), then
   **A1 → A2 → A3**, **M1 → M2 → M3**, **T1 → T3 → T2** — apply the cross-branch merge notes above.
2. **Lift ADRs 0095–0099** into `DECISIONS.md` on ratification (S3's 0093/0094 are already in).
3. **Run Dusk in CI** (S2's attach-zone + scroll-trap specs + the slug-tolerant moderation path; M1/M2 interaction).
4. **Make the gate `route:clear`** (the env finding above) so the subdir/PWA cases pass in CI.
5. **Engine fast-follow (flagged HIGH-class, pre-existing):** add the sole-admin/co-owner guard to the BAN/WARN engine
   (`UserBanService::ban` + `WarningService::applyConsequence`) so the front-end mod CP can't strand the owner tier either.
6. **Push/merge are the owner's** — Code pushed/merged nothing; `main` is untouched.

---

## ▶ ACTIVE TASK (EXECUTED 2026-06-22 — see the morning report above) — v1.x Feature Program

Executed [`docs/product/v1x-feature-program-kickoff.md`](docs/product/v1x-feature-program-kickoff.md) end-to-end in
order (**R → S → A → M → T**). Independent branch per slice off `main`, gated, committed at a green boundary,
**nothing pushed/merged** (owner reviews). Verified each slice against current `main` first and preferred the codebase.
Apex slices (A1/A2 member data + ban/warn, M2 subscription fan-out, T2 email-template render) each got an in-session
adversarial verify-then-refute review — all GO (no unresolved HIGH).

> Provenance: `docs/product/audit-ips-gap-analysis-2026-06-22.md` (gap map + code anchors) +
> `docs/product/design-polish-kickoff.md` (which deferred these as functional). ADR allocation: parent **0095**
> (v1.x Feature Program); children **0096** ACP v4 member-management (A1/A2/A3), **0097** subscriptions (M2),
> **0098** canned replies (T1), **0099** email templates (T2). Confirm next-free before lifting.

---

## 🌙 Unattended session 2026-06-22 — Design-Polish Program (EXECUTED: 6 branches off `main`; none pushed/merged)

**Executed cold** by an unattended Code run from `docs/product/design-polish-kickoff.md` (order **0 → 1 → 3 → 4 → 5 → 2**,
the apex last). Each slice is an **independent branch off `main`**, committed only at a fully-green gate boundary
(`Tommy Huynh` identity, DCO `-s`, no AI trailers). **Nothing pushed, nothing merged** — all six branches are local
for owner review. Gates run on the **VPS native toolchain** (PHP 8.3 + MySQL; no Docker). **Dusk could not run here**
(no CI Chrome — per the standing note); Dusk specs were written/extended and are **CI-pending**.

| # | Slice | Branch | Head | Gates (local) |
|---|---|---|---|---|
| 0 | M0 scroll-trap hotfix | `claude/polish-m0-prose-scope` | `cc6e8e5` | Pint ✓ · Pest 1959 ✓ · drift ✓ · Dusk contract preserved (CI) |
| 1 | Design-system foundation (Pillar 1) | `claude/polish-p1-design-system` | `4741d36` | Pint ✓ · PHPStan 0 ✓ · Pest 1974 ✓ · drift ✓ |
| 3 | Editor toolbar + schema (Pillar 4) | `claude/polish-p4-toolbar` | `0eb5b53` | Pint ✓ · PHPStan 0 ✓ · Pest 1973 ✓ · drift ✓ · Dusk extended (CI) |
| 4 | ACP navigability + polish (Pillar 2) | `claude/polish-p2-acp` | `bd8bf45` | Pint ✓ · PHPStan 0 ✓ · Pest 1964 ✓ · drift ✓ |
| 5 | Member-experience polish (Pillar 3) | `claude/polish-p3-member` | `7865f91` | Pint ✓ · PHPStan 0 ✓ · Pest 1963 ✓ · drift ✓ · Dusk (CI) |
| 2 | Editor attachments — **APEX** | `claude/polish-p4-attachments` | `6d7f636` | Pint ✓ · PHPStan 0 ✓ · Pest 1969 ✓ · drift ✓ · no migration · **adversarial review on record** |

All six end **runnable + green on the Baseline tier**; all gz asset budgets hold (editor island ≈131 KB, CSS ≈10.4 KB).

### Slice-2 apex review (mandated per-finding verify-then-refute — 11 vectors, independent refutation)
**0 HIGH ⇒ no halt.** The pre-existing backend was already serve-gate-hardened (P5.1 IDOR/club/trashed/orphan gate,
MIME allowlist, off-webroot random-name storage, nosniff); this slice closed the remaining gaps and the review
probed MIME/extension confusion, SVG/script, path-traversal, IDOR, private-club leak, unauth, rate-limit bypass,
association hijack, and re-encode bypass — **all refuted**. Two residuals:
- **1 MEDIUM — FIXED in-session + regression-tested.** The decompression-bomb fence was **per-side only**, so a
  square bomb (11999×11999 ≈144 MP, 446 KB → ~549 MB GD alloc *outside* `memory_limit`) slipped it → worker OOM.
  Fixed with a pre-decode **total-pixel** budget (`max_source_pixels`, ≈25 MP); test rejects a square that passes
  the per-side fence.
- **1 LOW — owner follow-up (NOT fixed; out of apex scope).** The orphan-prune cron can delete a **scheduled**
  reply's attachment, because the scheduled-post subsystem (`PostScheduler`/`ScheduledPost`) doesn't reserve
  attachments — so a reply scheduled beyond `orphan_prune_hours` loses its image at publish. **→ closed by v1.x
  S1.**

### ADRs (PROPOSED — lifted by v1.x S3 into `DECISIONS.md` as 0093/0094)
**ADR-0093 = Design-Polish Program** (parent) · **ADR-0094 = the attachment subsystem** (apex). The draft
`docs/product/design-polish-adrs-DRAFT.md` (numbered 0092/0093) is corrected on lift and then deleted by v1.x R2.

### Cross-branch overlaps to expect on merge (independent branches, all additive)
Slices **0/2/3** touch the `app.css` editor region; Slices **2/3** both touch `novfora-editor.js`, `island.js`,
and `content-editor.blade.php` (toolbar vs file-node/attach-zone — will conflict, need a hand-merge). Slices
**1/4/5** touch different `app.css` regions.

### What the owner does next
1. **Review each branch** (`git log`/`git diff main..<branch>`); independent — **merge in any order**. Slice 0/1
   are the safest first merges; Slice 1 unblocks the `x-ui.*` reuse.
2. **Expect a hand-merge between Slices 2 and 3** (shared editor files).
3. **Lift ADR-0093/0094** into `DECISIONS.md` — **done by v1.x S3** (renumbered from the draft's 0092/0093).
4. **Run Dusk in CI** for Slices 0/2/3/5 (editor journey + attach interaction + roving-tabindex).

---

## ✅ VALIDATE-BEFORE-GO-LIVE (consolidated — carried from Phase 4/5 + enhanced-tier validation)

Scaffolded/disabled-by-default; unit-tested against fakes only. Enable + validate per the named ADR /
`docs/product/release-checklist-1.0.md`. (Full Phase-5 narrative → `PROJECT-HISTORY.md`.)

1. **Meilisearch** (ADR-0060) — **PROVEN 2026-06-19** against a live engine (no private-club leak held; degrades to
   DB on outage). Recommend `SCOUT_QUEUE=true` on Enhanced so a transient engine outage degrades on writes too.
2. **Reverb realtime** (ADR-0061/0062) — **PROVEN 2026-06-19** (id-only payload over a live socket; unauthorized
   subscriber 403 at `/broadcasting/auth`). Production WSS needs an nginx proxy → `127.0.0.1:8090`.
3. **Live Stripe** (ADR-0065 + P5.1) — real keys/webhook; grant only on `payment_status=paid`; add `invoice.*` /
   cancellation before auto-renewal. **Still deferred** (needs a Stripe account).
4. **OAuth / SAML** (ADR-0053–0056) — real apps; the no-merge rule + the **staff-2FA step-up** end to end. **Deferred.**
5. **Web Push** (ADR-0058) — VAPID; live push-service round-trip. **Deferred.**
6. **StopForumSpam submission** (ADR-0069) — optional; key + the content-privacy opt-in. **Deferred.**
7. **Load test at scale** (ADR-0045/0074) — k6/artillery on a real baseline + enhanced host; capture p50/p95/p99
   vs the SLOs; `EXPLAIN` the forum-listing sort. **Deferred.**
8. **Manual a11y** (ADR-0044) — contrast (1.4.3, incl. admin custom theme tokens) · keyboard nav + no focus traps
   (2.1.1/2.1.2) · visible focus (2.4.7) · reduced-motion (2.3.1) · live-region status (4.1.3) · a screen-reader +
   RTL visual pass on clubs/PMs/memberships. (`docs/architecture/accessibility.md`.) **Owner/QA.**
9. **PWA under a `/community/` subpath** (ADR-0078) — install prompt + SW registration scope + the blue-N icon on a
   real device/host (not machine-verifiable here).

**Redis cache/queue** path (DB 1 + `novfora-queue` worker) was also proven live 2026-06-19.

---

## 📌 Open follow-ups (deferred, not blocking)

- **Design-Polish:** review + merge the 6 unmerged branches (above); hand-merge Slices 2↔3; run Dusk in CI.
- **Group clone button on the live demo** — code correct on `main` (PR #43); suspect stale compiled-Blade /
  opcache on Hostinger, or the checked group not `type='custom'`. Next demo cycle: confirm deploy, `view:clear` +
  opcache reset, verify the group's `type` column.
- **`novfora:trust:recompute --user`** prints the generic summary, not the per-user reason (engine correct; print
  is terser). Small polish.
- **Pending-member exit-ramp** — the systemic fix for the `status=pending` false-flag ("Dan") is spec'd at
  `docs/product/pending-member-review-kickoff.md` for a future cycle.
- **Pre-existing inherited test reds (env-sensitive, not a regression):** `SubdirInstallTest` +
  `PwaTest` subpath-scope cases fail under the VPS native runtime (base-path routing under a simulated `/community`
  mount → 404). Untouched by v1.x slices; tracked, not chased here. Plus the standing **asset-budget drift**
  (rebuild `public/build`) + **composer-audit** transitive `guzzlehttp` advisories (bump in a maintenance commit).

---

## Orientation (short form — full detail in `CLAUDE.md` + `PROJECT-HISTORY.md`)

**NovFora** (name locked 2026-06-10, ADR-0026) — open-source (**Apache-2.0**), self-hosted forum/community platform;
**Laravel 13 + Livewire 4 + Alpine.js + Blade**, server-rendered, PHP 8.3 floor; MySQL 8 / MariaDB default,
PostgreSQL on Docker/VPS; Vite prebuilt assets (no host Node). **Two tiers from one codebase** (baseline shared PHP
host / enhanced Docker-VPS); WYSIWYG-first editor; phpBB-grade permission masks; strict clean-room.

**Status:** shipped **1.0.0 (GA)** — Phases 0–5 + the full ACP v3 program complete. Current work is **post-1.0
increments**: the Design-Polish Program (above, unmerged) and the **v1.x Feature Program** (active task, above).

**How we work:** Claude Code builds (plan-before-code per phase); Claude Cowork does knowledge work (no app code);
don't run both against the working tree at once; commit between handoffs. Two stages, gated.

**Working rules** (full in `CLAUDE.md`): strict clean-room · progressive enhancement (no Redis/queue/Reverb/Meili/S3
hard-dep — detect + degrade) · reversible migrations · security by default · tests with every feature · semver'd
module/theme API · conventional commits + ADRs · commit identity `Tommy Huynh <tommy@saturnhq.net>` + DCO `-s`, no
AI trailers.

**Model & effort** (full in `CLAUDE.md §Model routing`): `ultracode` default — start at **Fable @ max** (apex),
downgrade as fit when work is pattern-replication. Fable @ max for permission/security/concurrency core, adversarial
reviews, spikes, API design; Opus 4.8 `xhigh`/`high` below the apex; Sonnet 4.6 for CRUD/scaffolding/breadth sweeps
(Explore sub-agents). Docker/native gates are free — verify with `pest`/`pint`/`phpstan`, not by re-reasoning. Never
re-read a file you just edited. Cap gate output (`tail`).
