<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# M5 — Claude Code kickoff prompt (Phase 1 FINAL — Operability & the runnable MVP)

> Paste the block below into the **Claude Code** session to begin **M5 — the last Phase 1 milestone.** On
> completion, Phase 1 / the **Core MVP is done** and the repo is shippable. M0–M4 are complete.
> M5 is **operability**, not new features: the no-SSH web installer is the headline (it's what makes NovFora
> actually self-hostable by ordinary operators), and the milestone must satisfy the **full Phase 1 exit
> criteria** in [phase-1-plan.md](../phase-1-plan.md) §1. The installer is **security-sensitive** (unauthenticated
> pre-install endpoint that writes `.env`, sets up the DB, and creates the admin) — deep-reasoning territory.
> Specs: phase-1-plan §1 (definition of done) + §5 (M5); PROJECT-BRIEF §6 (Operability); system-architecture §7
> (perf budgets); ADR-0011 (cron queue), ADR-0003 (tier detection — reuse M0's `ServiceTier`).

---

```
Begin Phase 1 — M5 (Operability & the runnable MVP). This is the FINAL Phase 1 milestone — on completion the
Core MVP is shippable. M0–M4 are done. M5 is operability + proving the Phase 1 exit criteria, NOT new features.

STEP 0 — IDEMPOTENCY GUARD (before any build):
  • Confirm M5 isn't already done: read PROJECT-STATE.md + `git log --oneline`. If M5 commits exist / it's
    recorded done, STOP and report — do NOT rebuild.
  • Confirm M4 is green (Docker dev env) and the working tree is clean. Commit this kickoff doc (docs:), then build.

START OF SESSION: read PROJECT-STATE.md, CLAUDE.md, docs/PROJECT-BRIEF.md (standing rule). Then the M5 spec:
phase-1-plan §1 (the 6 exit criteria — M5 must satisfy ALL of them) + §5 (M5); PROJECT-BRIEF §6 (Operability);
system-architecture §7 (the performance budgets to enforce in CI); reuse M0's ServiceTier (ADR-0003) and the
novfora:backup skeleton.

MODEL/EFFORT: Opus 4.8 at xhigh on (a) the web installer — it's an unauthenticated, pre-install surface that
writes secrets, runs migrations, and creates the admin, so it MUST lock after install (refuse to re-run),
validate every input, and never leak secrets; and (b) backup/restore data integrity. Sonnet is fine for the
demo seed, the getting-started guide, and wiring perf budgets into CI. Open with a SHORT M5 plan, then proceed.

BUILD M5:

1) NO-SSH WEB INSTALLER (the headline operability feature): a browser wizard that runs on a baseline shared
   host (no SSH) — requirement/writable-path/permission probes + a host-compatibility checklist; tier detection
   (reuse M0 ServiceTier); DB connection form → run migrations; generate APP_KEY + write `.env`; create the
   admin account (argon2id + staff-2FA enrollment per M1); `php artisan storage:link` equivalent (closes the
   M4 avatar/cover caveat); choose demo-seed or empty start. **LOCK after install** (persist an installed
   marker; the installer refuses to run once installed — no re-trigger, no admin-reset vector). Provide a
   Composer/artisan CLI install path too (for VPS). Security-test the lock + input validation.

2) BACKUPS + RESTORE: complete the M0 `novfora:backup` skeleton — DB dump + storage archive, scheduled via cron
   AND an admin-UI trigger/download; a RESTORE path (CLI + documented). Prove an upgrade rehearsal on the
   baseline tier: reversible-migration upgrade + a backup→restore round-trip, asserted green.

3) HEALTH CHECKS: a health/status endpoint (DB, cache, queue freshness, tier) for uptime monitoring.

4) DEMO SEED + GETTING-STARTED: an idempotent demo seed that produces a believable community (categories →
   forums → topics → posts, users across trust levels, the default theme). A getting-started guide: install on
   a baseline shared host, the single cron line that drives everything (ADR-0011), and how enabling enhanced
   services (Redis/Meilisearch/Reverb/S3) lights up the upgrades. Note the GD/Imagick requirement for image
   thumbnails as a deployment detail.

5) FINALIZE `.env.example`: every key documented with baseline-safe defaults.

6) PERF BUDGETS IN CI (system-architecture §7): enforce query-count-per-page (≤ the documented budget, no
   N+1), asset sizes, and p95 render targets as CI gates — failing the build if exceeded.

7) RUN THE DUSK EDITOR JOURNEY FOR REAL: the M2 editor Dusk battery was written but never executed (the build
   container has no Chrome). Stand up a Chrome-enabled CI job (or local run) and execute it green, so the
   editor is proven end-to-end IN THE REAL APP — not just via the spike's Playwright. This closes the one
   open verification gap from M2.

DEFINITION OF DONE = the Phase 1 EXIT CRITERIA (phase-1-plan §1 — all six):
  (1) runs on the baseline tier end-to-end via the no-SSH installer, one cron line drives everything;
  (2) the identical code runs on the enhanced tier with no code change;
  (3) tests green incl. the permission-mask truth-tables + service-tier fallback suites;
  (4) all CI guards pass — Pint, PHPStan, composer audit, Pest, **Dusk (executed)**, and the query/asset/perf
      budgets;
  (5) demo seed + getting-started produce a working community; `.env.example` is current;
  (6) the upgrade/restore path is proven on the baseline tier.
Then write a short Phase 1 COMPLETION SUMMARY (each criterion → evidence). M0–M4 suites STAY green. Small
conventional DCO commits; PROJECT-STATE updated to "Phase 1 / MVP complete".

SCOPE FENCE — M5 is operability + closing the MVP. NO new product features (nothing from Phase 2/3/4 — no
reactions/PMs/digest, no visual theme configurator, no module API, no importers, no Meilisearch/Reverb). Keep
the nullable tenant_id seam; don't build tenancy. NOTE: real shared-host validation can't run in the container
— ship the probes + the host-compatibility checklist and flag live-host testing on ≥2 real shared hosts as a
manual follow-up (per the phase-1-plan risk table). Strict clean-room; security-by-default (installer especially).
When M5 lands runnable + tested, report back here.
```

---

## When M5 reports back — Phase 1 / MVP is complete

The Cowork session reviews M5 against the **six exit criteria** (with particular scrutiny on the **installer's
post-install lock** and input validation — it's the highest-risk surface — and the **backup→restore
round-trip**), then writes a **Phase 1 completion / release-readiness summary**. The next owner decision is
strategic, not a milestone: **Phase 2** (Community — reactions, PMs, digest notifications, activity feeds,
the visual theme configurator) **vs. a Phase 5 hardening/release pass** (external security review, full WCAG
2.1 AA audit, i18n completeness, load testing on both tiers) before a public 1.0. I'll prep that decision
packet when M5 lands.
