<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# Architecture Overview

Top-level map of **NovFora**, a self-hosted forum platform. This is the living index; the
authoritative depth lives in `docs/architecture/` and the decision log in [DECISIONS.md](DECISIONS.md).

## The shape in one picture

```
Laravel 13 · Livewire 4 · Alpine · Blade  — server-rendered (SEO-safe on every tier)
        │
        ├─ Permission-mask ACL (ALLOW/NO/NEVER, global→thread, roles, cached)      [ADR-0006]
        ├─ Canonical content (structured + sanitized HTML cache + text)            [ADR-0005]
        ├─ First-class anti-spam (trust levels ↔ ACL, blocklist, CAPTCHA, rates)   [ADR-0007]
        ├─ Module/hook/slot API + dual theming — semver'd, no core edits           [ADR-0008/0009]
        └─ Capability contracts (cache/queue/search/broadcast/files/mail)          [ADR-0003]
                 │
   ┌─────────────┴──────────────┐
   ▼                            ▼
 BASELINE (shared host)      ENHANCED (Docker/VPS)
 PHP 8.3 · MySQL · cron      + Redis · workers · Reverb · Meilisearch · S3 · SES
 file/db cache · db-queue    (same code; drivers + infra differ; detect & degrade)
```

## Principles (non-negotiable)

1. **One codebase, two tiers.** The core forum is identical everywhere; only performance/real-time
   infrastructure scales. **No baseline feature hard-depends on Redis, a WebSocket server, a worker, or an
   external search engine** — detect available services and degrade gracefully. ([system-architecture](docs/architecture/system-architecture.md))
2. **Progressive enhancement via driver abstraction.** Cache/queue/search/broadcast/filesystem/mail are
   swappable; a service-tier detector surfaces the active tier and never errors on absence.
3. **phpBB-grade permissions as the spine.** Three-state ALLOW/NO/NEVER resolution across a scope hierarchy,
   with a "why can/can't X" inspector. ([security-and-permissions](docs/architecture/security-and-permissions.md))
4. **Extensibility without forking.** Modules and themes extend via events/hooks/slots and override layers —
   **never core edits** — behind **semver'd public contracts**. ([plugin-and-theme-system](docs/architecture/plugin-and-theme-system.md))
5. **Anti-fragility on the four incumbent failures:** spam, search, upgrade/theming breakage, migration —
   each has a deliberate subsystem.
6. **Security & a11y by default.** OWASP baseline, server-side sanitized rich text, reversible migrations;
   WCAG 2.1 AA and i18n/RTL designed in, not bolted on.
7. **Strict clean-room + Apache-2.0.** Concepts and schemas studied; everything implemented from scratch.

## Document index

| Area | Doc |
|---|---|
| Stack & versions, rejected SPA, dependency licensing | [technical-stack-recommendation](docs/architecture/technical-stack-recommendation.md) |
| Tiers, detection, queue-via-cron, email, SEO, **performance budgets**, scale path | [system-architecture](docs/architecture/system-architecture.md) |
| Schema, **canonical content storage**, `tenant_id`, indexing/partitioning, i18n | [data-model-initial](docs/architecture/data-model-initial.md) |
| Module API + **dual theming** + **importers** | [plugin-and-theme-system](docs/architecture/plugin-and-theme-system.md) |
| **Permission-mask engine** + **anti-spam ADR** + moderation + OWASP | [security-and-permissions](docs/architecture/security-and-permissions.md) |
| Test pyramid, permission & tier-fallback testing, CI | [testing-strategy](docs/architecture/testing-strategy.md) |
| Research base | [forum-platform-comparison](docs/research/forum-platform-comparison.md), [community-complaints](docs/research/community-complaints-and-feature-requests.md) |
| Product | [mvp-scope](docs/product/mvp-scope.md), [roadmap](docs/product/roadmap.md), [feature-prioritization](docs/product/feature-prioritization.md) |
| Decisions | [DECISIONS.md](DECISIONS.md) |
