<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# Changelog

All notable changes to **NovFora** are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); NovFora adheres to [Semantic
Versioning](https://semver.org/spec/v2.0.0.html). The **module and theme APIs are semver'd public
contracts** — a breaking change to either is a major-version event.

## [1.0.0] — 2026-06 (Public 1.0)

First public release. Self-hosted, Apache-2.0, runs on the **baseline tier** (PHP 8.3 + MySQL/MariaDB +
cron, no daemons) and lights up the **enhanced tier** (Redis, queue workers, Meilisearch, Reverb, S3) with no
code change. Brand is **NovFora** end-to-end (the "Hearth"/"NevoBB" codenames are retired; enforced by a CI
brand gate).

### Core (Phase 1 / 1.5)
- Categories → forums → topics → posts with a **phpBB-grade permission-mask engine** (ALLOW/NO/NEVER,
  global→thread scope, role presets, group merge, a "why can/can't X" inspector).
- **WYSIWYG-first editor** (canonical JSON → server-sanitised HTML), Markdown mode, BBCode import layer.
- **No-SSH web installer** (tier detection, setup token) + **reversible migrations** + cron-driven
  **automatic upgrade** (backup-first, maintenance-gated) + portable backup/restore.
- First-class **anti-spam** (trust levels ↔ ACL, StopForumSpam, CAPTCHA abstraction, honeypot, rate limits),
  moderation queue, email + in-app notifications, mobile-first theme, Scout DB search, SEO basics.

### Community (Phase 2)
- Reactions, profiles + custom fields, multi-participant PMs, digest emails, reports,
  warnings/infractions, **trust-level auto-promotion**, activity feeds, inline + bulk moderation, drafts,
  edit history, oEmbed, the social pack (follow + reputation + badges), members directory + leaderboard.

### Extensibility (Phase 3)
- **Module/plugin API** (semver'd, event/filter/slot hooks, manifest, lifecycle) with no core edits;
  **visual theming + layout configurator** + a sandboxed (data-only) template editor; **REST API** (`/api/v1`,
  hashed tokens, engine-authorized) + **outbound webhooks** (HMAC, SSRF-guarded); **phpBB/MyBB/SMF/XenForo
  importers** (idempotent, attachment-verified, 301 redirects); privacy-conscious admin analytics.

### Advanced (Phase 4)
- **Clubs** (sub-communities, scoped permissions, no-leak privacy fence); **SSO** (OAuth Google/GitHub/
  Discord; SAML scaffold); **PWA + Web Push**; enhanced-tier **Meilisearch** + **Reverb** realtime (channel
  authz no-leak) + opt-in presence; **paid memberships** (perk gating through the engine; offline/manual
  provider is the live-granting path; **Stripe hosted checkout shipped with charging DISABLED**); advanced
  **anti-spam intelligence** (HOLD-only, FP-guarded) + a content-privacy fence.

### Hardening + release readiness (Phase 5)
- **Security:** two full adversarial reviews (verify-then-refute). The Phase-5 pass fixed the search-facet
  club-name leak, SSO staff-2FA step-up, OAuth registration-gate bypass, the API locked-topic + maintenance
  gates, the installer DB-test SSRF token gate, Stripe payment-proof + DB-unique idempotency, the @mention
  fan-out cap, and the importer path-traversal fence (ADR-0046, ADR-0072).
- **Accessibility:** automated WCAG 2.1 AA page gate across 27 surfaces + a manual checklist (ADR-0044).
- **i18n:** native-Laravel localisation framework + RTL, a complete `en` base for the visitor-facing surface,
  and a Spanish (`es`) proof locale; partial locales fall back to `en` (ADR-0043, ADR-0073).
- **Performance:** a hot-path query-count regression gate (no steady-state N+1s) + a documented load-test
  procedure and capacity guidance (ADR-0045, ADR-0074).

### ⚠ Validate before relying on (no real service/credentials in the build env)
Meilisearch, Reverb realtime, **live Stripe payments**, OAuth/SAML providers, Web Push delivery, and the
StopForumSpam submission API are **scaffolded and unit-tested but NOT validated against a live service** — see
`PROJECT-STATE.md → VALIDATE-BEFORE-GO-LIVE` and each ADR's enable steps. Enhanced-tier load numbers were not
captured against a real host.

[1.0.0]: https://github.com/getnovfora/novfora/releases/tag/v1.0.0
