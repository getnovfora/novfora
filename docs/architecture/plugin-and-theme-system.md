# Plugin / Module & Theme System

> **Project:** NovFora (working codename). **Stage A deliverable** (Section 8 #9). **Date:** 2026-06-01.
> The versioned **module/extension API**, the **dual (visual + developer) theming** model, and the
> **resumable, verifying importer** architecture — none of which require editing core. These are the brief's
> answer to the incumbents' worst traits: upgrade-breaking add-ons (C3), theming-needs-core-edits (C4), and
> fragile migration (C7). Related ADRs: **ADR-0008** (modules), **ADR-0009** (theming), **ADR-0013**
> (importers). The module & theme APIs are **semver'd public contracts — a breaking change is a major-version
> event.**

---

## 1. Why this is make-or-break

Laravel does **not** give a forum-grade extension or visual-theming system for free
([stack §7](technical-stack-recommendation.md)). Every incumbent's biggest pain — add-ons and themes breaking
on upgrade, customization requiring core edits — is a *direct consequence* of a bad extension/theme
architecture (MyBB's `eval()` templates + `find_replace`; SMF's core-file patches). NovFora treats this as the
**highest-leverage design problem**, designed in Stage A and stabilized before Phase 3 builds it.

**Governing principles:**
1. **No core edits, ever** — modules and themes extend via declared seams only.
2. **Semantic versioning of the public API** — modules/themes declare the API major they target; upgrades
   check compatibility *before* anything breaks (the inverse of "upgrade, then discover breakage").
3. **Reversible by construction** — module migrations roll back; theme overrides are additive; uninstall is clean.
4. **Open, non-gatekept directory** — anyone can publish; we provide checksums/signing + a security policy, not
   a paywalled gate (the brief's openness stance, and the anti-Invision lesson C9).

---

## 2. Module / extension system (ADR-0008)

### 2.1 Anatomy

A module is a self-contained Laravel package (in `modules/<vendor>/<name>/` or Composer-installed) with a
**manifest** declaring identity and the **API major it targets**:

```jsonc
// module.json
{
  "name": "Acme Birthday Greetings",
  "slug": "acme/birthdays",
  "version": "1.4.0",          // the module's own semver
  "api_version": "^2.0",       // the NovFora MODULE API major(s) it supports
  "requires": { "php": ">=8.3", "modules": { "acme/core-utils": "^1.0" } },
  "provides": ["routes", "permissions", "settings", "slots", "migrations", "listeners"]
}
```

A `ModuleServiceProvider` is the single registration entrypoint; the core **never** references a module by name.

### 2.2 Extension seams (the public surface)

| Seam | Mechanism | Example |
|---|---|---|
| **Domain events** | Laravel events fired across core (`PostCreated`, `UserRegistered`, `TopicMoved`, …) | award a badge on `PostCreated` |
| **Filter hooks** | a synchronous filter pipeline transforming a value through registered callbacks | filter rendered post HTML; adjust a resolved permission set |
| **UI slots** | named Blade slot components (`<x-novfora::slot name="topic.sidebar"/>`) modules inject into | add a widget to the thread sidebar **without touching the template** |
| **Routes & controllers** | modules register their own routes/Livewire components | a `/birthdays` page |
| **Permissions** | modules register new permission keys into the ACL catalog | `acme.birthdays.manage` participates in the same mask engine |
| **Settings & admin pages** | register settings + an ACP panel | module config UI |
| **Migrations** | ship **reversible** migrations, run on enable, rolled back on disable/uninstall | a `birthdays` table |
| **CLI / scheduled jobs** | register Artisan commands and scheduler entries (cron-safe) | nightly birthday sweep |

The **public API** = these contracts + event/hook/slot names + published facades/interfaces. Everything else is
internal and may change between minors. The surface is documented and **frozen within a major**.

### 2.3 Versioning, compatibility & upgrade safety

- **Semver of the module API (the contract).** Adding events/hooks/slots = minor. Changing/removing a public
  signature, event payload, or slot = **major**. Deprecations live **one full major** with runtime warnings
  before removal.
- **Pre-upgrade compatibility check.** Before a core upgrade, NovFora compares each installed module's
  `api_version` against the new core's supported API range and produces a **report** (compatible / needs-update
  / incompatible). Incompatible modules are **disabled, not silently broken**, and the admin is told exactly
  which and why — turning C3's "upgrade then discover breakage" into "know before you upgrade."
- **Reversible everything.** Disable rolls back module migrations; uninstall purges cleanly; core schema changes
  are reversible Laravel migrations with a restore path (brief hard rule).

### 2.4 Trust model (honest)

Modules run **in-process with full PHP trust** — as in every forum ecosystem; true sandboxing of PHP is not
feasible. Mitigations: an **open but checksummed/signed** directory, a published **security policy** and review
norms, capability/permission registration that is visible in the ACP, and an audit log of module install/enable
events. We document this plainly rather than implying isolation we can't provide.

### 2.5 Public REST API & webhooks (Phase 3)

The same extensibility stance extends outward: a **versioned public REST API** (`/api/v1/…`, token-scoped via
Sanctum; OAuth2 provider via Passport in Phase 4) over core resources, plus **outbound webhooks** on domain
events (the same events modules listen to) for no-code integrations. API versioning follows the same semver
contract as modules.

---

## 3. Dual theming (ADR-0009)

Two audiences, one system, **no core edits** for either.

### 3.1 Visual configurator (non-technical admins — the ProBoards/XenForo-"Style-Properties" north star)

- **Style tokens** — named values (colors, fonts, spacing, radii, shadows) stored in `themes.settings`
  ([data-model §8](data-model-initial.md)), edited in a **point-and-click admin UI with live preview**, compiled
  to **CSS custom properties**. No CSS knowledge required.
- **Light/dark** variants as token sets; **per-forum styling** via scoped token overrides.
- **Layout/widgets** — a drag-to-arrange widget/slot layout for configurable regions (home, sidebars), built on
  the same UI-slot system modules use.

### 3.2 Developer theme layer (diff-based Blade overrides)

- A **child theme** is a package (`themes/<vendor>/<name>/`) with a manifest, Blade view overrides, and assets.
- **Parent-fallback resolution:** NovFora resolves a view from `active theme → parent theme → core`, so a child
  **stores only the views it changes** (XenForo's diff-inheritance concept, native to Blade's view finder). No
  core template is ever edited; upgrades don't clobber customizations.
- **Assets** are built with **Vite and shipped prebuilt** (no Node on the host). Themes can ship compiled
  CSS/JS.
- **Theme API is semver'd** exactly like the module API; theme `api_version` participates in the same
  pre-upgrade compatibility check.

### 3.3 Accessibility floor for themes (ADR-0016 carry-forward)

A11y is **baked in now**, not deferred to Phase 5 polish:

- **Minimum color contrast:** the configurator validates token combinations against **WCAG 2.1 AA** (4.5:1 body
  text, 3:1 large text/UI) and **warns/blocks** failing combos before they can be saved or published.
- **Keyboard operability is guaranteed by core components** — focus order, visible focus states, skip links,
  and ARIA live in the base components; themes may **restyle but not remove** them. A theme-validation step
  flags overrides that strip focus styles or required attributes.
- Result: a non-technical admin literally **cannot ship an inaccessible-by-contrast theme** through the
  configurator, and a developer theme is linted for the same.

---

## 4. Importer architecture (ADR-0013) — resumable, verifying, SEO-preserving

Directly engineered against the documented migration failure modes (C7: missing attachments, lost passwords,
mangled embeds, outright failure on large boards) and SEO loss (C5).

### 4.1 Shape

- **Source drivers** for **phpBB (3.x), MyBB (1.8), SMF (2.x)** (+ **XenForo** as a stretch). Each driver reads
  the legacy DB **read-only** and its file store, mapping to NovFora entities. Importers are **our own code that
  copies *data*** — never the reference program (clean-room).
- **Resumable batches:** work runs as **checkpointed, idempotent** queue jobs (offsets persisted), so a
  multi-million-post import survives interruption and **runs within cron windows** on the baseline tier. Re-runs
  are safe.

### 4.2 The three-stage workflow (the C7 answer)

1. **Dry-run / pre-flight** — validate source connectivity; **count** users, forums, topics, posts, and
   **attachments**; detect schema version; produce a **plan + warnings report** *before any write*.
2. **Import** — batched, resumable, with a live progress + running discrepancy log.
3. **Verify** — post-import reconciliation: counts match the plan; **every referenced attachment is resolved or
   explicitly flagged** (checksum-backed via `attachments.checksum`); a downloadable exceptions report.

### 4.3 Fidelity guarantees

- **Passwords preserved** — legacy hashes imported and **re-hashed to argon2id on first successful login** (the
  incumbents' approach); **no password loss**, no forced reset.
- **Content** — BBCode/legacy HTML → **canonical format** (ADR-0005), preserving quotes, code, spoilers, and
  re-resolving **oEmbed** so embeds don't degrade to plain links.
- **Permissions/users/groups** mapped with an **unmapped-items report** rather than silent drops.
- **SEO redirect maps** — every importer emits **301 redirect maps** from legacy URL patterns to new canonical
  slugs, so link equity survives the move ([system-architecture §6](system-architecture.md)). This is the single
  most-skipped migration step and the cause of the −95%-traffic horror stories.

### 4.4 Resilience

Designed to **not fail wholesale on large boards**: batching + checkpointing means a bad row is logged and
skipped into the exceptions report, not a fatal abort; the import is **resumable from the last checkpoint**.

## Cross-references

Content canonical format the importer targets: [data-model §3](data-model-initial.md) · Permission catalog
modules extend: [security-and-permissions](security-and-permissions.md) · Redirect maps & sitemaps:
[system-architecture §6](system-architecture.md) · Dependency licensing (theme/editor libs):
[technical-stack-recommendation §8](technical-stack-recommendation.md) · Test coverage for module/theme APIs &
importers: [testing-strategy](testing-strategy.md).
