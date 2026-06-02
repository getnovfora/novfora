# Roadmap

> **Project:** Hearth (working codename). **Stage A deliverable** (Section 8 #6). **Date:** 2026-06-01.
> Phases 0–5. **Every phase ends runnable + tested on the baseline tier** (PHP 8.3 + MySQL + cron) — the
> explicit guard against the big-rewrite death-march that stalled MyBB 2.0 and SMF 3.0. **Plan-before-code
> per phase, with approval at each gate.** Phases are scoped by **deliverable and dependency**, not calendar
> (timeline depends on resourcing, which is undetermined — the comprehensive target is planned in full per
> your instruction). A canonical copy lives in the repo-root [ROADMAP.md](../../ROADMAP.md).

**Carried-in refinements (apply throughout):** target **latest-stable Laravel 13 + Livewire 4**; **PHP 8.3
floor** (recommend 8.4+) — *revises the brief's 11/3 and the 8.2 floor; flagged at the Phase 0 gate*; **no-SSH
web installer**; **coarse-cron-tolerant** queue; **WYSIWYG↔Livewire = the #1 risk (early spike)**; **anti-spam
first-class from Phase 1**; **a11y/i18n baked in, not deferred wholesale to Phase 5**.

---

## Phase 0 — Discovery *(this Stage A)*
**Goal:** evidence-based plan a senior engineer can build from.
**Deliverables:** the research + architecture + product docs; the ADR log ([DECISIONS.md](../../DECISIONS.md));
governance/living files; MVP definition + cut options.
**Exit / gate:** **you approve at the Phase 0 gate.** No application code before then.

## Phase 1 — Core MVP
**Goal:** a real community can run on a shared host; the two-tier architecture proven end-to-end.
**Deliverables:** ([mvp-scope](mvp-scope.md) is the authority)
- Laravel 13 + Livewire 4 skeleton; **service-tier detection + driver abstraction** from commit one.
- **Web installer (no SSH)** + tier detection; **reversible-migration baseline**; **automated backup + restore**;
  prebuilt assets.
- Auth (register/verify/sessions); basic profiles + avatars.
- **Permission-mask engine** + groups + role presets + minimal inspector.
- Categories/forums/topics/posts (server-rendered) + sticky/lock/move + soft-delete/recycle + audit log.
- **WYSIWYG editor spike → feature** (canonical storage ADR-0005) + image attachments/thumbnails.
- **Anti-spam baseline** (blocklist, CAPTCHA abstraction, honeypot, rate-limit, trust gating, moderation queue).
- ACP + MCP; bans; email + basic in-app notifications (queued).
- Mobile-first default theme + Blade override layer + a11y floor; Scout DB search; SEO basics (canonical/
  schema/sitemap) + caching.
**Exit:** runs on PHP 8.3 + MySQL + cron; the [MVP acceptance criteria](mvp-scope.md) are green, incl. the
**permission-mask** and **service-tier fallback** suites.

## Phase 2 — Community
**Goal:** engagement + the moderation depth XF/IPS are known for.
**Deliverables:** reactions (score model); profiles + **custom fields**; **multi-participant PMs**; rich +
**digest** notifications + email bounce/suppression; **reports**, **warnings/infractions** (points, time-decay,
auto-consequences, required ack); **trust-level promotion** rules; activity feeds; **inline thread-view
moderation** + cross-page bulk select; user preferences; polls/prefixes/tags UI; Markdown input mode; oEmbed;
drafts/autosave; edit history/diffs.
**Exit:** all new features tested + runnable on baseline; notifications/digests respect deliverability hygiene.

## Phase 3 — Extensibility
**Goal:** customization-without-forking; migration on-ramp.
**Deliverables:** **module/plugin API + hook/event/UI-slot system** (semver'd public contract, ADR-0008) + the
**pre-upgrade compatibility check**; **visual theme configurator + layout/widgets** (ADR-0009) atop the override
layer; **public REST API + webhooks** (versioned); **resumable phpBB/MyBB/SMF importers** (dry-run → import →
verify; attachment verification; password-rehash-on-login; **301 redirect maps**; BBCode→canonical, ADR-0013);
admin analytics dashboards (incl. spam stats).
**Exit:** a sample module **and** a child theme pass the contract + rollback tests; an importer round-trips a
fixture board with verification; APIs versioned and documented.

## Phase 4 — Advanced / competitive
**Goal:** close the remaining commercial-feature gaps; light up the enhanced tier.
**Deliverables:** **SSO/OAuth2/OIDC/SAML/magic-link** (forum-as-OAuth2-provider); **paid memberships/
subscriptions** (Stripe via Cashier; subscription→group→permission); **groups/Clubs** (open/closed/private/
paid); **advanced anti-spam intelligence** (Akismet, MaxMind, AI scoring); **Meilisearch/Typesense** search +
**Reverb/Pusher real-time**; **PWA + web/iOS push**; XenForo importer (stretch).
**Exit:** enhanced features **degrade gracefully to baseline** (the forced-absence suite still green);
multi-tenant remains documented-not-built.

## Phase 5 — Hardening
**Goal:** production-grade quality bar.
**Deliverables:** independent **security review** (OWASP) + pen-test fixes; **WCAG 2.1 AA** completeness audit;
**i18n** completeness (language packs, RTL polish); **load testing on both tiers** against the
[performance budgets](../architecture/system-architecture.md); documentation completeness; 1.0 release
engineering + upgrade rehearsal on the baseline tier.
**Exit:** security/a11y/i18n/load targets met and documented → **1.0**.

---

## Sequencing notes
- **Dependencies:** the **module/theme API (P3)** must stabilize before a third-party ecosystem is encouraged
  (semver contract). **Importers (P3)** depend on the canonical content format (P1, ADR-0005) and redirect/SEO
  subsystem. **Real-time/search enhanced tiers (P4)** depend on the driver abstraction (P1).
- **Risk-front-loading:** the **WYSIWYG↔Livewire spike** and the **permission engine** are tackled first
  (Phase 1) because they are the highest-uncertainty, highest-blast-radius pieces.
- **Always-runnable rule:** no phase may leave the baseline tier non-functional; `.env.example`, seeds, and the
  getting-started guide are updated each phase (they begin in Phase 1 with the scaffold).
- **Each phase is its own plan-before-code gate** ([CONTRIBUTING](../../CONTRIBUTING.md) / working agreement).
