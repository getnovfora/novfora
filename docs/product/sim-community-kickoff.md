<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The Hearth Authors
-->
# Simulated community ("living demo board") — Claude Code kickoff

> Owner goal: a separate Hearth install that **simulates organic growth to critical mass** — personas
> joining over time, starting threads, replying — both as a realism/load harness and a living demo.
> Decisions made: runs on a **second cPanel subdomain** (baseline tier, NO SSH — everything must work via
> the web installer + the single cron line), **LLM corpus + budget-capped live replies**, **mid-community
> scale** (~200 users / ~800 threads / ~6,000 posts across ~6 simulated months).
> ⏳ Build AFTER RH-10 lands — separate branch + PR.

---

```
Build Hearth's community simulator: deterministic backfilled history + cron-driven ongoing activity,
safe-by-default, baseline-tier compatible (no SSH, no daemons). Separate branch + PR.

STEP 0: read PROJECT-STATE.md. Branch from current main (must include the merged theme + RH-10).
Commit identity per CLAUDE.md (Tommy Huynh <tommy@saturnhq.net>, DCO -s, no AI attribution).

PART 1 — SAFETY MODEL (build first; non-negotiable):
  • Simulation is HARD-DISABLED by default: every sim command/job refuses unless
    HEARTH_SIM_ENABLED=true AND a deliberate sim marker exists (storage/sim-enabled, written by
    `hearth:sim:enable --i-understand` only). Refuses on any install where it isn't both.
  • Every simulated user is flagged (users.is_simulated, reversible migration) and belongs to a
    "Simulated" group → a small "demo" badge renders on their names (honesty: the board never passes
    bots off as humans). A site notice setting marks the board as a living demo.
  • `hearth:sim:wipe` removes ALL simulated users + their content + recomputes counters; covered by a
    completeness test. The simulator ships inert in the product (flag-gated) — document why in the PR.

PART 2 — GROWTH PLAN (deterministic):
  • `hearth:sim:plan --seed=<n> --profile=mid` writes storage/sim-plan.json: ~200 personas (name, join
    day, interests mapped to the board's forums, activity weight on a 90/9/1 lurker power law, writing
    style tag), an S-curve join schedule over ~180 simulated days, thread/reply event timeline with
    daily + weekend rhythms. Same seed → same plan (testable).

PART 3 — BACKFILL (the past, in cron-sized chunks):
  • `hearth:sim:backfill` enqueues chunked jobs (drained by the EXISTING cron line — no SSH, no worker)
    that replay the plan: create users (verified, simulated-flagged), threads + replies through the real
    PostService with BACKDATED timestamps (created_at/last_posted_at per plan), realistic view_count
    seeding, trust levels recomputed. Resumable + idempotent (plan event ids), progress visible via
    `hearth:sim:status` and an admin panel line. Target: full mid profile completes within ~an hour of
    normal cron ticks; safe to re-run.
  • Content comes from the COMMITTED corpus (PART 4) — backfill needs no network/API.

PART 4 — CONTENT ENGINE:
  • database/sim/corpus/*.json — persona-tagged thread starters + replies per forum category, plus a
    local generator script (tools/sim/generate-corpus.php, run on a dev machine with ANTHROPIC_API_KEY)
    that (re)builds the corpus via the Claude API. Corpus is committed so installs never need a key.
  • LIVE replies (optional, off by default): when enabled (HEARTH_SIM_LIVE_API=true + key), the ongoing
    simulation may generate context-aware replies via the API for threads with recent human/persona
    activity — HARD budget caps (HEARTH_SIM_API_MAX_PER_DAY, default small), graceful fallback to
    corpus when capped/unreachable. Never blocks a request path; runs only inside queued jobs.

PART 5 — ONGOING LIFE (the present):
  • A scheduler hook (sim ticks via the cron line): each tick rolls persona actions from the plan's
    continuing curve — new threads, replies, occasional new joiners — at a configurable events/hour
    rate (default modest; shared-host-polite). All through the service layer.
  • Phase-2 of this tool (NOT now, note in docs): an external HTTP persona driver that exercises the
    real front door (register/login/post over HTTP) from an operator machine — valuable as an
    anti-spam/full-stack exercise; design hooks for it but do not build.

TESTS: plan determinism (seeded) · backfill chunk idempotency + resume · counters/search consistency
after backfill (forum/topic counts match generated content) · the production-refusal guard (disabled →
every sim command aborts) · wipe completeness · live-API budget cap respected (fake client).

DELIVER: branch + PR (conventional commits) · docs/SIMULATOR.md (enable → plan → backfill → live ticks →
wipe; the no-SSH subdomain recipe: install via wizard on the sim subdomain, set the env flags via cPanel
file manager, let cron do the rest) · suite + gates green · PROJECT-STATE updated. The release bundle is
unchanged in behavior for normal installs (flag-gated inert).

SCOPE FENCE: the simulator + its safety rails only. No theme changes, no Phase-2 features, no HTTP
persona driver (flagged as follow-up).
```

---

## Deployment recipe (owner, after merge — no SSH needed)
1. Create subdomain (e.g. `demo.adorablespider.com`) + a second database in cPanel.
2. Upload/extract the current release zip; run the web installer against the new DB.
3. cPanel file manager: add to `.env` → `HEARTH_SIM_ENABLED=true` (+ optional live-API key/caps).
4. Add the same single cron line for the new install path.
5. Run enable + plan + backfill via the admin sim panel (or the one-shot "start simulation" action) —
   then watch the board grow on cron ticks.
