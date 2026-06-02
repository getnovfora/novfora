# Claude Code Super-Brief v3 — Modern Self-Hosted Forum Platform (PHP/Laravel, Open Source)

> **What this is:** a single, self-contained brief for Claude Code. An **open-source**, community-
> oriented forum platform on a **modern PHP** stack that self-installs on either the operator's own
> infrastructure (Docker/VPS) **or** a modern shared PHP host, with a **WYSIWYG-first** editor. Paste
> everything below the line into Claude Code at the root of an empty git repo.
>
> **First response rule (do this and nothing else on your first reply):** (1) confirm the assignment;
> (2) state which pre-loaded decisions in Section 1 you accept vs. intend to challenge, with reasoning;
> (3) ask only the clarifying questions still genuinely open; (4) do **not** produce the plan yet;
> (5) do **not** write code yet.

---

## 1. Decisions already made (do not re-ask these)

- **Deployment:** **Self-hosted, single-community**, self-installable on **either** (a) the operator's
  own infrastructure (Docker / VPS / homelab) **or** (b) a **modern shared PHP host**. Multi-tenant
  SaaS is **out of MVP scope**, but keep the data-access layer clean so tenant scoping could be added
  later without a rewrite.
- **Hosting floor:** **Modern PHP host — PHP 8.2+, Composer, SSH, MySQL/MariaDB, cron.** Assume the
  baseline host **cannot** run persistent daemons (no guaranteed Redis, no long-lived WebSocket
  server, no always-on queue workers); **cron is the only reliable background mechanism** there.
- **Stack (DEFAULT — validate, but this is the chosen direction, not a coin-flip):**
  **Laravel 11 (PHP 8.3) + Livewire 3 + Alpine.js + Blade**, server-rendered for SEO, on
  **MySQL 8 / MariaDB by default** (PostgreSQL supported for Docker/VPS operators). Asset build via
  Vite; ship prebuilt assets so a Node runtime is **not** required on the host.
  - **Alternative front-end to note, not implement:** Inertia.js + Vue/React. Rejected as the default
    because SPA SEO needs server-side rendering via a Node process that shared hosts can't run
    reliably; Livewire keeps one codebase that is SEO-safe on every tier. Revisit only if SEO or
    shared-hosting support is later dropped.
- **Feature strategy: tiered progressive enhancement.** The **core forum is identical on every
  tier**; only performance/real-time features scale with the environment (Section 4). No baseline
  feature may hard-depend on Redis, a WebSocket server, a persistent worker, or an external search
  engine.
- **Imports matter:** first-class importers from **phpBB, MyBB, SMF** (with a **BBCode compatibility
  layer** and URL-redirect maps to preserve SEO).
- **Editor:** **WYSIWYG-first** (most end-user-friendly), with an optional **Markdown input mode** for
  power users and **BBCode kept as an import/compatibility layer**, not the primary authoring format.
- **License & openness:** **open source under Apache-2.0** (confirmed) — permissive, to grow a free
  plugin/theme/host ecosystem. **Strict clean-room:** no code is copied or adapted from any reference
  forum, so their licenses do **not** constrain ours (Section 11).
- **Clean-room only:** commercial platforms inform concepts only — never copy proprietary code, UI,
  branding, or docs. Review open-source licenses before reusing anything (Section 11).

If a *different* question is genuinely blocking after you read this, ask it. Otherwise proceed.

---

## 2. Mission

Build a modern, open-source-grade, **self-hosted forum/community platform** in PHP/Laravel that
combines the proven fundamentals of phpBB, MyBB, and SMF with the polished product/UX of ProBoards,
XenForo, and Invision Community — while deliberately fixing the pain points all of them share, and
running on hosting that ordinary forum operators actually have. Comprehensive target, delivered in
phased milestones; architecture must support the full feature surface (Section 6) without major
rewrites.

Two stages: **Stage A — Discovery (no code)** producing the Section 8 documents, then approval;
**Stage B — Implementation** along the Section 7 roadmap, plan-before-code per phase.

---

## 3. Design DNA — what we deliberately take from whom

Use these as guiding intent; emulate behavior, never copy code/assets from the commercial ones.

- **From phpBB / MyBB / SMF — the durable foundation:** a rock-solid **relational data layout** for
  categories → forums → threads → posts, and a **highly granular permission system modeled on
  phpBB's permission masks + roles** (per-user/group, per-forum allow/deny/never, role presets).
  This ACL model is a primary requirement, not a generic afterthought.
- **From XenForo / Invision — the polish:** sleek, responsive UI interactions; **inline moderation**
  (select and act on posts directly in the thread view, plus a queue, not only a queue); **rich media
  handling** — drag-and-drop uploads, paste-to-upload, and native oEmbed embedding in the editor;
  and **profile engagement mechanics** — reactions, badges, trophies, reputation/points, and
  achievements.
- **From ProBoards — accessible customization:** **point-and-click theming and layout configuration
  for non-technical admins** (visual theme settings, drag-to-arrange layout/widgets, no code
  required) — sitting alongside, not instead of, a developer-grade theme/template API.

---

## 4. Deployment tiers (one codebase; Laravel driver abstractions do the work)

| Capability | **Baseline tier** (modern shared PHP host) | **Enhanced tier** (Docker / VPS) |
|---|---|---|
| App | Laravel + Livewire, server-rendered | same |
| Database | MySQL/MariaDB | MySQL/MariaDB or PostgreSQL |
| Search | **MySQL full-text** via Laravel Scout DB driver | **Meilisearch / Typesense** via Scout |
| Cache / session | file or database | **Redis** |
| Queue / jobs | **database queue drained by cron** (`schedule:run` every minute) | **Redis queue + dedicated worker(s)** |
| Real-time | **Livewire polling** (near-real-time) | **Laravel Reverb / Pusher WebSockets** via Echo |
| Media storage | local disk | **S3-compatible (MinIO)** |
| Image processing | on-request / cron | queued workers |
| Email | host SMTP | SMTP / SES / Postmark |

**Rule:** the app must **detect available services and degrade gracefully** — never error because
Redis/WebSockets/Meilisearch are absent. Installer and admin panel surface which tier is active and
what enabling each enhancement would unlock.

---

## 5. Stage A research tasks (evidence, not assertions)

### 5.1 Tech-stack comparison
Document phpBB/MyBB/SMF on: language, framework, DB support, app/routing structure, template/theme
system, plugin/mod architecture, auth + permission model, ACP architecture, caching, search,
install/update/migration, i18n, security model, testing, deployment assumptions, shared-hosting
compatibility, modernization challenges. Then justify the chosen **Laravel + Livewire** stack against
the Section 1 constraints, and honestly record where it is weak (e.g. forum-grade extension/module
system and visual theming are **not** free in Laravel and must be designed — Section 9).

### 5.2 Common denominators
Catalog the features every serious forum shares (grouped as in Section 6); split each into
**MVP-required vs advanced**.

### 5.3 Best ideas from commercial platforms
From ProBoards / XenForo / Invision docs + community, catalog admin UX, theming, add-on marketplaces,
engagement mechanics, notifications, editor, media galleries, clubs/groups, paid memberships, spam
prevention, moderation queues, reporting, warnings/discipline, SEO tools, importers, mobile design,
activity feeds, integrations, APIs, SSO, email deliverability, analytics. Mark each **concept-safe to
emulate** vs **do-not-copy**.

### 5.4 Community/support-forum research (evidence-based)
For each recurring complaint/request record: **platform(s)** · **evidence/source link** ·
**severity** · **frequency/confidence** · **product opportunity** · **our design response.**
Distinguish confirmed patterns from one-off posts.

### 5.5 Competitive matrix
Score phpBB / MyBB / SMF / ProBoards / XenForo / Invision across install ease, admin ease, theme
customization, plugin ecosystem, moderation tools, permission flexibility, UX, mobile, performance/
scalability, security posture, upgrade experience, developer experience, docs quality, community
health, architecture modernity, import/export, best-fit use case.

### 5.6 Pre-loaded findings to validate (head start, not gospel — source and extend, don't restate)
Common denominators: all six are PHP + MySQL/MariaDB on hand-rolled/light-framework MVC; Redis as a
cache layer at scale; weak native MySQL full-text search so an external engine gets bolted on;
commercial ones add deep add-on frameworks, REST APIs, and HTML/page caching.
Recurring complaints = requirements (verify + source): (1) **spam is the #1 burden**; (2) weak
search; (3) dated mobile UX; (4) painful upgrades that break extensions/themes; (5) theming needs
core edits; (6) SEO gaps; (7) weak real-time; (8) install/ops friction; (9) shallow monetization
outside Invision; (10) fragile migration. Mapped design responses to validate: server-rendered
Livewire → SEO; Scout (DB→Meilisearch tiering) → search; anti-spam subsystem + trust levels → spam;
Composer + web installer + prebuilt assets → install friction; reversible Laravel migrations →
upgrade breakage; first-class module + visual theme system → customization-without-forking.

---

## 6. Feature scope (organize as modules; tag each MoSCoW: Must / Should / Could / Won't-for-now)

- **Core structure:** categories → forums → topics → posts (unlimited nesting, ordering, per-node
  permissions); sticky/announcement/locked/moved/merged/split; soft-delete + recycle bin + audit
  trail; polls; **topic prefixes/tags (XenForo) and hierarchical categories (Discourse) coexisting**.
- **Permissions & groups (phpBB-grade ACL):** **permission masks with allow/deny/never resolution**,
  evaluated per user and per group, at global → category → forum → thread scope; **role presets**;
  primary + secondary group membership; automatic group promotion (post count / trust / time);
  per-group styling. Provide a readable "why can/can't this user do X" permission inspector for admins.
- **Posting & content (WYSIWYG-first, end-user-friendly):** a modern rich-text **WYSIWYG editor**
  (ProseMirror/TipTap-class — `@mentions`, drag-and-drop / paste-to-upload, inline media, toolbar +
  slash commands, mobile + keyboard accessible) as the **default** surface; an optional **Markdown
  input mode** for power users; and a **BBCode compatibility layer** for importing legacy phpBB/MyBB/
  SMF content (and optional legacy input). Store sanitized content in a normalized format and render
  safely. Plus drafts/autosave; attachment thumbnailing; native **oEmbed** embedding; code blocks;
  spoilers; quotes; reactions/likes; edit history + diffs.
- **Users & social:** email-verified registration (optional admin approval); rich profiles + custom
  fields; avatars/covers; signatures; reputation/points; **badges / trophies / achievements**;
  follow/ignore; activity feeds; presence; staff notes; **Discourse-style trust levels** (also a
  primary anti-spam lever).
- **Messaging & notifications:** multi-participant PMs; in-app notifications (real-time on enhanced
  tier, polling on baseline); granular email prefs; **digest emails**; web push (enhanced) — all via
  the queue.
- **Moderation & admin:** full **ACP + MCP**; **inline moderation in thread view** + a moderation
  queue; report system; bulk actions; warnings/infractions with point thresholds + automated
  consequences; IP/device tracking; ban management (user/IP/email/range); word filters; approval
  workflows; complete **audit log**; staff dashboards.
- **Anti-spam subsystem (first-class):** crowdsourced blocklist on registration (StopForumSpam-style)
  + pluggable content scanning (e.g. Akismet); **Q&A challenge + invisible CAPTCHA** configurable per
  action; new-user moderation queue + trust gating + rate limiting; email verification +
  disposable-email blocking; honeypots; contact-form protection; IP/country/velocity rules.
- **Search & discovery:** Laravel Scout abstraction — **MySQL full-text on baseline**, Meilisearch/
  Typesense on enhanced; filters/facets; similar topics; trending; per-user unread / "what's new".
- **SEO & performance:** server-rendered pages; canonical URLs; sitemaps; schema.org
  `DiscussionForumPosting`; OG tags; import redirect maps; response + fragment caching; CDN-friendly
  assets; lazy loading.
- **Theming & layout (ProBoards-accessible + developer-grade):** **point-and-click visual theme
  settings and drag-to-arrange layout/widgets for non-technical admins**, plus a **Blade-based theme/
  template override system** for developers; themes never require editing core; light/dark; per-forum
  styling.
- **Monetization (Should/Could — Invision depth as the north star):** paid memberships/subscriptions;
  paid user upgrades; pluggable payments (Stripe first); optional ad-slot management.
- **Integrations & API:** **public REST API + webhooks** over core resources; **SSO** (OAuth2/OIDC,
  SAML, magic-link); embeddable comment/widget mode; optional chat bridges (Discord/Matrix) as
  modules.
- **Migration/import:** resumable batch importers for **phpBB/MyBB/SMF** (+ ideally XenForo) covering
  users, content, attachments, permissions, and redirect maps.
- **Analytics:** admin dashboards — registrations, posts, active users, device breakdown, top
  content, moderation/spam stats.
- **Operability (elevated for self-hosting — treat as Must):** **Composer-based + web installer**
  that runs on the baseline host; guided setup that detects the deployment tier; automated
  **backups**; health checks; **safe in-place upgrades via reversible migrations** with a clear
  restore path; prebuilt assets so no Node runtime is needed on the host.

---

## 7. Roadmap (phased; each phase ends runnable + tested on the baseline tier)

- **Phase 0 — Discovery:** Section 5 research, competitive matrix, data-model design, ADRs, MVP
  definition, the Section 8 docs. **Stop for approval.**
- **Phase 1 — Core MVP:** Laravel + Livewire skeleton; **service-tier detection + driver abstraction**
  wired from the start; web/Composer installer; auth (register/verify/sessions); categories/forums/
  topics/posts with server-rendered views; the **permission-mask engine**; moderation queue; the
  **WYSIWYG editor** (+ Markdown mode + BBCode import) with drag-drop uploads; email notifications;
  theme foundation; backup
  + reversible-migration baseline. Must run on a PHP 8.2 + MySQL + cron host.
- **Phase 2 — Community:** reactions; profiles + custom fields; PMs; rich + digest notifications;
  reports/warnings/infractions; trust levels; activity feeds; inline moderation; user preferences.
- **Phase 3 — Extensibility:** **module/plugin API + hook/event system**; **visual theming + layout
  configurator** and Blade theme override system; webhooks + public REST API; **phpBB/MyBB/SMF
  importers**; admin analytics.
- **Phase 4 — Advanced/competitive:** SSO/OAuth/SAML; paid memberships; groups/clubs; advanced
  moderation + anti-spam intelligence; enhanced-tier search (Meilisearch) + real-time (Reverb);
  PWA/mobile; (multi-tenant documented as a future option, not built).
- **Phase 5 — Hardening:** security review, accessibility (WCAG 2.1 AA), i18n completeness, load
  testing on both tiers, docs.

---

## 8. Stage A deliverables (create these, then stop for approval)

1. `docs/research/forum-platform-comparison.md` — research summary + competitive matrix
2. `docs/research/community-complaints-and-feature-requests.md` — evidence table (platform / source /
   severity / confidence / opportunity / design response)
3. `docs/architecture/technical-stack-recommendation.md` — Laravel/Livewire justification + the
   rejected alternatives, judged against Section 1; explicit baseline-vs-enhanced tier strategy
4. `docs/architecture/system-architecture.md` — the **two deployment tiers** plus a practical-MVP vs
   scalable-long-term view and the path between them
5. `docs/product/mvp-scope.md`
6. `docs/product/roadmap.md`
7. `docs/product/feature-prioritization.md` — MoSCoW
8. `docs/architecture/data-model-initial.md` — users, forums, topics, posts, **permission masks/
   roles**, moderation, notifications, modules, themes, settings (+ how a future `tenant_id` would
   thread through)
9. `docs/architecture/plugin-and-theme-system.md` — versioned module/extension API + the dual
   (visual + developer) theming model, none of it requiring core edits
10. `docs/architecture/security-and-permissions.md` — ACL/permission-mask resolution, abuse
    prevention, moderation model
11. **Living docs + project files (maintain throughout):** `ARCHITECTURE.md`, `DECISIONS.md` (ADRs),
    `ROADMAP.md`, `LICENSE`, `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `GOVERNANCE.md`, plus
    `.env.example`, seed data, and a getting-started guide so the repo is runnable at every milestone
    on the baseline tier.

---

## 9. Cross-cutting principles (non-negotiable)

- **Progressive enhancement / graceful degradation:** baseline features never hard-depend on Redis,
  a WebSocket server, a persistent worker, or an external search engine. Detect and adapt.
- **Extensibility is core:** module/hook/event system + theme API from day one; modules extend models,
  routes, UI slots, permissions, settings **without editing core**; version the module and theme APIs
  so upgrades don't silently break them. (Laravel doesn't give a forum-grade module system for free —
  design it deliberately.)
- **Security by default:** OWASP Top 10; Eloquent parameterized queries; bcrypt/argon2id; signed
  sessions; CSRF; strict CSP; rate limiting; audit logging; sanitized rich-text rendering.
- **Privacy/GDPR:** data export, account deletion, consent/cookie management, configurable retention.
- **Accessibility:** WCAG 2.1 AA on all user-facing UI.
- **i18n:** externalized strings; RTL; pluggable language packs.
- **Observability:** structured logging, health checks, metrics.
- **Testing:** Pest/PHPUnit unit + feature + browser (Dusk) tests; **permission-mask resolution and
  service-tier fallbacks get dedicated tests**; no feature is "done" without tests.
- **Community & openness (this is an open-source project meant to grow a community):** ship `LICENSE`,
  `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, and `GOVERNANCE.md`; follow **semantic versioning**; treat
  the **module and theme APIs as stable, semver'd public contracts** (a breaking change is a
  major-version event) so community plugins/themes don't silently break on upgrade; provide an **open,
  non-gatekept extension/theme directory** and a public roadmap.
- **Migrations are reversible and non-destructive;** upgrades never require manual DB surgery; test
  upgrades on the baseline tier.

## 10. Quality bar
Concrete enough for a senior engineer to start building. Ban vague phrases unless precisely defined.
Provide tradeoffs, risks, decision criteria, implementation complexity, migration concerns, security
implications, maintenance burden, and admin/UX implications wherever relevant.

## 11. Legal, license & openness (strict clean-room)
**Project license:** open source under **Apache-2.0** (confirmed) — permissive, explicit patent grant,
ecosystem-friendly. Add `LICENSE` and SPDX headers from the first commit.
**Strict clean-room (no reference-forum code, full stop):** do **not** copy or adapt source code, UI,
templates, themes, branding, or documentation from **any** reference — not the commercial platforms
(ProBoards/XenForo/Invision) and **not** the open-source ones (phpBB, MyBB, SMF) **even where their
license would technically permit it** (e.g. SMF's BSD-3-Clause). Everything is implemented from
scratch, which keeps the project unambiguously Apache-2.0 and free of copyleft/attribution
entanglements.
**What IS allowed (knowledge, not code):** study the references' publicly documented behavior, data
**schemas**, BBCode tag **semantics**, and permission/role **concepts**, then reimplement them
independently. Reading a reference forum's database/output structure purely to build an **importer**
or to interoperate is fine — the importer is our own code; we copy data, never their program.
**Normal open-source dependencies are unaffected:** clean-room applies to the *reference forums*, not
to ordinary third-party libraries. Using well-licensed packages (Laravel and Livewire are MIT, as is
most of the ecosystem) is expected — just confirm each dependency's license is Apache-2.0-compatible
and record anything non-obvious in `DECISIONS.md` before merging.

## 12. Working agreement (Stage B)
Plan before each phase; wait for approval at the Phase 0 gate. Small, reviewable, conventional commits.
Tests alongside code. **Ask before** destructive operations, stack-changing dependencies, or ambiguous
product calls; state inline any reasonable assumption you make to keep moving. Prefer boring,
well-supported libraries; record non-obvious choices as ADRs. Keep the repo runnable on the baseline
tier at every milestone.

## 13. Your first response (reminder)
Confirm the assignment; list which Section 1 decisions you accept vs. intend to challenge (with
reasoning); ask only the still-open clarifying questions; then stop. No plan, no code yet.
