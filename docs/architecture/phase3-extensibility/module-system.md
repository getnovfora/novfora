<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# Phase 3 — Module / Plugin foundation (B1)

> The semver'd module API and its lifecycle, hooks, slots, and security model. Builds ADR-0008 (the Stage A
> plan) into running code. **Status: Accepted — owner-authorized overnight build; flagged for review (ADR-0031).**

## 1. What a module is

A module/plugin is a **local package** an admin drops under `modules/<vendor>/<name>/`. There is **no remote
fetch, no marketplace, no `eval` of downloaded code** — installation is a filesystem action; enable/disable is
an audited admin action in the ACP. A module runs **in-process with full PHP trust** (true PHP sandboxing is
not feasible — the honest position from ADR-0008 §2.4); the mitigations (H3) are: a validated manifest, an
explicit **full-trust consent gate** before a first enable, a **package integrity hash** (tamper detection),
**disable-on-fatal** quarantine + a global **kill switch**, lifecycle auditing, an ACP that shows exactly what
is enabled, and the hard guarantees below.

```
modules/<vendor>/<name>/
  module.json                       # the manifest (validated, untrusted input)
  src/<...>.php                     # PSR-4 code (namespace declared in the manifest)
  database/migrations/<...>.php     # reversible migrations, run on enable
```

## 2. The manifest (the contract surface)

```jsonc
{
  "name": "Hello World",
  "slug": "novfora/hello",          // vendor/name — lowercase tokens, path-safe
  "version": "1.2.0",               // the module's own semver
  "api_version": "^1.0",            // the MODULE API the module targets (App\Modules\ModuleApi::VERSION)
  "namespace": "Modules\\Novfora\\Hello\\",          // PSR-4 root (non-core)
  "provider": "Modules\\Novfora\\Hello\\HelloServiceProvider",
  "requires": { "php": ">=8.3", "modules": { "acme/core": "^1.0" } },
  "permissions": [ { "key": "novfora.hello.manage", "label": "...", "scope_kind": "global" } ],
  "provides": ["routes", "listeners", "filters", "slots", "permissions", "migrations"]
}
```

`App\Modules\ManifestValidator` is the **fail-closed** boundary. It refuses, with an operator-facing message:
a slug that isn't `vendor/name` of lowercase tokens (so it can never traverse out of `modules/`); a namespace
on a **reserved root** (`App\`, `Illuminate\`, …) that would shadow core; a provider **outside** the module's
own namespace; an unparseable `version` / `api_version` / dependency constraint; and malformed permission
entries. Anything unexpected throws — nothing is coerced silently.

## 3. Extension seams (the semver'd public API)

| Seam | Mechanism | Sanitisation |
|---|---|---|
| **Domain events** | Laravel events the core already fires (`PostCreated`, `TopicCreated`, `Reacted`, `Followed`, `MessageSent`, `ReputationAwarded`, …); a module `Event::listen`s in its provider | n/a |
| **Filter hooks** | `Hook::applyFilters($name, $value, …)` runs registered callbacks in priority order; `Hook::addFilter(...)` registers. A core call site is a no-op until a module opts in | HTML filter output is **re-sanitised** by the call site (e.g. `post.html`) |
| **UI slots** | `<x-slot-outlet name="footer.widgets" />` in a core template; `SlotRegistry::addSlot(...)` registers a renderer | slot output is **sanitised through the post-HTML allowlist** before it reaches the page |
| **Routes / Livewire** | the module's provider registers routes/components | n/a |
| **Permissions** | manifest `permissions[]` register into the existing catalog on enable | participates in the existing `PermissionResolver` — **no second permission system** |
| **Migrations** | `database/migrations/` run on enable, rolled back on remove | reversible by construction |

**Versioning (the contract):** adding events / filter names / slot names = **minor**. Changing or removing a
public event payload, filter name+signature, slot name, or lifecycle behaviour = **major**. The core declares
its MODULE API version as `App\Modules\ModuleApi::VERSION`; a module declares the constraint it targets and the
engine checks **before** enabling — "know before you enable", never breakage-after-upgrade.

## 4. Lifecycle (`App\Modules\ModuleManager` — the single audited authority)

```
discover  → scan modules/ for valid manifests (invalid ones skipped, surfaced per-slug)
install   → record (disabled) after a compatibility check
enable    → assert compat + dependencies → register permission keys → run migrations → mark enabled (audited)
disable   → stop loading the provider; KEEP schema + data (re-enable is non-destructive)
upgrade   → re-validate; run new migrations; bump the recorded version
remove    → roll back migrations → drop owned permission keys + their grants → delete the row (audited)
```

`App\Modules\ModuleLoader` boots **enabled** modules each request: it registers the module's PSR-4 namespace
with a runtime autoloader and `register()`s its provider. A module whose files vanished or whose manifest no
longer validates is **silently skipped** — never a fatal boot error.

## 5. Security guarantees (apex — the project's highest-stakes boundary)

1. **No permission escalation.** A module's manifest permission keys only **ADD** to the catalog; grants stay
   separate `acl_entries` an admin must create. A module **may never redefine a core permission key**
   (refused at enable) and may not claim a key another module owns. Removal deletes the module's keys **and any
   grants that referenced them**, so no dangling ACL is left behind.
2. **No unsanitised HTML.** Every slot renderer's output and every `post.html` filter's output passes through
   the same server-side allowlist (`App\Content\ContentSanitizer`) as user post HTML — `<script>`/`<style>`
   and unsafe attributes are stripped regardless of what the (full-trust) module returns.
3. **No path traversal / class shadowing.** The validated slug bounds the directory to `modules/<vendor>/<name>`;
   the namespace can't be a core root; the provider must live inside the module's own namespace.
4. **Fail-closed + audited.** Compatibility and dependency checks run before enable; every lifecycle write is
   `Audit::log`'d (`module.installed|enabled|disabled|upgraded|removed`).
5. **Trust guardrails around the full-trust model (H3).** Enabling a module is refused until an admin
   **explicitly consents** to its full-server-trust ("I trust this module"), recorded once per module and
   audited. Install/enable/upgrade record a **package integrity hash** (sha-256 over the manifest + `src/` +
   migrations); the ACP flags a module whose on-disk files no longer match it (`verified` / `modified`). A
   module whose provider **throws while loading is quarantined** (auto-disabled + the error recorded and shown
   in the ACP), so one bad module cannot white-screen the site. A file-based **kill switch**
   (`novfora.modules.safe_mode_marker`) loads NO modules while present — an operator's escape hatch that works
   over FTP with no DB access. *(A real PHP sandbox remains out of scope — documented; these are the honest
   mitigations for a full-trust, local-install model.)*

## 6. The example plugin (`modules/novfora/hello`)

A first-party plugin that exercises every seam — a permission, a `PostCreated` listener, a `post.html` filter,
a `footer.widgets` slot, a `/hello` route, and a reversible migration — and doubles as the lifecycle's living
integration test (`tests/Feature/Modules/ModuleLifecycleTest.php`).

## 7. Tests

`tests/Feature/Modules/`: adversarial manifest validation; the compatibility checker (caret/exact/≥/wildcard);
the hook + slot sanitisation contracts; the full install→enable→exercise→disable→remove lifecycle on the real
example plugin; incompatible-API / missing-dependency / core-permission-collision refusals; and the ACP
authz + inline-error surface.
