<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# Changelog

All notable changes to **NovFora** are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); NovFora adheres to [Semantic
Versioning](https://semver.org/spec/v2.0.0.html). The **module and theme APIs are semver'd public
contracts** — a breaking change to either is a major-version event.

## [Unreleased]

## [1.2.0] — 2026-07

This release folds in **U7 (embed API / SSI / web components, ADR-0103)** and **U17 (plugin install-from-zip
+ signature/trust gate, ADR-0104)** — both already merged into `main` before this cycle — plus the UI-audit
reconcile (all 21 findings verified already fixed by PR #41 and intact), four live beta-tester fixes, and
three Phase-6 quick wins. Two additive, reversible migrations ship this release (`module_trust_keys` from
U17, `username_history` from U8), so the upgrade path is the **cron auto-upgrade** (backup-first), not
assets-only.

### Added

- **U7 — Embed API / SSI / web components (ADR-0103).** Server-rendered `/embed/v1` widgets (iframe/SSI HTML
  + versioned JSON) and a dependency-free `<novfora-*>` web-component bundle for external sites. Guest-visible
  content only, OFF by default, per-origin allowlist with rotatable keys, stateless + rate-limited, 404 on
  every denial. Adversarial-reviewed (0 open HIGH/MEDIUM).
- **U17 — Plugin install-from-zip + signature/trust gate (ADR-0104).** Upload a signed module `.zip` in ACP →
  Plugins; installs disabled only if safe to extract **and** authentic. Hostile-archive hardening
  (traversal/symlink/zip-bomb structurally prevented), detached **ed25519** signatures verified against an
  admin-managed trusted-key registry, atomic reversible commit with quarantine-on-failure. Adversarial-reviewed
  (0 HIGH/MEDIUM).
- **U8 — Username history + admin change/revert (ADR-0106).** Admins can rename members and revert a prior
  handle from a fully audited `username_history`, gated by `users.manage` + the rank/no-self guard. Revert
  fails loud on a now-taken name (never auto-suffixes). User-side username editing stays deferred.
- **U18 — hCaptcha + reCAPTCHA CAPTCHA drivers (fail-closed) + opt-in Gravatar (ADR-0107).** Two new
  registration CAPTCHA providers that fail **closed** on a provider outage (an unverifiable token is a failed
  challenge); secrets stored encrypted, never rendered. Optional, default-OFF Gravatar avatar fallback with a
  browser-side (no server call) email hash. Also fixes a latent CSP gap that silently blocked the shipped
  Turnstile widget under the default policy. QA/null drivers remain the Baseline default.
- **U20 — SEO polish (ADR-0108).** Profile OpenGraph/canonical (aggregate-only description, no post/signature
  leak), site-wide `og:site_name` + an optional per-page meta-description seam, a "view all posts by this
  member" author-search link, and a dynamic (subdirectory-aware) `robots.txt` replacing the shadowed static
  file.

### Fixed

- **BETA-1 — Notification read-state now auto-updates (NOV-85).** Opening a notification (bell dropdown or the
  index) marks it read via a new owner-scoped, same-origin-guarded click-through route, and the bell dropdown
  reloads its list on every open so the list and the polling badge can no longer disagree.
- **BETA-2 — Mobile portrait nav no longer spills at 390px (NOV-86).** The brand link is now the one flex
  child that yields (`min-w-0`), so the signed-in bell/PM/avatar cluster can't be pushed off-viewport;
  admin-added nav titles stay on one line.
- **BETA-3 — Direct messages explain the send restriction instead of a bare 403 (NOV-87).** A new member
  without `pm.send` (a deliberate anti-spam trust gate) now sees a friendly explainer on the compose page and
  in a received conversation, instead of a raw framework 403. Enforcement is unchanged — no capability widened.
- **BETA-4 — Moderation controls no longer render to users who can't use them (NOV-88, ADR-0105).** The
  header Moderation link, the Merge trigger, the `posts.edit` page, and the queue/recycle-bin dashboard links
  now render on the exact capability their action enforces; and bulk-lock now matches the single-lock
  predicate (a moderator's bulk lock equals N single locks — no phantom "insufficient rank").
- **Moderation leak fence (ADR-0108).** A topic whose opening post is pending moderation is now 404 (not 403,
  no disclosure) to everyone but its author and moderators, and emits no crawlable/shareable metadata or feed
  — closing a pre-existing direct-URL/Atom title-and-excerpt leak.

## [1.1.0] — 2026-06

### Added

- **Admin members directory: gated row actions + PII tightening.** Bulk/row actions in the ACP
  members tab are now gated by the operator's actual permissions (ban, warn, delete — only shown when
  the acting admin holds the corresponding grant). PII columns (email, IP) are hidden from
  sub-admins who lack the `view_pii` grant.

### Fixed

- **Editor scroll-trap on rendered posts (M0).** The `.novfora-prose` box constraints
  (`min-height: 8rem; max-height: 28rem; overflow-y: auto`) were meant for the composer's editing box
  but rode the shared class onto rendered posts and signatures — trapping any post over ~28rem in an
  inner scroller and padding short posts with 8rem of dead space. The constraints are now scoped to
  `.novfora-editor .novfora-prose`, so rendered posts grow with their content and the *page* scrolls;
  the composer's editing box still caps at 28rem. CSS-only; the `.novfora-prose` Dusk contract is
  preserved.

- **v1.x Polish-2 + a11y batch (F1–F6, P1–P2).** ACP recents removed; mod-CP widened to `lg`;
  Info Center collapsible + coloured who's-online; latest-activity bounded (+3 const queries, holds
  HotPath <25 gate); report-review post excerpts + gated mod actions (no private-club leak); manual
  trust/rep editing with transactional group-swap + audit log; email-editor dark-mode tokens;
  a11y gate grown 27→30 surfaces (WCAG 2.1 AA).

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

[1.2.0]: https://github.com/getnovfora/novfora/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/getnovfora/novfora/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/getnovfora/novfora/releases/tag/v1.0.0
