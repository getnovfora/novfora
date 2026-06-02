<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The Hearth Authors
-->
# M0 — Claude Code kickoff prompt (Phase 1, Skeleton & guardrails)

> Paste the block below into the **Claude Code** session to begin Phase 1 **M0**. The Phase 1 plan (M0–M5)
> is owner-approved (2026-06-01) and **Spike 0 is GO** (2026-06-02), so this is a build kickoff, not a new gate.
> Spec: [phase-1-plan.md](phase-1-plan.md) §1 (definition of done), §5 (M0), §6 (quality gates); the validated
> editor pattern + its 7 M2 notes live in §4 (for M2, **not** M0). ADRs: [DECISIONS.md](../../DECISIONS.md).

---

```
Begin Phase 1 — M0 (Skeleton & guardrails). The Phase 1 plan (M0–M5) is owner-approved; Spike 0 is GO.

START OF SESSION: read PROJECT-STATE.md, CLAUDE.md, docs/PROJECT-BRIEF.md (standing rule), then
docs/product/phase-1-plan.md — §1 (definition of done), §5 (M0), §6 (quality gates). Skim §4 (Spike 0
result + the 7 editor findings) but note those are M2, not M0. Relevant ADRs in DECISIONS.md: 0003
(service-tier detection), 0011 (cron queue), 0012 (editor — validated, for M2), 0015 (licensing).

MODEL/EFFORT: Opus 4.8 at xhigh. Think hard on the service-tier detection / driver abstraction
(ADR-0003) — the "detect available services and degrade gracefully, never error" contract is the spine
of the two-tier promise and is the part most expensive to get wrong.

Open with a SHORT M0 execution plan (how you'll merge a Laravel scaffold into the non-empty repo, the
tier-detection contract shape, and the CI jobs), then proceed — no need to wait, the plan is approved.

FIRST — commit the pending Cowork doc edits (clean baseline before scaffolding):
`git status` will show modified DECISIONS.md, docs/product/phase-1-plan.md, PROJECT-STATE.md, plus this
kickoff doc — the Cowork session folded the Spike 0 GO + findings into them. Review, then:
  git add -A
  git commit -s -m "docs: fold Spike 0 GO + findings into ADR-0012, phase-1-plan, PROJECT-STATE"
(HEAD's .gitignore already excludes hearth-spike/ heavy artifacts + runtime dirs. Leave hearth-spike/
as the reference scaffold; it is retired later in M2 once the real editor lands.)

THEN scaffold M0 at the REPO ROOT (this is production code, not the throwaway):
  • Laravel 13 + Livewire 4 + Alpine + Blade at the repo root. create-project needs care in a
    non-empty dir: scaffold in a temp dir and merge in, PRESERVING the existing docs/, the *.md
    governance files, LICENSE, .gitignore, and the git history. Add SPDX headers; small conventional
    commits with DCO sign-off.
  • Baseline config (ADR-0003/0011): database cache/session/queue drivers, Scout `database` driver,
    `smtp` mail — all working on PHP 8.3 + MySQL + cron, no daemons. Use MySQL now (a Docker MySQL
    service is fine; the spike's SQLite was spike-only). Keep a current .env.example.
  • Service-tier detection (ADR-0003): the detection contracts + detectors + an
    `Admin → System → Service Tier` panel that shows the active tier and what each enhancement
    (Redis / Meilisearch / Reverb / S3) would unlock. Wire the driver abstraction so the SAME code
    runs on the enhanced tier with no change.
  • Reversible-migration baseline + a backup command skeleton.
  • Vite build with PREBUILT ASSETS COMMITTED (the host needs no Node runtime).
  • CI: Pint, PHPStan/Larastan, Pest, Dusk, `composer audit`, and the query/asset/perf budgets
    (system-architecture §7). Stand up the harness for the two non-negotiable suites now
    (permission-mask truth-tables, service-tier forced-absence/fallback) even though their subjects
    land in M1+; the tier-fallback tests can begin in M0.

DO NOT build in M0: auth (M1), the permission-mask engine (M1), forum CRUD (M2), or the editor /
CanonicalRenderer (M2). Keep M0 to the skeleton + guardrails. The validated editor pattern ports in M2
per phase-1-plan §4 — do not carry hearth-spike/ in wholesale.

DEFINITION OF DONE (phase-1-plan §1, M0 slice): the app runs on the baseline tier (PHP 8.3 + MySQL +
cron, no daemons); the identical code path runs on the enhanced tier; CI guards are green; .env.example
is current; the reversible-migration + backup skeleton is in place. Land it runnable + tested, commit in
small conventional steps, update PROJECT-STATE, and report back here.

CONSTRAINTS: strict clean-room (no reference-forum code); progressive enhancement (no baseline feature
hard-depends on Redis / WebSocket / worker / external search); security-by-default (argon2id, CSRF,
strict CSP, rate limits, audit log); every dependency Apache-2.0-compatible (ADR-0015), recorded in
DECISIONS.md before merge. Commit between handoffs; keep PROJECT-STATE current.
```

---

## When M0 reports back

The Cowork session reviews the M0 result, updates PROJECT-STATE, and preps the **M1** kickoff (identity &
access + the permission-mask engine — the part flagged for deep reasoning in CLAUDE.md). Each milestone lands
runnable + tested on the baseline tier before the next begins.
