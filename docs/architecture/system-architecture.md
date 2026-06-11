# System Architecture

> **Project:** NovFora (working codename). **Stage A deliverable** (Section 8 #4). **Date:** 2026-06-01.
> Covers the two deployment tiers, the service-tier detection + driver-abstraction contract, the cron-driven
> queue, email deliverability, the SEO subsystem, search tiering with a documented (illustrative) threshold,
> concrete performance budgets, and the practical-MVP → scalable-long-term path.
> Related ADRs: **ADR-0003** (tiers/detection), **ADR-0010** (search), **ADR-0011** (queue-via-cron),
> **ADR-0014** (email). Scale target: **medium now (~100k users, low-millions of posts), documented path to
> large** — see [stack recommendation](technical-stack-recommendation.md) for version choices.

---

## 1. One codebase, two tiers (the central principle — ADR-0003)

The **core forum is byte-for-byte identical on every tier.** Only performance/real-time *infrastructure*
changes. No baseline feature may hard-depend on Redis, a WebSocket server, a persistent worker, or an
external search engine. The app **detects available services and degrades gracefully** — it must never error
because an enhanced service is absent.

```
                 ┌─────────────────────────────────────────────────────────┐
                 │            NovFora application (identical code)            │
                 │  Laravel 13 · Livewire 4 · Alpine · Blade (server-rendered)│
                 └───────────────┬─────────────────────────┬───────────────┘
        capability contracts →   │  (config-selected drivers + tier detection) │
        ┌────────────────────────┴───────────┐   ┌─────────┴────────────────────────┐
        │        BASELINE (shared host)       │   │        ENHANCED (Docker/VPS)       │
        │  PHP 8.3+ · MySQL/MariaDB · cron     │   │  + Redis · queue worker(s) · Reverb │
        │  file|db cache · db queue (cron)     │   │  + Meilisearch/Typesense · S3/MinIO │
        │  MySQL FULLTEXT · Livewire polling   │   │  + SES/Postmark · read replica · CDN│
        │  local disk · host SMTP (best-effort)│   │                                     │
        └─────────────────────────────────────┘   └────────────────────────────────────┘
```

### 1.1 Driver-abstraction contract

Every environment-sensitive capability sits behind a Laravel contract with a baseline default and an enhanced
upgrade. Degradation behavior is specified, not incidental.

| Capability | Contract / driver | Baseline default | Enhanced | If enhanced absent |
|---|---|---|---|---|
| Cache | `CACHE_STORE` | `database` / `file` | `redis` | Use DB/file — slower, correct |
| Session | `SESSION_DRIVER` | `database` | `redis` | DB sessions (fine to ~100k users) |
| Queue | `QUEUE_CONNECTION` | `database` (cron-drained) | `redis` + worker | Jobs run on next cron tick (ADR-0011) |
| Search | `SCOUT_DRIVER` | `database` (MySQL FT) | `meilisearch`/`typesense` | DB full-text — see §4 threshold |
| Broadcast | broadcast driver | `null` → **Livewire polling** | `reverb`/`pusher` + Echo | Poll every N s — near-real-time |
| Files | `FILESYSTEM_DISK` | `local` | `s3`/`minio` | Local disk — fine until multi-node |
| Mail | `MAIL_MAILER` | `smtp` (host) | SES/Postmark/Mailgun | Host SMTP — best-effort (§3) |
| Image processing | sync vs queued | on-request / next cron | queued workers | Synchronous thumbnailing |

**Service-tier detection.** At install and at runtime (cached, with a manual "re-detect" in the admin panel)
NovFora probes each optional service — Redis `PING`, Meilisearch/Typesense `/health`, a Reverb handshake, S3
`HeadBucket`. The installer and an **Admin → System → Service Tier** panel display the **active tier per
capability** and an "enabling *X* unlocks *Y*" hint (e.g., "Add Redis → real-time queue + faster cache").
Detection failures **downgrade silently to the baseline driver** and log an info event — never a user-facing
error.

**Real-time without Redis (enhanced tier).** The Broadcast row's enhanced driver is **Laravel Reverb**. In
Laravel 13, Reverb can run with a **database/local scaling driver**, so the **enhanced tier can offer
WebSockets without requiring Redis** (Redis remains the recommended scaling backend only for multi-process /
multi-node Reverb). The binding constraint is different: Reverb needs a **persistent process (a daemon)**,
which the **baseline shared-host tier cannot run** — so **baseline stays on Livewire polling** regardless. In
short, the real-time split is about *whether you can run a daemon*, not *whether you have Redis*.

## 2. Request lifecycle, caching & the cron scheduler

- **Server-rendered Livewire** components produce full HTML (SEO-safe). Interactive islands (editor,
  drag-drop, theme configurator) are Alpine + prebuilt JS with `wire:ignore`.
- **Caching layers:** (1) config/route/view caches (build-time); (2) an **object cache** for hot, expensive
  reads — resolved permission masks (ADR-0006), settings, the forum tree, per-user unread state;
  (3) **fragment/response caching** for rendered post HTML and guest thread views (cacheable because content
  is sanitized at write time — see [data-model](data-model-initial.md) ADR-0005). Baseline uses file/DB cache;
  enhanced uses Redis. All cache use is **read-through with graceful miss** — correctness never depends on a
  cache hit.
- **The scheduler is the heartbeat.** A single cron entry — `php artisan schedule:run` — drives everything:
  queue draining, digest emails, search (re)indexing, cleanup, backups, bounce processing. On enhanced hosts
  it runs **every minute**; on baseline hosts that only allow coarse cron, it **tolerates 5–15-minute
  intervals** (ADR-0011). The only getting-started requirement on a shared host is *one cron line*.

## 3. Queue via cron (ADR-0011) — making "no daemon" work

Baseline hosts cannot run `queue:work` as a daemon. Design:

- Jobs enqueue to the **database** queue. The scheduler runs a **bounded drain** each tick —
  `queue:work --stop-when-empty --max-time=55 --max-jobs=...` — so a job never outlives its cron window, and
  an **overlap lock** (`withoutOverlapping`) prevents concurrent drains.
- **Coarse-cron tolerance:** every user-visible async action (email, notification, thumbnail, index update)
  must be **correct with up to one cron-interval of latency** and **idempotent/retry-safe**. UI copy sets
  expectations ("you'll be emailed shortly"); nothing assumes sub-minute delivery on baseline.
- **Enhanced tier** simply swaps `QUEUE_CONNECTION=redis` and runs real workers — same job classes, lower
  latency. No code change.

## 4. Search architecture (ADR-0010) & the tier threshold

- **One abstraction (Laravel Scout), two backends.** Baseline indexes into **MySQL/InnoDB FULLTEXT**;
  enhanced indexes into **Meilisearch/Typesense** (typo-tolerance, facets, far better relevance/latency).
  Re-indexing is a queued job (cron-driven on baseline). The search *UX* (inline predictive results, filters,
  "similar topics", "what's new") is identical on both.
- **Documented switch-over threshold — illustrative, not a constant.** Per the
  [evidence](../research/community-complaints-and-feature-requests.md) (MySQL FT degrades ~60× as rows grow
  ~32×; multi-predicate queries can stall), operators need guidance on *when* to move to the enhanced tier.
  **As a directional guideline — dependent on hardware, schema, average post length, and concurrency, not a
  universal cutoff:**

  | Corpus size (posts) | Typical baseline (shared host / 1–2 vCPU, InnoDB FULLTEXT) | Recommendation |
  |---|---|---|
  | up to ~100k | search p95 generally acceptable | MySQL full-text is fine |
  | ~100k–500k | relevance/latency begins to degrade, esp. with filters | acceptable; start watching |
  | ~500k–1M+ | p95 latency & relevance degrade materially | **recommend Meilisearch (enhanced tier)** |

  These numbers are **illustrative** (one class of hardware/config); the **authoritative signal is the live
  metric**, not the table. The admin panel surfaces a **rolling search-latency indicator (p95)** and post
  count, and prompts "your forum may benefit from the enhanced search tier" when p95 crosses a configurable
  threshold (default ~750 ms). No hard dependency on an external engine ever exists at baseline.

## 5. Email & deliverability (ADR-0014)

Email is where self-hosted forums quietly fail (verification mails in spam, bounces ignored). Design:

- **Provider abstraction:** Laravel Mail transport — `smtp` (host) by default; **SES / Postmark / Mailgun /
  SendGrid** on the enhanced tier via config only. A pluggable transport keeps providers swappable.
- **Authentication guidance (DNS, documented in the install guide):** **SPF**, **DKIM**, and **DMARC** are
  DNS/server concerns NovFora cannot set for the operator, but the installer's email step and the admin panel
  link a concrete checklist and run a **self-test send + DNS lookup** that warns if SPF/DKIM/DMARC records are
  missing or misaligned.
- **Bounce & complaint handling:** enhanced providers deliver bounce/complaint **webhooks** → a suppression
  list (hard-bounced/complained addresses are flagged and excluded from future sends; the user is prompted to
  re-verify). Baseline fallback: an optional **cron-polled IMAP bounce mailbox**, or—if unavailable—manual
  review; either way a **suppression list** protects sender reputation.
- **Volume hygiene = deliverability:** granular per-event email preferences and **digest emails** (configurable
  rollups) cut volume; every message carries a one-click unsubscribe and uses a consistent envelope/From.
- **Honest baseline note (required by the brief):** on the **baseline tier, shared-host SMTP email is
  best-effort.** Shared IPs, missing DKIM, and no bounce webhooks mean deliverability can be poor; the admin
  panel says so plainly and recommends a transactional provider (Postmark/SES) as the single highest-value
  enhanced upgrade for any community that relies on email verification.

## 6. SEO subsystem (addresses complaint C5)

Server-rendering solves the hardest part; the rest is deliberate:

- **Canonical URLs** with human-readable slugs; one canonical per content item even across pagination.
- **`schema.org` `DiscussionForumPosting`** (JSON-LD) on threads/posts; Open Graph + Twitter cards.
- **XML sitemaps with smart filtering:** auto-generated, **`noindex` on empty containers** (forums/categories
  with no content) to protect crawl budget; only above-threshold content is included.
- **Importer redirect maps:** every importer emits **301 redirect maps** from legacy URLs to preserve link
  equity (the migration failure mode that cost real forums ~95% of search traffic). See
  [plugin-and-theme-system](plugin-and-theme-system.md) (importers) and [data-model](data-model-initial.md).

## 7. Performance budgets (concrete targets at medium scale)

Design budgets at **~100k users / low-millions of posts**, enforced in CI (query-count assertions in feature
tests) and verified by profiling. **Targets, not measurements** — they set the bar and catch regressions.

| Metric | Baseline (shared host, file/DB cache) | Enhanced (VPS + Redis) | How enforced |
|---|---|---|---|
| Thread page server render (TTFB), p50 | < 350 ms | < 150 ms | profiling + cache hit |
| Thread page TTFB, p95 | < 800 ms | < 400 ms | profiling |
| DB queries / thread view | ≤ 30 (no N+1) | ≤ 30 | **CI query-count assertion** |
| DB queries / forum index | ≤ 15 | ≤ 15 | CI assertion |
| DB queries / prefix-filtered board (P2-M1) | ≤ 25 | ≤ 25 | CI assertion |
| DB queries / tag-filtered board (P2-M1) | ≤ 25 | ≤ 25 | CI assertion |
| DB queries / tag listing (`tags.show`, P2-M1) | ≤ 45 | ≤ 45 | CI assertion |
| Permission-mask cache hit rate | > 95% | > 99% | metric/event (ADR-0006) |
| Fragment cache hit (guest thread views) | > 80% | > 90% | metric |
| Base HTML (gz) | < 80 KB | < 80 KB | asset budget in CI |
| Base JS (gz, excl. editor island) | < 50 KB | < 50 KB | Vite budget |
| Editor island JS (gz, lazy-loaded) | < 180 KB | < 180 KB | lazy-load only on compose |
| Search query p95 | see §4 threshold | < 150 ms (Meili) | search metric |

Forums are **~95% reads**; the strategy is aggressive read-caching + tight query budgets, not write
optimization. N+1 is the default enemy — eager-loading and the query-count CI guard are mandatory.

## 8. Practical-MVP → scalable-long-term (the path)

The architecture **does not change** between MVP and large scale — only drivers and infrastructure do. That is
the whole point of ADR-0003.

| Stage | Shape | What changes |
|---|---|---|
| **MVP / small** (baseline) | Single shared host: PHP 8.3, one MySQL, file/DB cache, cron queue, MySQL FT search, Livewire polling, local media, host SMTP | nothing — ships at every milestone |
| **Growing** (enhanced, single box) | One VPS/Docker host: **+Redis** (cache/session/queue + real worker), **+Meilisearch**, **+Reverb**, **+S3/MinIO**, transactional email | config/env + compose services; **no code change** |
| **Large** (horizontal) | Stateless app behind a load balancer; session/cache in Redis; **MySQL read replica(s)** for read-heavy load; **CDN** for assets/media; queue workers scaled out; search on a dedicated Meili node | infra topology; app stays stateless by design (no local session/file assumptions on this tier) |

**Design rules that keep the path open** (decided now, even though only baseline is built first): the app is
**stateless** (no reliance on local session/file when a shared driver exists); all heavy work is **queueable**;
all reads are **cache-through**; the data model carries indexing/partitioning headroom and a nullable
`tenant_id` (see [data-model](data-model-initial.md)). Multi-tenant SaaS remains out of scope but is **not
precluded**.

## Sources & cross-refs

Search-degradation evidence and the −95%-traffic migration case:
[community-complaints](../research/community-complaints-and-feature-requests.md). Version/PHP basis:
[technical-stack-recommendation](technical-stack-recommendation.md). Permission cache, content sanitization,
and indexing detail: [security-and-permissions](security-and-permissions.md), [data-model](data-model-initial.md).
