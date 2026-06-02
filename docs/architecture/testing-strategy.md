# Testing Strategy

> **Project:** Hearth (working codename). **Stage A deliverable** (addition beyond Section 8, requested at
> Checkpoint 1). **Date:** 2026-06-01. The test pyramid, the **dedicated testing of permission-mask
> resolution and service-tier fallbacks** (brief hard rules), fixtures, and CI. Principle from the brief:
> **no feature is "done" without tests.**

---

## 1. The pyramid & tools

| Layer | Tool | Scope | Speed |
|---|---|---|---|
| **Unit** | **Pest / PHPUnit** | Pure logic, no/the-minimum framework: permission resolution, content sanitization, BBCode→canonical, trust-promotion rules, warning-point math, anti-spam threshold logic | fast (ms) |
| **Feature / integration** | **Pest** (full app + DB) | HTTP + **Livewire component** tests: register (with anti-spam), post via editor pipeline, moderation actions, ACL-enforced routes, queue jobs, search indexing, installer steps | medium |
| **Browser / E2E** | **Laravel Dusk** (headless Chrome) | Real-browser journeys: register→verify→**WYSIWYG post**, inline moderation, theme configurator, the permission inspector, PWA basics | slow |

**Supporting gates:** **Larastan/PHPStan** (static analysis), **Laravel Pint** (style), `composer audit`
(dependency CVEs), and **asset/query budgets** (below). Dusk is non-negotiable for the **WYSIWYG↔Livewire
island** (the #1 technical risk, ADR-0012) — only a real browser exercises the editor's JS + `wire:ignore`
boundary.

## 2. Permission-mask resolution — dedicated tests (brief hard rule)

The ACL engine ([security §1](security-and-permissions.md)) is tested as a **data-driven truth table**, not
incidentally:

- **Scenario matrix:** every meaningful combination of `{ALLOW, NO, NEVER}` × `{user, primary group, secondary
  group}` × `{global, category, forum, thread}`, each row asserting the expected verdict. Explicit cases:
  - **NEVER is absolute** — a group NEVER beats a user ALLOW at a more-specific scope.
  - **local overrides global**; **user overrides group**; **most-permissive group wins** (absent NEVER).
  - **deny-by-default** when nothing grants.
  - **role expansion** yields the same result as equivalent raw entries.
  - **multi-group conflict** (one ALLOW, one NEVER) → DENY.
- **The inspector is the oracle:** tests assert not just the boolean but the **resolution trace**
  ([security §1.4](security-and-permissions.md)) — which holder/scope/value decided it — so a regression
  pinpoints itself.
- **Cache correctness:** assert that a group/ACL/role change **bumps the version and invalidates** the cached
  mask (stale-read regression guard).
- **Every reported permission bug becomes a permanent row** in the matrix.

## 3. Service-tier fallbacks — dedicated tests (brief hard rule + Checkpoint-1 ask)

Graceful degradation is **proven by running the same suite under multiple driver configurations**, not assumed:

- **CI driver matrix** runs the feature suite twice:
  - **Baseline profile:** `cache=database|file`, `session=database`, `queue=database`, `scout=database`
    (MySQL FT), `broadcast=null` (polling), `filesystem=local`, `mail=array`.
  - **Enhanced profile:** `cache=redis`, `queue=redis` + worker, `scout=meilisearch`, `broadcast=reverb`,
    `filesystem=s3/minio`.
- **Forced-absence tests:** with an enhanced profile configured, **simulate the service being down** (Redis
  refused, Meilisearch 503, Reverb unreachable, S3 error) and assert the app **falls back to the baseline driver
  and does not error** — the core rule of ADR-0003. e.g. *"Meilisearch down → Scout DB driver serves results"*;
  *"Redis down → DB cache used, request succeeds."*
- **Queue-via-cron path:** enqueue a job → run `schedule:run` / a bounded `queue:work --stop-when-empty` →
  assert it processed, is **idempotent on re-run**, and respects the **overlap lock** (ADR-0011).
- **Coarse-cron tolerance:** assert async outcomes are correct with simulated multi-minute scheduler intervals.

## 4. Fixtures & test data

- **Factories** for every model; **seeders** producing a realistic demo community (categories→forums→topics→
  posts, users, groups, the full permission catalog). The same seed powers the **getting-started demo**, so the
  "runnable at every milestone" promise is continuously exercised.
- **Named permission fixtures** (e.g., *"moderator in Forum A only"*, *"TL0 new user"*) reused across ACL tests.
- **Importer fixtures:** small, sanitized **legacy phpBB/MyBB/SMF DB dumps + file sets** drive importer
  integration tests asserting the **dry-run plan, attachment verification, password-rehash-on-login, and
  redirect-map** outputs (ADR-0013).
- **Security corpora:** an XSS payload set against the sanitizer; malformed-upload set; CSRF/authz negative tests.

## 5. Performance & contract guards

- **Query-budget assertions** in feature tests enforce the [system-architecture §7](system-architecture.md)
  budgets (≤30 queries/thread view, ≤15/forum index) — N+1 regressions fail CI.
- **Asset budgets** in Vite/CI (base JS < 50 KB gz; editor island lazy-loaded).
- **Public-API contract tests:** a guard that fails if a **module/theme public signature, event payload, or
  slot name changes** without a major-version bump — enforcing the semver contract (ADR-0008/0009). A **sample
  module + theme** is installed→enabled→exercised→disabled in CI, asserting **clean migration rollback**.
- **Load tests** (k6 / ab) on **both tiers** in Phase 5 (hardening), validating the budgets under concurrency.

## 6. CI pipeline (GitHub Actions)

Matrix: **PHP {8.3, 8.4} × DB {MySQL 8, MariaDB, PostgreSQL} × driver-profile {baseline, enhanced}**. Steps:
`pint --test` → `phpstan` → `composer audit` → **Pest unit+feature** (with coverage) → **Dusk** (headless) →
asset/query budgets. Branch protection requires green. **A PR template checkbox enforces "tests included"** —
operationalizing "no feature done without tests."

## 7. What must never ship untested

Permission-mask resolution · service-tier fallbacks · content sanitization (XSS) · anti-spam register/post flow
· importer fidelity (attachments/passwords/redirects) · reversible migrations (up **and** down) · the module
compatibility check. These are the subsystems where a silent regression is most expensive — they carry
**mandatory, dedicated suites**.

## Cross-references

[security-and-permissions](security-and-permissions.md) · [system-architecture](system-architecture.md) ·
[plugin-and-theme-system](plugin-and-theme-system.md) · [data-model](data-model-initial.md).
