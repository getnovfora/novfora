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

## ▶ ACTIVE TASK: v1.4 release hardening (resumed 2026-10-29)

**Where it stands:** the v1.4 feature work is **merged to `main`** but **not tagged**. On 2026-10-28 the owner merged
all 12 v1.4 slices (4A–4D plus E1), the final-report docs and a docs sync. The merge train ran through
`integ/v14-trial`, and `origin/main` = `728df58`. `config/app.php` is still `1.3.0`. Owner call (2026-10-29): **fix the
security backlog first, then tag `v1.4.0`.** E2–E8 move to a later release (v1.4.x or v1.5).

The July morning report that tracked these slices on their branches now lives in
[`PROJECT-HISTORY.md`](PROJECT-HISTORY.md).

### Gate status on `main` @ `728df58` (verified 2026-10-29)
- **Passing:**
  - CI Pest (MySQL 8) and CI Dusk.
  - Local Pest on SQLite: 2451 passed, 1 skipped.
  - Local Pint and Larastan: clean.
- **CI red since 2026-10-06** (`14f75d1`), for two reasons:
  1. The brand gate matched `AGENTS.md`. Because it is the lint job's first step, Pint, Larastan and audit were
     skipped on every run after that.
  2. The assets-fresh check failed: `public/build` was not rebuilt after the merges.

  Both are fixed on `claude/project-status-review-uxmk9o` (`b1f58e2`, `34a6606`).
- **`composer audit` fails:** about 28 advisories built up over the gap, including a Laravel TokenGuard bypass,
  a Livewire `#[Authorize]` bypass and XSS, and commonmark XSS. Tracked as **NOV-162** (Urgent).

### Security backlog: blocks the `v1.4.0` tag
The 2026-09-06 security review filed NF-01 to NF-13 in Linear (NOV-144 to NOV-156). Nothing about them was in the
repo until now.

| Priority | Issues |
|---|---|
| ◆ Urgent | **NF-01** NOV-144: reply authz is checked at board scope, not thread scope · **NF-02** NOV-145: pending-post attachments bypass moderation · **NF-03** NOV-146: REST API topic reads drift from web visibility · **NF-04** NOV-147: OAuth JIT sign-up must prove the email is verified · **NF-05** NOV-148: service-worker cache crosses the privacy boundary · **NOV-162**: dependency advisories |
| High | **NF-06** NOV-149: API and web rate limits differ · **NF-07** NOV-150: board-offline doesn't gate member mutations · **NF-08** NOV-151: reply transaction and counter concurrency |
| Medium / Low | NF-09 to NF-13, NOV-152 to NOV-156 |

### ✅ Hardening slice MERGED (PR #52 → `main` @ `5079215`) and DEPLOYED to novfora.com/community (2026-10-30)

| Item | Commits | What changed |
|---|---|---|
| NOV-162 | `bc06d49` | Patch-level dependency bumps; `composer audit` clean |
| NF-01 | `594c807` | `ReplyGate`, a single thread-scope reply predicate (composer, API, scheduler, `canReply`) |
| NF-02 | `bbf878b` | `PostVisibility`; attachments never more public than their post |
| NF-03 | `99b8936`, `fe7caa2` | `TopicVisibility`; API and board listing share one thread-scope selector with bounded candidate queries |
| NF-04 | `0d058df`, `cd40969` | OAuth sign-up verified only on provider evidence; pre-hijack guard on password reset |
| NF-05 | `9602781`, `288cbdd` | Service worker: navigations handled first, asset allowlist, namespaced cleanup, `v3`, credential-less offline precache |

- **Design:** ADR-0123.
- **Gates:** Pest 2493 passed / 1 skipped (42 new tests); Pint, Larastan, audit, brand gate and assets-fresh all
  clean.
- **Apex review** (3 independent reviewers, verify-then-refute): 5 MEDIUM confirmed and fixed, each with a
  regression test that fails on the prior code; **0 HIGH/MEDIUM open.**
- **Real-browser validation:** `scripts/pwa-sw-harness.cjs`, run at the domain root and under `/community/`.
- **New issues from the review:** NOV-163 (thread-visibility drift on secondary listings; consolidate the
  predicates) and NOV-164 (fonts 404 under a subdirectory mount).

**Live deploy (2026-10-30 00:26 UTC):** https://novfora.com/community/ upgraded in place to `5079215` by
`scripts/upgrade-in-place.sh` (merged to `main` in PR #53), driven from Hostinger cron:
- A `novfora:backup` zip and a code tarball were taken first.
- The code was swapped.
- `novfora:upgrade` applied 11 migrations, after taking its own pre-upgrade backup.
- Pending migrations: 0; `/health` and the home page returned 200.
- The live `sw.js` is the v3 worker.

Linear: NOV-144 to NOV-148 and NOV-162 are **Done**, each with a deploy comment.

**Production scheduler fixed (2026-10-30):** `/health` had shown `queue.pending` = 82 with no heartbeat ever
recorded. The hPanel cron line `cd ~/novfora && /usr/bin/php artisan schedule:run >> … 2>&1` never executed on
Hostinger. It was replaced by `/usr/bin/php /home/u938587075/novfora/artisan schedule:run` (cron `pMdru7Z92p`).
The heartbeat appeared within 60 s and the queue drained to 0. Our installer prints the failing form, so this is
filed as **NOV-165** (High).

**Next:**
1. ✅ **PR #53 merged (2026-10-30, `main` @ `0a10df4`, CI green):**
   - **NOV-165 (Done):** the installer and `novfora:install` print the absolute, cd-free cron line. The CLI no
     longer strips its path.
   - **`scripts/upgrade-in-place.sh`, hardened in review:**
     - flock process lock, replacing the age-based lock (Codex P1);
     - copy failures roll back (Codex P2);
     - a SQLite database in `database/` is carried over during the swap;
     - the report folder's `.htaccess` is never overwritten;
     - rollback restores only the trees this run moved aside.
   - One LOW left as is: the script replaces `public/.htaccess` on upgrade (pre-existing behaviour).
2. The live site runs `5079215`. #53 changes only the installer output and the ops script, so nothing needs
   redeploying: the production cron already uses the new line.
3. ✅ **PR #54 merged (2026-10-31, `main` @ `03e3e0f`, CI green): NF-06..08, ADR-0124.**
   NOV-149, NOV-150 and NOV-151 are Done, each with a completion comment.
   - **NF-06:** one posting budget shared by the web composer and the API, plus two API rate layers: per-IP
     before token auth, per-member after (`ThrottleApiMember`). New env: `NOVFORA_API_IP_PER_MINUTE`,
     `NOVFORA_API_USER_PER_MINUTE`.
   - **NF-07:** `BoardState` plus `EnsureBoardOnline` as Livewire persistent middleware. A JSON 503 on the API
     while the board is offline. Scheduled replies are deferred while offline.
   - **NF-08:** reply write path. **This adds a migration** (`topics.last_position`, reversible and backfilled), so
     the next deploy runs one.
     - Positions come from a counter; no post-row lock is taken.
     - Create-time aggregates are atomic.
     - Delete/restore re-derivation runs after commit.
     - Notifications, events and Scout run after commit.
     - `scripts/stress/concurrent-replies.php` now runs in CI on MySQL. It passes on PostgreSQL 16 and
       MariaDB 10.11 too.
   - **Review fixes:** apex review, 0 HIGH; 1 MEDIUM and 2 LOWs fixed. Codex P2 deadlock fixed. Two CI MySQL
     failures root-caused and fixed: a harness range lock, and a REPEATABLE READ lost update.
   - **Filed:**
     - NOV-166: idempotency aborts PostgreSQL transactions.
     - NOV-167: the API returns 500 on a content rejection, and a reply racing a merge can land in the moved shell.
     - NOV-168: lock-order deadlocks with account deletion and with topic creation.
     - NOV-169: a concurrent double soft-delete double-decrements counts.
4. **The security backlog that blocked the tag is cleared** (NF-01..08 and NOV-162). Before tagging, deploy
   `main` to staging or the live community and run the staging harness (`scripts/pwa-sw-harness.cjs`).
5. The `v1.4.0` release run (owner section §1).

---

## ☀️ OWNER SECTION: decisions, hand-offs, and what to run

**0. Push restored (2026-10-29).** The owner gave the Claude GitHub App write access, and the branch
`claude/project-status-review-uxmk9o` is on `origin`. CI runs only on pushes to `main` and on PRs, so opening a
PR is what gets the CI signal on this branch.

**0a. Hostinger clean-up (needs hPanel; the connector can't delete files):**
- Delete `public_html/.ops/`. It holds deploy scripts, logs and probe files; it is web-denied but should go.
- `~/novfora-deploy/` holds the build tree and the pre-upgrade code tarball. Keep it until you're happy with the
  release, then delete it.
- The `dev` site cron (`5TWVODRZAC`) uses `cd …/dev/public && php artisan …`. There is no `artisan` in `public/`,
  so it likely never runs. Point it at `/usr/bin/php …/dev/artisan schedule:run` if the dev site matters.

**1. Release run, once the security slice is merged:**
1. Bump `config/app.php` to `1.4.0`.
2. Run `scripts/build-release.sh`, then `verify-release.sh` (expect `RELEASE_VERIFY=PASS`).
3. Confirm `modules/novfora/populate` is **absent** from the zip. It is a junction to the private Populate repo;
   never commit or ship it.
4. Add `[1.3.0]` and `[1.4.0]` sections to `CHANGELOG.md`. Neither version has one; the security entries
   currently sit under Unreleased.
5. Tag locally; you push.

**2. Linear was reconciled on 2026-10-29:**
- NOV-107–112, 114, 116 and 124–127 plus NOV-135 are now **Done**, each with a merge/SHA/ADR/review comment.
- The July discoveries are filed as NOV-157 to NOV-161, and the dependency advisories as NOV-162.
- NF-01 to NF-05 (NOV-144 to NOV-148) and NOV-162 are **Done** as of 2026-10-30 (merged in PR #52 and deployed),
  each with a deploy comment. NOV-165 (installer cron line) is Done (PR #53).
- NOV-140 and NOV-143 (the private Populate plugin, E6) have been **In Review since 2026-07-02**. That's your call,
  since the plugin lives outside this repo.

**3. Open product decisions (parked, not guessed):**
- The default for self-upgrade auto-apply (E7): `off`, `security-only` or `all-patch`. The spec recommends
  security-only, chosen at install time.
- Carried from v1.3: **U8**, the imported-username revert (ADR-0106), and **U18**, Turnstile fail-open (ADR-0107).

**4. Cloud-session gotchas:**
- `composer install` can't reach the github.com API; seed `phpstan/phpstan` from git (see the Second Brain gotcha).
- `git push` returns 403 ("Resource not accessible by integration") whenever the Claude GitHub App lacks write
  access to `getnovfora/novfora`. Fix it in the App's installation settings on GitHub.
- The PHP file cache (`storage/framework/cache`) is shared between ad-hoc local servers. Run `php artisan
  cache:clear` when switching test databases, or stale ACL verdicts leak between them.

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
- **demo.novfora.com** still runs pre-v1.3 — the v1.3.0 upgrade (and v1.4.0 after it) goes via the cron
  auto-upgrade path, backup-first (both carry migrations; not assets-only).

---

## Orientation (short form — full detail in `CLAUDE.md` + `PROJECT-HISTORY.md`)

**NovFora** (name locked 2026-06-10, ADR-0026) — open-source (**Apache-2.0**), self-hosted forum/community platform;
**Laravel 13 + Livewire 4 + Alpine.js + Blade**, server-rendered, PHP 8.3 floor; MySQL 8 / MariaDB default,
PostgreSQL on Docker/VPS; Vite prebuilt assets (no host Node). **Two tiers from one codebase** (baseline shared PHP
host / enhanced Docker-VPS); WYSIWYG-first editor; phpBB-grade permission masks; strict clean-room.

**Status:** shipped **1.0.0 (GA)**, then **v1.2.0** and **v1.3.0** (tagged + pushed; record above). The v1.4 "Creator
Release" feature set is merged to `main` but untagged. Current work is the security hardening that precedes the
`v1.4.0` tag (active task above). The private Populate plugin (E6a/E6b) lives in its own repo
`D:\novfora-populate`, junctioned at `modules/novfora/populate` — never committed here, never shipped.

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
