<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# RH-10 — No-SSH upgrade: make "it migrates automatically" true — Claude Code kickoff

> **Finding (RH-10):** `docs/getting-started.md` §5 promises "deploy the new version (it migrates
> automatically)" — but nothing implements it. The only `migrate` call is in `InstallRunner` (install
> time); the scheduler has no upgrade task. A no-SSH operator who extracts a new release over a live
> install runs **new code against the old schema** — concretely: the themed bundle's appearance-settings
> migration would 500 every signed-in page until someone migrates, and there is no way to migrate without
> SSH. This is a **beta gate** and blocks the themed live deploy.
> ⏳ Run AFTER the theme polish round (PR #3) merges. Branch + PR. The bundle built after THIS merges
> (theme + polish + RH-10) is the one that ships — its live deploy is the mechanism's first validation.

---

```
Implement NovFora's no-SSH upgrade mechanism: cron-driven, backup-first, maintenance-safe automatic
migration — keeping the documented promise on the baseline tier. Branch + PR. No product features.

STEP 0: read PROJECT-STATE.md, docs/product/real-host-findings.md, routes/console.php (the scheduler),
app/Install/* (backup/restore + installer patterns). Branch from main WITH the merged theme work.
Commit identity per CLAUDE.md (Tommy Huynh <tommy@saturnhq.net>, DCO -s, no AI attribution). Log the
finding as RH-10 in real-host-findings.md (the docs promised it; nothing implemented it).

THE MECHANISM (design for the baseline tier: cron is the only reliable executor; no SSH ever):

1) DETECTION — cheap and boot-safe:
   • Detect "deployed code has pending migrations" without a per-request DB-heavy check (perf budget):
     a cached schema-state flag refreshed by the scheduler tick and invalidated by a release/build
     marker change is one acceptable shape — your design, but request paths must stay O(cache-read).
   • Expose state on GET /health: e.g. "schema": {"pending": bool, "upgrading": bool} — no secrets.
     (This is also how the owner and Cowork verify the live upgrade remotely.)

2) THE UPGRADE RUN (a scheduled task, every minute, withoutOverlapping + a cache lock so it can never
   double-run):
   When pending migrations are detected, in strict order:
   a. Enter maintenance (friendly branded 503 — never raw SQL errors during the window).
   b. Take a backup (the existing novfora:backup pipeline) — the pre-upgrade restore point. Failure to
      back up ABORTS the upgrade (stay pending, surface loudly).
   c. php artisan migrate --force (via the Migrator/Artisan in-process).
   d. Clear/refresh the relevant caches (compiled views are content-hashed already; config isn't cached
      on baseline; flush the schema-state flag).
   e. Exit maintenance. Audit-log the whole run (versions, batch, duration); admin System panel shows
      the last upgrade result.
   • Mind shared-host cron realities: a long migration must survive coarse cron (resume/idempotent if
     the process is killed mid-run — migrations are transactional per-migration; the task re-entering
     on the next tick must do the right thing).

3) REQUESTS DURING THE WINDOW: from the moment new code is deployed until the upgrade completes,
   signed-in pages touching new columns would 500 — instead, when the pending flag is set, serve the
   maintenance response (with Retry-After). Guests reading cached-safe public pages MAY pass through if
   trivially safe; otherwise maintenance for all. Window on a 1-minute cron ≈ ≤2 minutes.

4) FAILURE PATH (never silently half-upgraded):
   • Migration failure → attempt rollback of the failed batch (reversible migrations are a project hard
     rule), STAY in maintenance, audit-log + health shows "upgrading: stuck", and the maintenance page
     shows an operator hint (the pre-upgrade backup name + docs link). No retry loop — one retry max,
     then hold for the operator.
   • Document the no-SSH recovery in docs: re-upload the previous release zip (code now matches the
     rolled-back schema) or restore the pre-upgrade backup via the admin Backups panel.

5) OPERATOR CONTROLS:
   • NOVFORA_AUTO_UPGRADE=true by default (the documented promise). Setting false = manual mode: the
     admin System panel gains an "Apply pending migrations" action (admin.access + 2FA + confirm) —
     same pipeline, human-triggered. (Auto mode is what saves the operator when new columns break
     signed-in pages — note that asymmetry in the docs.)
   • An ADR recording the upgrade strategy + its trade-offs (auto-by-default on baseline, the window,
     the failure policy).

TESTS (feature-level, driving the scheduled task directly): detection on/off · lock prevents concurrent
runs · ordering backup→migrate (backup failure aborts) · maintenance entered/exited · failure → rollback
+ maintenance retained + health "stuck" · AUTO_UPGRADE=false → no auto run, admin action works · /health
schema block · requests during pending window get 503-maintenance, not SQL errors.

DOCS: getting-started §5 updated so the promise matches reality (window behavior, the toggle, recovery);
REAL-HOST-VALIDATION runbook gains an "upgrading a live no-SSH install" section; real-host-findings RH-10
entry → FIXED; PROJECT-STATE updated.

DELIVER: branch + PR, suite + all gates green (Pest/Pint/Larastan/audit; Dusk unaffected), then —
AFTER this merges on top of the theme — rebuild scripts/build-release.sh + verify and report the bundle
size + sha256: that artifact (theme + polish + RH-10) is the next live deploy, and deploying it onto the
current live site IS the mechanism's first real-world validation (the appearance migration applies
itself via cron).

SCOPE FENCE: the upgrade mechanism, its tests, docs, and admin surface only. No theme changes, no
Phase-2 features, no installer changes.
```

---

## Live validation script (owner + Cowork, after deploying the resulting bundle)
1. Upload/extract the new zip over the live install (no other action).
2. Within ~2 minutes: site may briefly show the branded maintenance page.
3. `GET /health` → `schema.pending` flips true → false; backups list shows the pre-upgrade snapshot.
4. Sign in → Settings → Appearance works (the new columns exist) → toggle dark/density.
5. Admin → System shows the upgrade run record; audit log has the entry.
