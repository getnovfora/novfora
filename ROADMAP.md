<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# Roadmap

Canonical, living roadmap for **NovFora**. Detailed deliverables and exit criteria per phase
are in [docs/product/roadmap.md](docs/product/roadmap.md); the MoSCoW feature split is in
[docs/product/feature-prioritization.md](docs/product/feature-prioritization.md). **Every phase ends runnable +
tested on the baseline tier** (PHP 8.3 + MySQL + cron). **Plan-before-code, with approval at each phase gate.**
Phases are scoped by deliverable and dependency, not calendar.

| Phase | Theme | Headline deliverables |
|---|---|---|
| **0** | **Discovery** *(this Stage A)* | Research + architecture + product docs; ADR log; governance/living files; MVP definition. **→ Phase 0 gate.** |
| **1** | **Core MVP** | Skeleton + **service-tier detection/driver abstraction**; **no-SSH web installer**; auth; forum CRUD; **permission-mask engine**; moderation queue; **WYSIWYG editor**; **anti-spam baseline**; email/in-app notifications; mobile-first theme + override layer; Scout DB search; SEO basics; **reversible migrations + backups**. *Runs on PHP 8.3 + MySQL + cron.* |
| **2** | **Community** | Reactions; profiles + custom fields; PMs; rich + digest notifications; reports; warnings/infractions (decay, auto-consequences, ack); trust-level promotion; activity feeds; **inline moderation** + bulk select; Markdown mode; oEmbed; drafts; edit history. |
| **3** | **Extensibility** *(in progress — owner-authorized overnight build)* | **Module/plugin API + hook/event/slot system** (semver'd) + compatibility check **✅ B1 (ADR-0031)**; **visual theming + layout configurator ✅ B2 (ADR-0032)**; **REST API + webhooks ✅ B3 (ADR-0033)**; **phpBB/MyBB/SMF importers ✅ B4 (ADR-0034 — phpBB built, MyBB/SMF scaffolded)**; **admin analytics ✅ B5 (ADR-0035)**. See `docs/architecture/phase3-extensibility/`. |
| **4** | **Advanced / competitive** *(M1–M3 built — owner-authorized overnight build, branch `claude/phase-4-features`)* | **XenForo importer ✅ (ADR-0041)**. **M1 Clubs ✅** (ADR-0047–0052: data model + two-axis privacy, club-scoped permissions through the engine, membership flows + rank ceiling, discussion on the existing forum stack, the no-leak sweep across every surface, configurable creation policy). **M2 SSO ✅** (ADR-0053–0056: OAuth login Google/GitHub/Discord with encrypted secrets + email-collision no-merge, account linking, PKCE/state/CSRF hardening, **SAML scaffold-only — not validated against a real IdP**). **M3 PWA + Web Push ✅** (ADR-0057–0059: installable manifest + a no-PII service worker, VAPID web push as an opt-in cron-tolerant channel, push preferences UI). See `docs/architecture/phase-4/`. **DEFERRED to later Phase-4 waves:** M4 Meilisearch + Reverb (enhanced tier), M5 paid memberships/subscriptions, M6 advanced anti-spam intelligence. ⚠ OAuth/SAML/Web-Push delivery are **scaffolded, not validated against live providers/services** (no real credentials in the build env). |
| **5** | **Hardening** *(partial — owner-authorized overnight build on `claude/mega-build`)* | **Security-review sweep ✅ (ADR-0046, verify-then-refute)**; **WCAG 2.1 AA automated audit + fixes ✅ (ADR-0044 — automated floor + manual checklist)**; **i18n framework + RTL scaffolding ✅ (ADR-0043 — framework shipped, full string sweep is mechanical follow-up)**; **load-test harness ✅ (ADR-0045 — SCAFFOLDED, not run at scale)**. Remaining: full i18n string externalisation, captured load-test numbers on both tiers, docs → **1.0**. Rename (ADR-0026) already complete. |

**Carried-in refinements:** Laravel 13 + Livewire 4; **PHP 8.3 floor** *(revises brief's 11/3 and the 8.2
floor — flagged at the Phase 0 gate)*; no-SSH installer; coarse-cron-tolerant queue; WYSIWYG↔Livewire spike as
the #1 risk; anti-spam first-class from Phase 1; a11y/i18n baked in throughout.

**v1.0.0 release gate (ADR-0024):** the first public release is branded **NovFora** end-to-end. The
Hearth/NevoBB→NovFora rename (ADR-0026) is complete. The
Phase 5 exit criterion: `grep -ri nevo` returns only historical ADR references in docs.
enforced in CI. Domains + GitHub org are registered by the owner. Approaching 1.0 the owner reinstalls
fresh on a **new webhost at the new domain** — the current validation host is interim and is not migrated.

**Out of scope for 1.0:** multi-tenant SaaS (data-model seam kept, not built), native mobile apps (PWA
instead), in-core chat bridges (modules), marketplace payments. The architecture precludes none of them.
