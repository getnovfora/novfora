<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The Hearth Authors
-->
# Roadmap

Canonical, living roadmap for **Hearth** (working codename). Detailed deliverables and exit criteria per phase
are in [docs/product/roadmap.md](docs/product/roadmap.md); the MoSCoW feature split is in
[docs/product/feature-prioritization.md](docs/product/feature-prioritization.md). **Every phase ends runnable +
tested on the baseline tier** (PHP 8.3 + MySQL + cron). **Plan-before-code, with approval at each phase gate.**
Phases are scoped by deliverable and dependency, not calendar.

| Phase | Theme | Headline deliverables |
|---|---|---|
| **0** | **Discovery** *(this Stage A)* | Research + architecture + product docs; ADR log; governance/living files; MVP definition. **→ Phase 0 gate.** |
| **1** | **Core MVP** | Skeleton + **service-tier detection/driver abstraction**; **no-SSH web installer**; auth; forum CRUD; **permission-mask engine**; moderation queue; **WYSIWYG editor**; **anti-spam baseline**; email/in-app notifications; mobile-first theme + override layer; Scout DB search; SEO basics; **reversible migrations + backups**. *Runs on PHP 8.3 + MySQL + cron.* |
| **2** | **Community** | Reactions; profiles + custom fields; PMs; rich + digest notifications; reports; warnings/infractions (decay, auto-consequences, ack); trust-level promotion; activity feeds; **inline moderation** + bulk select; Markdown mode; oEmbed; drafts; edit history. |
| **3** | **Extensibility** | **Module/plugin API + hook/event/slot system** (semver'd) + compatibility check; **visual theming + layout configurator**; **REST API + webhooks**; **phpBB/MyBB/SMF importers** (verify + redirects); admin analytics. |
| **4** | **Advanced / competitive** | **SSO/OAuth2/SAML**; **paid memberships/subscriptions**; **Clubs**; advanced anti-spam intelligence; **Meilisearch + Reverb** (enhanced tier); **PWA/push**; XenForo importer (stretch). |
| **5** | **Hardening** | Security review; **WCAG 2.1 AA** completeness; i18n completeness; **load testing on both tiers**; docs → **1.0**. |

**Carried-in refinements:** Laravel 13 + Livewire 4; **PHP 8.3 floor** *(revises brief's 11/3 and the 8.2
floor — flagged at the Phase 0 gate)*; no-SSH installer; coarse-cron-tolerant queue; WYSIWYG↔Livewire spike as
the #1 risk; anti-spam first-class from Phase 1; a11y/i18n baked in throughout.

**Out of scope for 1.0:** multi-tenant SaaS (data-model seam kept, not built), native mobile apps (PWA
instead), in-core chat bridges (modules), marketplace payments. The architecture precludes none of them.
