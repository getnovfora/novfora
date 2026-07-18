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

## 🌅 Morning report — FABLE v1.4 "The Creator Release" — Phase 0 + 4A + 4B + 4C COMPLETE (green, apex-reviewed, committed per-slice); 4D/4E PARKED for a follow-up; owner reviews (2026-07-18)

Ran [`docs/product/FABLE-V1.4-KICKOFF-2026-07-17.md`](docs/product/FABLE-V1.4-KICKOFF-2026-07-17.md) unattended.
Delivered **Phase 0 (reconcile) + 4A (style engine) + 4B (upgrade-safe customization) + 4C (distribution:
export / Registry / importers)** — every slice gated GREEN, both ◆ apex slices adversarially reviewed with **0
open HIGH/MEDIUM at merge**, each on its own branch off `main`, committed as `Tommy Huynh` (DCO `-s`, no AI
trailers). **Phases 4D and 4E are PARKED** (a deliberate depth-over-breadth call — the remaining ~13 slices +
2 apex reviews + release run did not fit one session at the apex quality bar; documented below with exact plans
+ clean branch state so a follow-up resumes cleanly). **Nothing merged to `main` beyond Phase 0; no `v1.4.0`
tag** — a partial release can't honestly be tagged. `main` is at Phase 0's `5c3800b`.

### The apex review earned its keep (the kickoff's core bet)
Both ◆ slices' verify-then-refute reviews caught **real HIGH/MEDIUM bugs a fully-green suite missed** — exactly
the evidence the kickoff cites. **U11: 5 findings** (1 HIGH + 4 MEDIUM). **Registry v1: 6 findings** (2 HIGH +
4 MEDIUM, across three review passes). **All 11 fixed + regression-tested before the slice's green boundary.**
Full ledgers in ADR-0112 and ADR-0113.

### Branch topology (all off `main` `5c3800b`; only Phase 0 is on `main`)
```
main 5c3800b (Phase 0 merged)
├─ claude/v14-u9-style-props     U9 rich style props (7→21 tokens + dark)       NOV-107
│  └─ claude/v14-u10-style-tree  U10 style tree + per-user chooser (on U9)      NOV-108
├─ claude/v14-u11-template-hooks U11 template hooks + Diff3 (ADR-0112, ◆apex)   NOV-109
├─ claude/v14-u12-style-io       U12 style import/export + global CSS + spike   NOV-110/124
│  └─ claude/v14-registry-v1     Registry v1 (ADR-0113, ◆apex; stacked on U12)  NOV-125
├─ claude/v14-importers          MyBB/SMF importer completion                   NOV-126
└─ claude/v14-morning-report     THIS report (doc-only)
```

### Phase 0 — MERGED to `main` (`5c3800b`)
Landed the two stranded v1.3 report branches; wrote the missing v1.3.0 release record; trimmed PROJECT-STATE
847→125 lines (reports → PROJECT-HISTORY); link-check + pint green.

### 4A — Style engine (Sonnet-rung once the design locked)
- **U9 (NOV-107):** ThemeApi grows **7→21 typed/grouped style tokens** (MINOR → 1.3.0) with **per-token dark
  values** (`tokens_dark`, closing ADR-0037's deferred dark customisation) behind the unchanged strict
  `cleanTokens()` injection fence; grouped ACP editor with dual-mode AA preview. Full suite 2311/0.
- **U10 (NOV-108, on U9):** `site_themes` becomes a **tree** (parent_id, depth-5, cycle-guarded,
  delete-refused-with-children, child-wins inheritance) + a **per-user style chooser** (`users.style_theme_id`,
  no-JS form, validated); compiled CSS under a **monotonic generation key** so a parent edit atomically busts
  descendants, viewer-resolution generation-cached → warm path stays **zero site_themes queries**; ships
  NovFora/Daylight/Midnight presets (never auto-activated). **Gate 4A proof — a child theme built entirely in
  the ACP — passes.** Full suite 2323/0.

### 4B ◆ APEX — U11 upgrade-safe customization (NOV-109, ADR-0112)
Two mechanisms on the **unchanged ADR-0038 sandbox** as the sole render/safety authority: **template-hook
fragments** on 8 named anchors (name-anchored → survive upgrades; per-fragment isolated; forever-cached map =
zero warm-path queries) + **Diff3 three-way merge** for full overrides (in-house bounded Myers, no new
dependency; a conflict NEVER touches the stored source and `Diff3` returns `merged=null` so markers can't
exist; merged output re-passes the sandbox lint). `TemplateSync` on the upgrade path + `novfora:templates:sync`
+ lazy ACP. **Gate 4B proof — a themed + template-modded install crosses a simulated release with mods intact,
conflicts non-fatal — passes.** Apex: **1 HIGH** (lint skeleton unsound — regex→AST text-node scan) **+ 4
MEDIUM** (slash-separated handlers; Diff3 duplicate-collapse corruption; Diff3 O(D²) OOM; ACP sync
write-amplification), all fixed. Full suite 2340/0.

### 4C ◆ — Distribution (spike GO, U12, Registry apex, importers)
- **NOV-124 spike → GO** ([`docs/product/spike-registry-memo.md`](docs/product/spike-registry-memo.md)) on all
  four self-gate criteria.
- **U12 (NOV-110):** `StylePackage` export/import — a portable zip whose `style.json` manifest **IS the registry
  theme format**; import rides ArchiveGuard + StyleThemeManager's strict validation (no second path;
  traversal/CSS-injection refused). Plus a site-wide global custom-CSS box. Full suite 2313/0.
- **Registry v1 (NOV-125, ADR-0113, APEX):** signed static-feed client — ed25519 root-key verify over exact
  bytes, **durable** monotonic sequence, sha256 content-address, publisher-active + downgrade refusal,
  **SSRF-guarded** fetches, one-click install via the untouched paths, publisher **revocation → trust-key
  disable + `on_revoke` kill-switch + ACP alert + daily cron refresh**, ops `novfora:registry:sign`, ACP Browse.
  Apex across **three passes: 2 HIGH + 4 MEDIUM, all fixed** (fingerprint decoupling; dead kill-switch config;
  cache-flushable rollback floor; redirect SSRF; cached-path revocation skip + no cron; unbounded download).
  Registry suite 18 tests; full suite 2331/0.
- **Importers (NOV-126):** MyBB/SMF filter to real members (parity with phpBB bot-exclusion / XenForo
  valid-only, mirrored into `counts()`); SMF attachment uploader resolves via the owning message; SCAFFOLD
  labels flipped. Import 15/15 (7929 assertions); full suite 2305/0.

### Gate discipline
`route:clear` before every gate; slice gate = Pest + Pint + PHPStan(app/) 0 + migrate apply/rollback/re-apply +
a11y where touched; full parallel suite green at each committed boundary. **Dusk = CI-pending** (no Chrome in
the env). New reversible migrations across the slices: `site_themes.tokens_dark`; style tree +
`users.style_theme_id`; `site_templates` merge-tracking + `site_template_hooks`; `registry_installs` +
`registry_state`.

---

## ⏸ PARKED — Phases 4D + 4E + the release run (follow-up session; nothing built, nothing broken)

Parked deliberately for capacity, per the kickoff's "park that phase, ship around it, flag it" rule. Each has a
ready plan; nothing is half-built. A follow-up session branches per slice off `main` and continues.

- **Phase 4D — Admin at scale (mostly Sonnet-rung):** U14 registration controls + pending-member exit-ramp fix
  (**NOV-112**; spec `docs/product/pending-member-review-kickoff.md` — do its Step-0 ADR first, the
  anti-spam-sensitive activation policy), U13 IP investigation + CIDR/range bans *(elevated review)*
  (**NOV-111**), U16 maintenance/rebuild + logs + mail-test ACP (**NOV-114**), U19 custom topic fields +
  move-with-redirect wiring the existing `moved_to_topic_id` seam (**NOV-116**), staff workflow + Hearth
  metrics v1 on `moderator_assignments` — **real signals only** (**NOV-127**, ADR-0114). Gate 4D: full gates +
  demo soak.
- **Phase 4E ◆◆ — Admin API + self-upgrade:** **read
  [`docs/product/ADMIN-API-AND-POPULATE-SPEC-2026-07-02.md`](docs/product/ADMIN-API-AND-POPULATE-SPEC-2026-07-02.md)
  IN FULL first** (authoritative). Slice order E1→E2→E3→E4→E5→E7→E8. E1 ◆APEX scoped tokens (scopes ∩ `canDo`,
  no super-scope, co-owner-only restore/upgrade scopes, 2FA-gated mint, `Idempotency-Key`, OpenAPI 3.1;
  **NOV-135**, ADR-0115), E2 read (**NOV-136**), E3 ◆ write via existing domain services only (**NOV-137**), E4
  ◆APEX backups (**NOV-138**, ADR-0116), E5 ◆◆APEX restore — the most dangerous endpoint (**NOV-139**,
  ADR-0117), E7 ◆◆APEX self-upgrade — ed25519 core-release signing + GitHub Releases channel (**NOV-141**,
  ADR-0118), E8 docs (**NOV-142**). **E6a/E6b already shipped** as the private Populate plugin — do NOT rebuild;
  add only the thin Populate API round-trip endpoints behind E1's scopes. Gate 4E: the spec §5 end-to-end proof
  on all three RH-4 layouts.
- **The release run (§6):** only after 4D/4E are green — `backup/pre-v14` tag, `--no-ff` merges in phase order
  (U9→U10→U11→U12→Registry→importers→4D→4E) re-gating between merges, union gate, bump `config/app.php` →
  `1.4.0`, `build-release.sh` → `verify-release.sh` = `RELEASE_VERIFY=PASS` (confirm Populate is NOT in the
  zip), tag `v1.4.0` locally. **Expected merge conflicts:** the `DECISIONS.md` append tail (keep 0112 then 0113
  in order); the **themes SFC** 3-way (U9 grouped tokens / U10 tree / U12 export-import — all additive,
  hand-merge); `AdminNavigation`/`routes/web.php`/`lang/en/admin.php` adjacent nav items; the
  `AdminAccessWalkTest` sentinel (trivial both-add).

---

## ✅ Definition-of-done (kickoff §10) — status at this handoff

- [x] Phase 0 landed: PROJECT-STATE accurate + lean, v1.3.0 recorded, history moved.
- [~] Every phase 4A–4E **merged green or parked with a documented reason**: 4A/4B/4C **green + committed** (not
  merged — the release run is parked); 4D/4E **parked with plans** above. None silently dropped.
- [x] Every ◆/◆◆ slice **built so far** (U11, Registry v1) has an apex review with **0 open HIGH/MEDIUM**,
  findings + fixes recorded (ADR-0112, ADR-0113). *(4E's ◆◆ slices are parked, un-built.)*
- [x] Union gate green **per slice**; migrations reversible. *(The §6 union re-gate across all merged slices is
  part of the parked release run.)*
- [ ] `RELEASE_VERIFY=PASS` / Populate-not-in-zip — **parked** (release run not reached).
- [ ] Version `1.4.0` / `v1.4.0` tag / `backup/pre-v14` — **not done** (partial release; see ☀️).
- [!] **Linear reconciled — BLOCKED this session** (no Linear MCP in the env). Every intended state/comment is
  listed in the ☀️ section for the owner to apply by hand.
- [x] ADRs lifted at confirmed next-free numbers (0112, 0113) on their branches.
- [x] This report + the ☀️ owner section.

---

## ☀️ What the owner does next

1. **Review + keep the 6 slice branches** (all green, apex-reviewed where ◆): `claude/v14-u9-style-props` →
   `claude/v14-u10-style-tree` (stacked); `claude/v14-u11-template-hooks`; `claude/v14-u12-style-io` →
   `claude/v14-registry-v1` (stacked); `claude/v14-importers`. And **merge this doc-only report branch**
   (`claude/v14-morning-report`) to `main` so the handoff record lives on `main` (don't strand it — that was
   the Phase 0 lesson). All local — the harness cannot push the protected `main`.
2. **Run a follow-up v1.4 session for 4D + 4E + the release run** (parked section above has the full plan, ADR
   numbers, and the expected merge conflicts). 4D is mostly Sonnet-rung; 4E is the big apex phase.
3. **Linear — apply by hand (NO Linear write path this session; the classifier had no MCP to deny — the tool
   simply isn't present).** Set → **Done on merge:** NOV-107, NOV-108, NOV-109, NOV-110, NOV-124 (spike),
   NOV-125, NOV-126. Post the per-issue completion comment (branch + head SHA + gate + ADR + confirmed apex
   findings) — the full ledger is in the session scratchpad; the essentials are in this report. Still **verify**
   (Phase 0 could not, no MCP): the v1.3 issues are `Done`, and NOV-140/NOV-143 (Populate E6a/E6b) are `Done`
   with completion comments backfilled. Leave **NOV-112/111/114/116/127** (4D) and **NOV-135–139/141/142** (4E)
   in Backlog — parked.
4. **File the discovered follow-ups as issues:** (a) importer live-dump verification + per-driver traversal
   tests + the tracked `docs/architecture/phase3-extensibility/importers.md` refresh (stale re: XenForo) + the
   untracked `novfora-docs` migrating-guide "scaffold" wording + the ACP import surface that guide references
   but which doesn't exist; (b) the U12 export → wire `effective()`+`tokens_dark` once U10 merges (reserved
   manifest slot); (c) demo.novfora.com still runs pre-v1.3 (upgrade via the cron auto-upgrade path,
   backup-first — v1.3.0 and later carry migrations, not assets-only).
5. **Gemini API key (STILL OPEN):** the key committed in the old `F:\ForumGen\generate_forum.py` was flagged for
   revocation 2026-07-02 and is **not confirmed revoked**. Confirm it's revoked (the new script is env-only).
6. **Dusk in CI:** all new ACP surfaces (theme editor, templates/hooks, Registry Browse) are server-render +
   auth-gated here; no Chrome in the build env, so their browser journeys are CI-pending.

---

## 🏁 Release record — v1.3.0 SHIPPED (tagged + pushed; recorded 2026-07-17)

> Written during v1.4 Phase 0: v1.3.0 shipped without a morning report — this is the missing record. The
> stranded Phase 3A/3B session reports now live in `PROJECT-HISTORY.md` (moved there with the rest).

- **Tag `v1.3.0` = `4f5585e`**, pushed; `origin/main` **in sync** at `126e5f0` (the v1.3–v1.5 roadmap +
  admin-API/populate spec + build-prompts docs commit sits on top of the tag). `config/app.php` = `1.3.0`.
- **Phase 3A — foundation & hygiene:** **NOV-121** ◆ engine hygiene (last-plain-admin removal guard +
  delegation fan-out; ADR-0086/0087 amended) · **NOV-96** ◆ permission-aware UI contract (`Affordance` +
  `<x-action>` + route-level friendly-403; **ADR-0109**) · **NOV-122** CI completion (13 Dusk journeys wired,
  `route:clear` in CI, guzzle/psr7 audit bump, asset budget verified). Both ◆ slices apex-reviewed, GO.
- **Phase 3B — front-of-site redesign:** card-based board index (**NOV-90**), sm–md search entry (**NOV-92**),
  board/thread guest CTA via `<x-action>` (**NOV-93**), centered auth header (**NOV-94**), `x-ui.timestamp`
  (**NOV-95**) — all through the 3A contract, no new JS deps.
- **Phase 3C — engagement:** multi-quote basket (**NOV-100** U1) · follow tags + Watched home-loop
  (**NOV-101** U2) · announcement topic type with dismissible targeted banners (**NOV-102** U4).
- **Phase 3D — community:** ◆-lite profile wall with fenced status posts + mod queue (**NOV-91**) · inline
  approve/reject on held replies in-thread (**NOV-104** U6) · welcome email + new-member checklist
  (**NOV-123** onboarding-lite).
- **Release run + post-tag green-ups:** version bump + onboarding hot-path gate (`f86262d`); fail-soft layout
  widgets + cold-render budget (`44581db`); **two real bugs exposed by the newly CI-gated Dusk journeys fixed**
  (`86a4fca`, NOV-122's wiring paying off); installer-wizard Dusk timeouts (`6eabc40`); thread query-budget
  cache-warmth edge (`4f5585e`).
- **Residue:** 2 harness-timing Dusk journeys returned to **CI-pending** with tracking (`91b8984`) · parked
  product calls **U8 imported-username revert** (ADR-0106) and **U18 Turnstile fail-open posture** (ADR-0107)
  still await owner decisions · the old `F:\ForumGen` **Gemini API key revocation is unconfirmed**.

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

- **Dusk in CI:** 2 harness-timing journeys are CI-pending with tracking (`91b8984`); the 13 wired journeys are
  the green-verifier on CI (no Chrome in the build envs).
- **Parked product calls (owner):** U8 imported-username revert vs the modern format rule (ADR-0106, recommendation
  on record) · U18 Turnstile fail-open vs the fail-closed default (ADR-0107, recommendation on record).
- **Gemini API key** committed in the old `F:\ForumGen\generate_forum.py` — revocation requested 2026-07-02,
  **not confirmed revoked**. The replacement script is env-only.
- **Group clone button on the live demo** — code correct on `main` (PR #43); suspect stale compiled-Blade /
  opcache on Hostinger, or the checked group not `type='custom'`. Next demo cycle: confirm deploy, `view:clear` +
  opcache reset, verify the group's `type` column.
- **`novfora:trust:recompute --user`** prints the generic summary, not the per-user reason (engine correct; print
  is terser). Small polish.
- **Pending-member exit-ramp** — spec'd at `docs/product/pending-member-review-kickoff.md`; **being absorbed by
  v1.4 U14 (NOV-112)** this cycle.
- **demo.novfora.com** still runs pre-v1.3 — the v1.3.0 upgrade (and v1.4.0 after it) goes via the cron
  auto-upgrade path, backup-first (both carry migrations; not assets-only).

---

## Orientation (short form — full detail in `CLAUDE.md` + `PROJECT-HISTORY.md`)

**NovFora** (name locked 2026-06-10, ADR-0026) — open-source (**Apache-2.0**), self-hosted forum/community platform;
**Laravel 13 + Livewire 4 + Alpine.js + Blade**, server-rendered, PHP 8.3 floor; MySQL 8 / MariaDB default,
PostgreSQL on Docker/VPS; Vite prebuilt assets (no host Node). **Two tiers from one codebase** (baseline shared PHP
host / enhanced Docker-VPS); WYSIWYG-first editor; phpBB-grade permission masks; strict clean-room.

**Status:** shipped **1.0.0 (GA)**, then **v1.2.0** and **v1.3.0** (tagged + pushed). Current work: **v1.4 "The
Creator Release"** — **Phase 0 merged; 4A/4B/4C green + committed on per-slice branches (both ◆ apex slices
reviewed, 0 open HIGH/MEDIUM); 4D/4E + the release run PARKED** for a follow-up (report above). No `v1.4.0`
tag yet. The private Populate plugin (E6a/E6b) lives in its own repo `D:\novfora-populate`, junctioned at
`modules/novfora/populate` — never committed here, never shipped.

**How we work:** Claude Code builds (plan-before-code per phase); Claude Cowork does knowledge work (no app code);
don't run both against the working tree at once; commit between handoffs. Two stages, gated.

**Working rules** (full in `CLAUDE.md`): strict clean-room · progressive enhancement (no Redis/queue/Reverb/Meili/S3
hard-dep — detect + degrade) · reversible migrations · security by default · tests with every feature · semver'd
module/theme API · conventional commits + ADRs · commit identity `Tommy Huynh <tommy@saturnhq.net>` + DCO `-s`, no
AI trailers · close the loop on Linear every cycle (states, comments, discoveries, blocked writes).

**Model & effort** (full in `CLAUDE.md §Model routing`): `ultracode` default — start at **Fable @ max** (apex),
downgrade as fit when work is pattern-replication. Fable @ max for permission/security/concurrency core, adversarial
reviews, spikes, API design; Opus 4.8 `xhigh`/`high` below the apex; Sonnet 4.6 for CRUD/scaffolding/breadth sweeps
(Explore sub-agents). Docker/native gates are free — verify with `pest`/`pint`/`phpstan`, not by re-reasoning. Never
re-read a file you just edited. Cap gate output (`tail`).
