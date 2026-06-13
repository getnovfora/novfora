<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# Writing your first plugin & theme

> The PROVEN extensibility contract, written after dogfooding it: the first-party `novfora/hello`,
> `novfora/qa`, and `novfora/kudos` plugins and the `aurora` / `nebula` themes are all built **purely** through
> what's below — **zero core edits**. **Module API: `App\Modules\ModuleApi::VERSION` = `1.1.0`.**
> **Theme API: `App\Theme\ThemeApi::VERSION` = `1.0.0`.** Within a major, the surface is frozen (additions are
> minor). Full rationale: [`module-system.md`](module-system.md), [`theming-layout.md`](theming-layout.md),
> and the `DECISIONS.md` Phase-3 + hardening + dogfood entries.

---

## 1. The trust boundary (read first)

A module runs **in-process with full PHP server trust** — it can do anything your app can. There is **no
sandbox** (true PHP sandboxing isn't feasible; this is the honest position). So:

- Modules are **local** packages under `modules/<vendor>/<name>/` — no remote fetch, no marketplace, no `eval`.
- Enabling one requires an **explicit admin consent** ("this runs with full server trust"), recorded once.
- An **integrity hash** is recorded at install/enable/upgrade; the ACP flags files changed since (`verified` /
  `modified`).
- A module whose provider throws while loading is **quarantined** (auto-disabled + the error shown), and a
  file-based **kill switch** (`storage/modules-safe-mode`) disables ALL modules instantly — your escape hatch.

**Only install modules you trust.**

---

## 2. Anatomy of a plugin

```
modules/<vendor>/<name>/
  module.json                    # the manifest (validated, fail-closed; the untrusted-input boundary)
  src/<Name>ServiceProvider.php  # PSR-4 entrypoint (namespace declared in the manifest)
  database/migrations/<...>.php   # reversible migrations, run on enable / rolled back on remove
```

### `module.json`

```jsonc
{
  "name": "Kudos",
  "slug": "novfora/kudos",          // vendor/name — lowercase tokens only (path-safe; can't traverse modules/)
  "version": "1.0.0",               // your module's own semver
  "api_version": "^1.1",            // the MODULE API you target (^1.1 needs the topic.post.aside slot + settings)
  "namespace": "Modules\\Novfora\\Kudos\\",       // PSR-4 root (NOT a reserved root: App\, Illuminate\, …)
  "provider": "Modules\\Novfora\\Kudos\\KudosServiceProvider",  // MUST live inside your namespace
  "permissions": [
    { "key": "novfora.kudos.give", "label": "Give kudos", "scope_kind": "global", "group": "Kudos" }
  ],
  "provides": ["routes","listeners","filters","slots","widgets","permissions","settings","migrations"]
}
```

The validator refuses anything unsafe (bad slug, reserved namespace, provider outside the namespace,
unparseable version/constraint, malformed permission). Declared `permissions` only **ADD** to the catalog —
they're never grants, and a module may never redefine a core key.

### The service provider — wire seams in `boot()`

```php
final class KudosServiceProvider extends Illuminate\Support\ServiceProvider
{
    public function boot(): void
    {
        // EVENT — listen to a core domain event (PostCreated, TopicCreated, Followed, ReputationAwarded, …)
        Event::listen(\App\Events\PostCreated::class, fn ($e) => /* … */);

        // FILTER — transform a value a core call site exposes; output is RE-sanitised by core.
        \App\Modules\Facades\Hook::addFilter('post.html', fn (string $html) => str_replace('[kudos]', '👍', $html));

        // SLOT — render sanitised HTML into a named outlet. Per-post UI: 'topic.post.aside' (gets post+topic).
        app(\App\Modules\SlotRegistry::class)->addSlot('topic.post.aside', fn (array $ctx) => /* sanitised html */);

        // WIDGET — register a layout widget an admin can place into a region (extend App\Theme\Widget).
        app(\App\Theme\WidgetRegistry::class)->register(new KudosWidget);

        // SETTING — register a typed, plugin-owned setting (can't override a core key).
        \App\Settings\SettingsRegistry::register(new \App\Settings\SettingDefinition(
            'kudos.glyph', 'string', default: '👍', group: 'modules', label: 'Kudos glyph'));
        // read it: app(\App\Settings\Settings::class)->string('kudos.glyph')

        // ROUTE — register routes. Use path-based url('/kudos/give') (robust under route:cache); gate with
        // $user->canDo('novfora.kudos.give', \App\Permissions\Scope::global()) — the SAME engine as the web UI.
        Route::middleware('web')->post('/kudos/give', /* … */);
    }
}
```

**Security guarantees (enforced by core, not by you):** slot + `post.html` filter output is re-sanitised
through the post-HTML allowlist (no `<script>` can be smuggled in); a throwing filter/slot is isolated
(reported + skipped, never a 500); permission checks resolve only through `PermissionResolver`.

### Lifecycle (ACP → `ModuleManager`)

`discover → install → enable (consent + compat + deps + permissions + migrations) → disable (keeps data) →
upgrade → remove (rolls back migrations + drops owned permission keys & grants)`. Everything is audited.

---

## 3. Anatomy of a theme

```
themes/<slug>/
  theme.json                              # slug, name, version, api_version
  views/partials/theme-head.blade.php     # inject <style> — override the ThemeApi token contract
  views/partials/footer-tagline.blade.php # any core view you want to override
  views/<any/core/view>.blade.php         # resolution is: active theme → parent → core
```

A theme restyles by **overriding CSS custom properties** (the `ThemeApi::tokens()` contract — semantic aliases
like `--novfora-accent` + the AA-safe `--accent…` palette), never by editing core markup. Derive accent
variables with `App\Support\AccentPalette::for('#hex')` so light/dark inks meet WCAG AA. Activate with
`NOVFORA_THEME=<slug>` (or Admin → Appearance). See `themes/aurora` (palette + footer) and `themes/nebula`
(token-contract override) for complete, tested examples. Themes **coexist** with module slots and the admin
layout/region configurator — those keep working under any theme.

---

## 4. Versioning the contract you depend on

Declare the API major you target (`api_version`) and the engine checks it **before** enabling ("know before you
enable"). Adding events / filter names / slot names / regions / tokens = **minor**; changing or removing a
payload, name, signature, or lifecycle behaviour = **major**. Pin `^1.1` if you use the `topic.post.aside` slot
or plugin settings.
