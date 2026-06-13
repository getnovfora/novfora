<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# Phase 3 — Admin analytics (B5)

> Privacy-conscious aggregate analytics computed daily on the baseline (cron) tier, with an admins-only
> dashboard. **Status: Accepted — owner-authorized overnight build; flagged for review (ADR-0035).**

## 1. Posture: aggregates only, no PII

`daily_metrics` stores one row per `(day, metric_key)` — an aggregate COUNT. There is **no per-user tracking,
no IP logging, no PII** anywhere in the subsystem. The metric set is a fixed, closed schema
(`AnalyticsService::METRICS`): `users_new`, `users_total`, `topics_new`, `topics_total`, `posts_new`,
`posts_total`, `active_users`.

## 2. Computation (baseline-safe)

```
AnalyticsService::rollup($date)        ── compute the day's figures, upsert (idempotent)
novfora:analytics:rollup               ── daily cron: finalise yesterday + refresh today
```

- **Idempotent** via `UNIQUE(metric_date, metric_key)` — the cron, a manual run, and `--backfill=N` are all
  safe to re-run.
- **Totals are as-of the end of the day** (`created_at <= end`), so a backfilled timeseries is historically
  correct, not just a "now" snapshot.
- Runs on a **daily cron** (overlap-guarded, skipped during a restore) — no worker required; the enhanced tier
  can run it more often if desired, with no code change.

## 3. Dashboard

*Admin → Analytics* (admins-only: `admin.access` + staff-2FA). Headline live totals (cheap counts) plus a
recent-days table (new members / topics / posts / active) from the rollup. A "Refresh today" action re-rolls on
demand.

## 4. Tests

`tests/Feature/Analytics/AnalyticsTest.php`: the rollup values + idempotency, the cron command, and dashboard
authz + aggregate display.

## 5. Follow-ups (flagged)

- Per-forum / per-category breakdowns; reaction + follow trends.
- Charting in the dashboard (currently a table); export.
