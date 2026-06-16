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

## Phase 5 (P5.4) — baseline query profiling + the N+1 regression gate

The traffic harness above needs a running server + a k6/artillery binary, which an unattended build cannot
exercise at scale. What it CAN do deterministically — and what actually catches the perf bug that hurts a forum
(an N+1 that turns one page into hundreds of queries) — is **profile the query SHAPE** of the hot paths. P5.4
adds `tests/Feature/Performance/HotPathQueryTest.php`: it seeds a page-full of items with **distinct authors**
(so a naive per-row lookup would balloon) and asserts each surface runs a **bounded** query count that does not
scale with the item count.

### Captured baseline (sqlite gate DB; shapes are engine-independent)

| Surface | Items seeded | Queries | Verdict |
| --- | --- | --- | --- |
| Board index (`forums.index`) | 8 forums | **13 warm** (steady state) | bounded — no per-forum N+1 |
| Forum listing (`forums.show`) | 15 topics | **< 40** | bounded — eager-loads author/last-poster/prefix/tags |
| Topic page (`topics.show`) | 16 posts | **< 40** | bounded |
| Search (`search.index`) | 15 topics | **< 45** | bounded |
| Clubs directory (`clubs.index`) | 10 clubs | **< 45** | bounded — no per-club N+1 |

**No steady-state N+1 was found.** The board index's COLD first build runs more (~69 with 8 forums) because it
populates the 60s `forum.index.tree` fragment cache + warms the per-request resolver/ACL cache; that is
amortised to once per TTL, and the steady-state per-request cost is ~13. The hot-path query columns are indexed
(posts: `(topic_id, position)`, `(topic_id, created_at)`, `user_id`, `approved_state`; topics: `forum_id`;
forums: `parent_id`), so the bounded queries are also seek-friendly. The gate is wired into the normal `pest`
run, so a future change that drops an eager-load (reintroducing an N+1) fails CI.

### Enhanced-tier procedure + target thresholds (NOT run against a real enhanced host)

Run the traffic harness (§2) on representative hardware against **MySQL/MariaDB** (baseline) and then the
enhanced tier (Redis + Meilisearch + queue workers), comparing the same seed/script:

1. `php artisan novfora:loadtest:seed --forums=20 --topics=200 --posts=15 --users=500` on a throwaway DB
   (≈60k posts — a mid-size board). Seed in stages for larger boards.
2. `k6 run -e BASE_URL=… -e FORUMS=20 -e VUS=100 -e HOLD=5m load-tests/k6/browse.js` (or artillery).
3. **Suggested starting SLOs to validate/tune** (the scripts ship `p95 < 800ms`, `err < 1%` as placeholders):
   - Baseline shared host: guest board/topic reads **p95 < 600ms**, search **p95 < 1.5s** (DB-driver search
     latency grows with corpus — the expected trade-off; the fix is Meilisearch, not a baseline code change).
   - Enhanced (Redis + Meilisearch): reads **p95 < 250ms**, search **p95 < 300ms** and flat as the corpus grows.
4. **At-scale EXPLAIN (validate-before-go-live):** on the real MySQL host, `EXPLAIN` the forum-listing sort
   (`WHERE forum_id=? ORDER BY is_pinned DESC, last_posted_at DESC`) on a large forum. If a filesort dominates,
   a composite `(forum_id, is_pinned, last_posted_at)` index is the reversible fix — added only once the EXPLAIN
   justifies it (it is NOT added speculatively here, since the `last_posted_at IS NULL` ordering expression may
   prevent index-sort use and it cannot be validated without at-scale MySQL).

### Capacity guidance (rule-of-thumb, validate with the harness)

- **Baseline shared host (1 vCPU, cron queue, DB search):** comfortable for a small-to-mid community —
  low tens of concurrent readers, a corpus up to ~10⁵ posts before DB full-text search latency becomes the
  felt bottleneck. Enable page/fragment caching (already used on the board index) and a CDN for assets.
- **Move to the enhanced tier when** search p95 climbs past ~1s at your corpus size, the cron queue lags
  visibly, or concurrent readers exceed what one PHP-FPM pool serves under p95 — Redis cache + Meilisearch +
  queue workers flatten all three.

## Scope & honesty

The traffic harness still requires running it on representative hardware to produce latency SLOs — synthetic
numbers from an unattended build would be meaningless, so none are claimed. P5.4 adds the deterministic half:
the query-shape profiling gate above (run in CI) proves the hot paths are N+1-free, and the seeder feature test
(`tests/Feature/LoadTest/LoadTestSeedCommandTest.php`) covers the fixture at small scale. The driver scripts
remain static assets (no k6/artillery binary in the gate); the latency SLOs + the at-scale EXPLAIN are
explicit validate-before-go-live items.
