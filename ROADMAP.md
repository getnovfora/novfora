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
| **4** | **Advanced / competitive** *(M1–M6 built — owner-authorized overnight builds; M1–M3 on `claude/phase-4-features`, M4–M6 on `claude/phase-4-enhanced`)* | **XenForo importer ✅ (ADR-0041)**. **M1 Clubs ✅** (ADR-0047–0052). **M2 SSO ✅** (ADR-0053–0056; **SAML scaffold-only**). **M3 PWA + Web Push ✅** (ADR-0057–0059). **M4 Enhanced tier ✅** (ADR-0060 Meilisearch via Scout + DB fallback; ADR-0061 **Reverb realtime + apex channel-authz no-leak fence** + polling fallback; ADR-0062 opt-in presence). **M5 Paid memberships ✅** (ADR-0063 tiers + perk gating through the engine; ADR-0064 offline/**manual provider — the live-granting path**; ADR-0065 **Stripe hosted checkout, charging DISABLED** + hardened webhook; ADR-0066 money-fenced paid-clubs hook). **M6 Advanced anti-spam ✅** (ADR-0067 **HOLD-only spam intelligence** + FP guards; ADR-0068 review surface; ADR-0069 external-signal tuning + **content-privacy fence**). See `docs/architecture/phase-4/`. ⚠ **Scaffolded, NOT validated against live services:** OAuth/SAML/Web-Push, **Meilisearch, Reverb, live Stripe payments, SFS submission** (no real credentials/services in the build env — enable steps in PROJECT-STATE + each ADR). |
| **5** | **Hardening → GA** *(complete — owner-authorized GA run on `claude/phase-5-ga`)* | **P5.1 security ✅** 2nd adversarial verify-then-refute over the whole Phase 3/4 surface — 8 MEDIUM + 5 LOW/INFO fixed, 6 refuted, no HIGH (ADR-0070, extends ADR-0046). **P5.2 WCAG 2.1 AA ✅** automated gate grown 14→27 surfaces + 3 accessible-name fixes; manual residue recorded (ADR-0044). **P5.3 i18n ✅** framework + RTL + locale-switch (ADR-0043) completed with an `es` **proof locale** + the auth/error surfaces externalised + per-key `en` fallback test; remaining view sweep is documented community-contributable residue (ADR-0071). **P5.4 perf ✅** hot-path query-count regression gate (no steady-state N+1) + documented baseline + enhanced procedure/SLOs (ADR-0072, extends ADR-0045). **P5.5 release ✅** the `nevo→novfora` rename **completed + enforced by a CI brand gate**, version → **1.0.0**, CHANGELOG + release checklist (ADR-0073). **P5.6 fresh-install ✅** from-scratch redeploy proven (FreshInstallSmokeTest: empty DB → schema + seeded posture + capable admin + lock; build-release zip clean + cold boot 302→/install) (ADR-0074). ⚠ Carries forward the **VALIDATE-BEFORE-GO-LIVE** set (Meilisearch · Reverb · live Stripe · OAuth/SAML · Web Push · SFS · at-scale load) — see `PROJECT-STATE.md`. |

**Carried-in refinements:** Laravel 13 + Livewire 4; **PHP 8.3 floor** *(revises brief's 11/3 and the 8.2
floor — flagged at the Phase 0 gate)*; no-SSH installer; coarse-cron-tolerant queue; WYSIWYG↔Livewire spike as
the #1 risk; anti-spam first-class from Phase 1; a11y/i18n baked in throughout.

**v1.0.0 release gate (ADR-0024) — MET:** the first public release is branded **NovFora** end-to-end. The
Hearth/NevoBB→NovFora rename (ADR-0026/0073) is complete down to the artisan command prefix + the editor JS
island + dev/CI infra names. The Phase 5 exit criterion — `grep -ri nevo` returns only historical ADR
references in docs — **passes and is enforced in CI** (the `static` job's "Brand gate" step). Version is
**1.0.0**. Domains + GitHub org are registered by the owner. Approaching 1.0 the owner reinstalls fresh on a
**new webhost at the new domain** (proven by the P5.6 fresh-install path) — the current validation host is
interim and is not migrated.

**Out of scope for 1.0:** multi-tenant SaaS (data-model seam kept, not built), native mobile apps (PWA
instead), in-core chat bridges (modules), marketplace payments. The architecture precludes none of them.
