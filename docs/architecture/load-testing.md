# Load testing (Wave 8.3)

> **SCAFFOLDED — NOT VALIDATED.** This is a harness: a fixture seeder plus driver scripts and a procedure.
> No at-scale numbers have been measured or are claimed. Run it on your own hardware against your own target
> to get numbers that mean anything. ADR-0045.

A load test has two halves: **data** to read and **traffic** to read it. Both ship here; running them is a
deliberate, manual step (nothing here runs automatically or in the default test gate).

## 1. Seed a big board (data)

```bash
php artisan novfora:loadtest:seed --forums=5 --topics=40 --posts=8 --users=50
```

- Writes through the real `PostService`, so counters, last-post pointers and the search projection are all
  correct — the test then exercises **true query shapes**, not hand-built rows.
- **Additive & resumable:** forums (`loadtest-forum-N`) and users (`loadtest{N}@example.test`) are keyed by a
  stable slug/email; re-running with larger counts grows the dataset, it does not duplicate.
- Defaults produce ~200 topics / ~1,800 posts. Scale the options to your target — seeding time is roughly
  linear in total posts because it uses the real write path. For very large boards, seed in stages.
- Run it against a **staging/throwaway database**. It creates obvious `Load Test` content; do not point it at
  production data you care about (the production confirmation prompt guards this; `--force` bypasses it).

To remove the fixture afterwards, drop the `loadtest*` forums/users or restore the staging DB from backup.

## 2. Drive traffic (load)

Two interchangeable drivers hit the same guest read surfaces — board index, a forum listing, a topic, and
search. Both are **read-only and guest-only**: safe to point at a staging copy; neither logs in or writes.

### k6

```bash
k6 run -e BASE_URL=https://staging.example -e FORUMS=5 -e VUS=50 -e HOLD=2m load-tests/k6/browse.js
```

Env knobs: `BASE_URL`, `FORUMS` (match the seeder), `VUS`, `RAMP`, `HOLD`.

### Artillery

```bash
BASE_URL=https://staging.example artillery run load-tests/artillery/browse.yml
```

The thresholds/SLOs in both scripts (`p95 < 800ms`, `error rate < 1%`) are **placeholders to tune**, not
validated targets. A breach makes the tool exit non-zero, so either driver can gate a CI/cron job once you
have set real numbers.

## 3. What to measure & expect by tier

Capture p50/p95/p99 latency and error rate per endpoint, plus DB time. Interpret against the deployment tier:

- **Baseline (shared host, cron-only).** No Redis/worker/Meilisearch. Search uses the Scout **database**
  driver, so search latency grows with corpus size — the load test will show this directly; it is the
  expected trade-off, and the path to fix it is the enhanced tier, not a baseline code change. Queue work is
  drained by cron, so write-then-side-effect latency is not real-time. Add a DB index or tune pagination only
  where the numbers justify it.
- **Enhanced (Docker/VPS).** Redis cache + queue workers + Meilisearch + Reverb. Search and cache-backed
  reads should be markedly faster and flatter as the corpus grows. Compare the same seed/script across both
  tiers to quantify the upgrade.

## Scope & honesty

This wave delivers the harness only. It does **not** include a tuned baseline, captured results, or a
performance SLO — those require running it on representative hardware, which is out of scope for an unattended
build and would be meaningless as synthetic numbers. The deliverable is: a correct fixture seeder, two driver
scripts, and this procedure. Tested: the seeder is covered by a feature test
(`tests/Feature/LoadTest/LoadTestSeedCommandTest.php`) at small scale; the driver scripts are static assets
(no k6/artillery binary in the gate).
