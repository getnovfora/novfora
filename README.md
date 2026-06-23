<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# NovFora

> **NovFora** is an open-source, **self-hosted** forum and
> community platform on a modern PHP stack. It combines the proven fundamentals of phpBB/MyBB/SMF with the
> polish of XenForo/Invision and the accessible customization of ProBoards — and deliberately fixes the pain
> points all of them share: **spam, weak search, dated mobile UX, upgrade-breaking add-ons, theming that needs
> core edits, SEO gaps, and fragile migration.**

**License:** [Apache-2.0](LICENSE) · **Status:** **1.0.0 — generally available.** Self-hosted and Apache-2.0,
running on the **baseline tier** (PHP 8.3 + MySQL/MariaDB + cron) with optional **enhanced-tier** services
(Redis, Meilisearch, Reverb, S3) detected and used automatically. See [CHANGELOG.md](CHANGELOG.md) and
[ROADMAP.md](ROADMAP.md).

## Why NovFora

It runs on the hosting ordinary forum operators actually have **and** feels modern:

- **Installs on a commodity shared PHP host** (PHP 8.3+, MySQL/MariaDB, cron) via a **web installer that needs
  no SSH** — or on Docker/VPS for the enhanced tier. **One codebase, two tiers.**
- **phpBB-grade permissions** — three-state allow/deny/never masks across categories→forums→threads, with an
  admin "why can/can't this user do X" inspector.
- **WYSIWYG-first editor** (TipTap-class), Markdown optional, BBCode as an import/compatibility layer.
- **First-class anti-spam** — trust levels wired into the permission engine, crowdsourced blocklist,
  swappable CAPTCHA, rate limiting — because spam is the #1 thing that kills forums.
- **Search that scales** — MySQL full-text on the baseline tier, Meilisearch on the enhanced tier, one UX.
- **No-core-edit theming** — point-and-click visual configurator *and* a developer Blade override layer.
- **Upgrades that don't break you** — reversible migrations, and module/theme APIs as **semver'd public
  contracts** with pre-upgrade compatibility checks.
- **Migration that preserves your community** — resumable phpBB/MyBB/SMF importers with attachment
  verification, password preservation, and SEO redirect maps.

## Two tiers, one codebase

| | Baseline (shared host) | Enhanced (Docker/VPS) |
|---|---|---|
| Cache/queue | file/db · **db queue via cron** | Redis · workers |
| Search | MySQL full-text | Meilisearch/Typesense |
| Real-time | Livewire polling | Reverb/Pusher WebSockets |
| Media / email | local disk · host SMTP | S3/MinIO · SES/Postmark |

The app **detects available services and degrades gracefully** — it never errors because an enhanced service
is absent.

## Documentation

- **User & admin guides:** **novfora.com/docs** — owner/administrator, moderator, and member documentation.
- **Architecture:** [ARCHITECTURE.md](ARCHITECTURE.md) · `docs/architecture/`
- **Decisions (ADRs):** [DECISIONS.md](DECISIONS.md)
- **Product:** [mvp-scope](docs/product/mvp-scope.md) · [roadmap](docs/product/roadmap.md) ·
  [feature-prioritization](docs/product/feature-prioritization.md)
- **Research:** [platform comparison](docs/research/forum-platform-comparison.md) ·
  [community complaints](docs/research/community-complaints-and-feature-requests.md)

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md), [GOVERNANCE.md](GOVERNANCE.md), and
[CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md). We use Conventional Commits and **DCO** sign-off; tests ship with
every feature; strict **clean-room** (no code/assets from any reference forum).

## Getting started

NovFora installs on a commodity shared host through a **no-SSH web installer** — upload the release, point your
domain at `public/`, and complete the four-step wizard (system check + setup token → database → site & admin →
install). Add one cron line and you're live. Owner/administrator, moderator, and member guides are published at
**novfora.com/docs** (source in the NovFora docs repo); the shared-host + Hostinger walkthrough is in
[docs/REAL-HOST-VALIDATION.md](docs/REAL-HOST-VALIDATION.md).
