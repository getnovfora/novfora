# Technical Stack Recommendation

> **Project:** Hearth (working codename). **Stage A deliverable** (Section 8 #3). **Date:** 2026-06-01.
> Justifies the chosen stack against the brief's locked constraints, records the rejected alternative, the
> baseline-vs-enhanced tier strategy, and the honest weaknesses Laravel does **not** solve for free.
> Versions are **live-validated as of June 2026**. Related ADRs: **ADR-0001** (stack), **ADR-0002** (PHP
> floor), **ADR-0003** (tiers), **ADR-0015** (dependency licensing).

---

## 1. Decision summary

| Choice | Decision | Status |
|---|---|---|
| Framework | **Laravel 13** (latest stable, Q1 2026) | ✅ validated — revises brief's "Laravel 11" |
| Server-driven UI | **Livewire 4** (stable Jan 2026; v4.3.0 May 2026) + **Alpine.js** + **Blade** | ✅ validated — revises brief's "Livewire 3" |
| Rendering | **Server-rendered HTML** (SEO-safe on every tier) | ✅ matches brief |
| Min PHP | **8.3** (recommend 8.4+) | ⚠️ **revises** approved "8.2 floor" — see §5, flagged for Phase 0 gate |
| Default DB | **MySQL 8 / MariaDB**; PostgreSQL on Docker/VPS | ✅ matches brief (detail in ADR-0004) |
| Assets | **Vite**, prebuilt and committed — **no Node runtime on the host** | ✅ matches brief |
| Editor | **TipTap-class WYSIWYG** as an Alpine island (MIT core only) | ✅ matches brief (detail in ADR-0012) |

**One-line rationale:** Laravel + Livewire is the only mainstream PHP stack that yields **one
server-rendered codebase** that is simultaneously SEO-safe, installable on a commodity shared host (no Node,
no daemon), and modern enough to deliver the commercial-grade product surface — satisfying the brief's
hardest constraint (identical core on every tier) without an SPA's Node-SSR dependency.

---

## 2. Constraints the stack must satisfy (from Section 1)

1. **Self-hostable on a modern shared PHP host** — no persistent daemons; cron is the only reliable
   background mechanism; no guaranteed Redis/WebSocket/worker.
2. **SEO-safe** — server-rendered content, because forums live or die on search traffic (see the
   quantified SEO-loss evidence in [community-complaints](../research/community-complaints-and-feature-requests.md)).
3. **One codebase, two tiers** — baseline (shared host) and enhanced (Docker/VPS) differ only in
   performance/real-time, never in core features (ADR-0003).
4. **Comprehensive feature surface** without major rewrites (phpBB-grade ACL, WYSIWYG, importers,
   modules, theming, real-time, search, monetization).
5. **Apache-2.0 + strict clean-room** — every dependency must be Apache-2.0-compatible (ADR-0015).

---

## 3. Why Laravel (the framework choice)

- **Batteries the brief needs, as first-party, well-licensed (MIT) components:** Eloquent ORM
  (parameterized queries → OWASP-aligned), Blade, queues with **multiple drivers incl. a database queue**
  (the key to the cron-only baseline), **Scout** (search abstraction: DB driver → Meilisearch), policies/gates
  (authorization), events+listeners (our extension hook substrate), reversible **migrations** (non-destructive
  upgrades), Cashier (Stripe, for Phase-4 monetization), Sanctum/Passport (API auth/OAuth2), Reverb
  (first-party WebSockets for the enhanced tier). Each maps directly to a brief requirement.
- **Driver abstraction is native.** Cache/session/queue/search/broadcast/filesystem are all swappable via
  config. This is precisely how we make a baseline feature degrade gracefully instead of erroring when
  Redis/Meilisearch/WebSockets are absent — the brief's central rule.
- **Security posture by default:** CSRF tokens, hashed (argon2id/bcrypt) auth, signed cookies, encryption,
  rate limiting, validation/form requests — the OWASP Top-10 surface is covered idiomatically.
- **Ecosystem & longevity:** the largest modern PHP framework; annual majors with **2 years of security
  support** per release ([endoflife.date/laravel](https://endoflife.date/laravel)). This directly attacks the
  incumbents' "painful upgrades / stagnation" problem.

PHP itself is non-negotiable given the brief's shared-hosting floor — the entire commodity-hosting market is
PHP+MySQL. The only real question is *which* PHP stack, answered below.

## 4. Why Livewire + Alpine + Blade — and why NOT an SPA

**The rejected alternative: Inertia.js + Vue/React (SPA).** Documented in the brief as a noted-not-chosen
option; confirmed rejected here.

| | **Livewire (chosen)** | **Inertia + Vue/React (rejected)** |
|---|---|---|
| SEO | Server-renders real HTML on every request — safe by construction | SPA needs SSR for SEO, which needs a **persistent Node process** |
| Shared host | Runs on PHP+MySQL+cron alone | Node SSR can't run reliably on commodity shared hosts |
| One codebase across tiers | Yes — identical on baseline & enhanced | Would force either degraded SEO on baseline or a second rendering path |
| Interactivity | Server round-trips + Alpine for local state; **Livewire 4's new diff algorithm cuts DOM updates ~60%** | Richer client interactivity out of the box |
| Team model | One language (PHP/Blade) for most UI | Requires a parallel JS/TS frontend skillset |

**Conclusion:** SPA SEO requires server-side rendering via a Node process shared hosts can't run reliably.
Livewire keeps **one SEO-safe codebase on every tier** — the decisive factor. We revisit only if SEO or
shared-hosting support is ever dropped (neither is planned). Where we genuinely need rich client behavior
(the WYSIWYG editor, drag-drop, the visual theme configurator), we drop to **Alpine + a prebuilt JS island**,
not a framework rewrite — see §7 and ADR-0012. Livewire 4's optional **Vue/React bridges** give us an escape
hatch for isolated complex widgets without abandoning the model.

## 5. Version targeting & the PHP-floor revision (ADR-0002)

I committed to targeting *latest stable*, validated live:

- **Laravel 13** (latest; requires **PHP ≥ 8.3**) — [laravel/releases](https://laravel.com/docs/13.x/releases),
  [endoflife.date/laravel](https://endoflife.date/laravel).
- **Livewire 4** (latest; v4.3.0 May 2026; Laravel 13 support; new diffing; single-file components) —
  [Livewire v4.2 release](https://laravel-news.com/livewire-v4-2-0), [Livewire releases](https://github.com/livewire/livewire/releases).
- **PHP support window** ([php.net/supported-versions](https://www.php.net/supported-versions.php)): 8.2, 8.3,
  8.4, 8.5 supported; **8.2 reaches EOL 2026-12-31**; 8.3 security-supported to 2027-12-31; 8.4 to 2028-11.

**Decision — minimum PHP 8.3, recommend 8.4+.** This *revises* my Checkpoint-approved "PHP 8.2 hard floor."
Rationale: (a) latest-stable Laravel 13 **requires** 8.3; (b) **PHP 8.2 is EOL in ~7 months** — shipping a
brand-new platform on a soon-dead runtime is indefensible; (c) by mid-2026 reputable shared hosts
universally offer 8.3/8.4 (8.2 left active support in Dec 2024); (d) the brief's own stack line already said
"PHP 8.3." Net effect on the baseline tier: floor becomes **PHP 8.3+** rather than 8.2+.
**Flagged for the Phase 0 gate** as an evidence-based deviation for your explicit sign-off.

*Maintenance posture:* track Laravel's annual major within one cycle (don't re-pin and stagnate like phpBB
3.3.x). Each upgrade is gated by our own test suite + the module-compatibility check (ADR-0008).

## 6. Baseline vs enhanced tier strategy (summary; full detail in [system-architecture](system-architecture.md))

| Capability | Baseline (shared host) | Enhanced (Docker/VPS) | Mechanism |
|---|---|---|---|
| Cache/session | file / database | **Redis** | `CACHE_STORE`, `SESSION_DRIVER` |
| Queue/jobs | **database queue drained by cron** | Redis + worker(s) | `QUEUE_CONNECTION` + `schedule:run` |
| Search | **MySQL full-text** (Scout DB) | **Meilisearch/Typesense** (Scout) | `SCOUT_DRIVER` |
| Real-time | **Livewire polling** | **Reverb/Pusher** via Echo | broadcast driver + graceful detect |
| Media | local disk | **S3/MinIO** | `FILESYSTEM_DISK` |
| Email | host SMTP (best-effort) | SES/Postmark/Mailgun | `MAIL_MAILER` |

A **service-tier detection** layer (ADR-0003) probes which services are reachable and exposes the active tier
in the installer/admin panel, with "enabling X unlocks Y" guidance — never erroring when an enhanced service
is absent.

## 7. Honest weaknesses — what Laravel does NOT give for free

The brief demands this be recorded. Laravel is a *web* framework, not a *forum* framework; the following are
net-new engineering we must design deliberately (each links to its ADR/doc):

1. **A forum-grade module/extension system.** Laravel packages exist, but a *safe, versioned, no-core-edit*
   module system with UI-slot injection, permission/setting registration, and upgrade-survival is not
   off-the-shelf. → [plugin-and-theme-system](plugin-and-theme-system.md), **ADR-0008**. *This is the single
   biggest build risk.*
2. **Visual (point-and-click) theming + a developer override layer** with no core edits. Blade gives
   templating, not a ProBoards-style configurator or diff-based theme inheritance. → **ADR-0009**.
3. **The phpBB-grade permission-mask engine** (allow/deny/**never**, global→category→forum→thread scope,
   role presets, group merge, caching, an admin "why can/can't X" inspector). → [security-and-permissions](security-and-permissions.md), **ADR-0006**.
4. **WYSIWYG ↔ Livewire integration** — a stateful TipTap editor fights server-driven DOM diffing. Mitigated
   by isolating it as an Alpine island with `wire:ignore` and prebuilt assets; Livewire 4's improved diffing
   helps. **The #1 technical risk; an early spike.** → **ADR-0012**.
5. **First-class anti-spam** — not a framework concern; we build it as a headline subsystem. → **ADR-0007**.
6. **Real-time on the baseline tier** — no daemon means polling, not WebSockets; acceptable, not free. → ADR-0003.

None is a blocker; all are designed in Stage A. But pretending Laravel "gives us a forum" would be the
mistake that sinks the project — so they are tracked as first-class risks in the [roadmap](../product/roadmap.md).

## 8. Dependency-license discipline (ADR-0015)

- **Policy:** every dependency must be **Apache-2.0-compatible** (MIT, BSD, Apache-2.0, ISC, LGPL-with-care).
  **No GPL/AGPL** in the distributed product (would force copyleft over our Apache-2.0 grant). Clean-room
  applies to *reference forums*, not to ordinary well-licensed libraries (Laravel & Livewire are MIT).
- **Per-dependency check** recorded in [DECISIONS.md](../../DECISIONS.md) when non-obvious, *before* merge.
- **Specific note — TipTap:** TipTap's **core and standard extensions are MIT** and may be used directly;
  its **Pro extensions and collaboration server are commercial**. Hearth depends on the **MIT-licensed parts
  only**; any premium-tier capability (e.g. real-time collaborative editing) must be reimplemented or sourced
  from an Apache-compatible alternative, never by pulling a commercial TipTap package.
- **Other watch-items:** Meilisearch (MIT) and Typesense (GPL-3 server, but used **out-of-process over HTTP**,
  so it's an optional external service, not a linked dependency — acceptable on the enhanced tier only and
  never bundled); Reverb (MIT); image libs (Intervention/Imagine — MIT/BSD).

## 9. Key risks & mitigations

| Risk | Mitigation |
|---|---|
| WYSIWYG↔Livewire DOM conflicts | Alpine island + `wire:ignore` + prebuilt assets; **early spike in Phase 1**; Livewire 4 diffing |
| Module system is the hardest part and easy to under-design | Treat the module + theme API as a **semver'd public contract** from day one; design before Phase 3 code |
| Big-rewrite death-march (cf. MyBB 2.0 — itself a Laravel rewrite abandoned after ~6 yrs) | **Phased, always-runnable** roadmap; ship the baseline tier at every milestone; never a "boil the ocean" branch |
| Laravel major-version churn breaking modules | Module-compatibility check (ADR-0008) + our own test suite gate each upgrade |
| Annual framework upgrades vs shared-host PHP lag | Floor at a *currently-supported* PHP (8.3); document supported matrix; CI tests the floor |

## Sources

[laravel.com/docs/13.x/releases](https://laravel.com/docs/13.x/releases) ·
[endoflife.date/laravel](https://endoflife.date/laravel) ·
[Livewire v4.2 (Laravel News)](https://laravel-news.com/livewire-v4-2-0) ·
[github.com/livewire/livewire/releases](https://github.com/livewire/livewire/releases) ·
[php.net/supported-versions](https://www.php.net/supported-versions.php) ·
[endoflife.date/php](https://endoflife.date/php).
