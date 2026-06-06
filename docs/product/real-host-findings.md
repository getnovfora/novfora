<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The Hearth Authors
-->
# Real-Host Validation — Findings & Bug Log

> Running log of what the live shared-host validation surfaced (the part no container could). Test host:
> **cPanel / CloudLinux, PHP 8.4, MySQL**, installing at a `public_html` subfolder with the app above the web
> root. Feeds the post-validation fix cycle. Status legend: **FIXED · MITIGATED · OPEN**.

## Outcome so far

**The core promise is proven on real hardware:** after the RH-1 fix, Hearth boots on a real cPanel shared host
and reaches the installer with every system check green (PHP 8.4, all extensions, all paths writable, Baseline
tier detected). Full install completion is being done via the **subdomain** layout (which works cleanly); the
**subdirectory** layout is blocked by RH-4 below.

## Findings

### RH-1 — Missing package manifest on cold boot — FIXED (`c203566`)
The `--no-scripts` bundle shipped no `bootstrap/cache/packages.php`; on a non-writable cache the cold boot threw
at `RegisterFacades` before any provider registered → "Target class [view] does not exist". Fix: the bundle now
runs `package:discover` and ships `packages.php`; the release verify does a true cold HTTP boot (no prior
`artisan`) so this can't slip through again.

### RH-2 — CloudLinux/suEXEC strictness on world-writable files — MITIGATED
Extraction can leave files `0777`, which strict PHP handlers 500 on. `hearth:doctor` now flags world/group-
writable app files, and runbook §3a adds a "set 755/644 after extract" step.

### RH-3 — Subfolder install recipe — documented (runbook §3b), but insufficient on its own — see RH-4.

### ⭐ RH-4 — Subdirectory install is broken — OPEN (owner-flagged priority)
**Requirement:** an end user must be able to install Hearth into a **subdirectory of the web root**
(e.g. `example.com/community`), not only at a domain/subdomain root. Common shared-host scenario.

**Observed** (`example.com/HearthBB`, app at `~/Hearth`): after the boot fix, the System step renders but the
wizard's **Continue (a Livewire action) does nothing**, and **`app-*.css` returns 404** (page unstyled, JS not
wired).

**Root causes (three, compounding):**
1. **Dual `public/` copies drift.** The "copy `public/` into the web subdir + edit `index.php`" layout creates
   two copies of `public/`: the app's `public/build` (where the Vite **manifest** is read) and the served
   `web/SUBDIR/build` (what the browser fetches). On any update they desync → the manifest names one asset
   hash while the served folder has another → **404 on CSS/JS** → unstyled page + dead Livewire.
2. **Base-path / URL generation under a subpath.** `route()`, Livewire's update endpoint
   (`/SUBDIR/livewire/update` + `livewire.js`), and `@vite` asset URLs must all carry the `/SUBDIR` prefix.
   That depends on correct base-path detection (SCRIPT_NAME + `RewriteBase`) and/or `APP_URL`/`ASSET_URL`,
   which is not reliable out of the box — especially **pre-install**, before any `.env` exists.
3. **Storage publish target.** The installer publishes `public/storage` into the app's own `public/`, not the
   web subdir → avatars/uploads 404 in the split layout.

**Why it needs a design pass, not a quick patch.** Subdirectory Laravel deployment is inherently fragile via
the copy layout. A robust fix needs both:
- the app **fully subpath-aware from the request** — correct `/SUBDIR/...` URLs for routes, Livewire, and
  assets with no manual `APP_URL` (so it works pre-install); and
- a **non-drifting web-dir strategy** — e.g. a symlinked `public`, or a thin forwarding stub with a single
  canonical `build/` and `storage/`, or the installer **detecting the subpath** and wiring `APP_URL`/
  `ASSET_URL` + publishing storage/assets into the web dir.

**Proposed next step:** a short **design spike + ADR ("subdirectory install support")**, then implement and add
a **subdirectory case to the install test matrix** (so it's covered, like the no-SSH cold-boot now is). Until
then, the runbook steers users to a subdomain.

### RH-5 — Stale committed assets — OPEN
The committed `public/build` CSS hash has drifted from source (a P1.5 template change wasn't rebuilt). The
*bundle* ships internally-consistent assets, but the repo's committed assets are stale — which both muddied the
RH-4 diagnosis and would ship outdated CSS to a git-based deploy. Fix: a `chore: rebuild assets` commit **plus
a CI guard** that fails the build if a fresh `npm run build` changes the committed assets (prevents recurrence).

### ⭐ RH-6 — Installer wizard front-end is dead — MISDIAGNOSED → real cause is RH-7
> **Correction (2026-06-05, live-host inspection via the browser):** the RH-6 root cause below
> ("Livewire never `start()`s because the auto-inject script runs after `DOMContentLoaded`") is **wrong**.
> Direct inspection of the live host proved Livewire boots **fine**: `livewire.js` loads exactly once
> (Performance API), the `installer.wizard` component is reactive, and `wire:click` **does** fire a
> `POST /livewire/update`. The wizard's failure is **server-side** — see **RH-7**. The RH-6 fix
> (`@livewireScripts` + boot guard in `resources/views/install/index.blade.php`) is **harmless but
> unnecessary** (the guard is a no-op because Livewire starts normally); it can stay or be reverted, but it
> was never the blocker. The "`$persist` redefine" error reported during debugging was just the expected
> result of manually calling `Livewire.start()` a second time on an already-started runtime — not a
> duplicate Alpine.

On a clean subdomain with all assets loading and Livewire+Alpine initialized (the `installer.wizard` component
is in `Livewire.all()`), the wizard's `wire:click` actions fired **no request** — `<button wire:click="toStep2">
Continue</button>` did nothing (no network call, no console error), so the install could not be completed in a
browser.

**Root cause (found by reading Livewire 4's bundle, `dist/livewire.esm.js`).** Livewire's runtime auto-starts
from a single `DOMContentLoaded` *event listener* with **no `readyState` fallback**:
`window.Alpine.__fromLivewire = true` runs synchronously (so Alpine is always "present"), but `Livewire.start()`
— which builds the `$wire` proxy and binds every `wire:` directive — runs **only when that event fires**. The
standalone pre-install layout delivered the runtime **solely** via Livewire's response-rewrite auto-injection (a
plain `<script>` appended before `</body>`). On a real cPanel host a server/JS-optimizer layer (LiteSpeed Cache,
Cloudflare Rocket Loader — near-ubiquitous on shared hosting) **defers/delays that script so it executes *after*
`DOMContentLoaded` already fired** → the listener never runs → `start()` is never called → Alpine is attached
(`__fromLivewire:true`) but `wire:click`/`wire:model` never bind and `$wire` has no methods. That is the reported
symptom exactly. (A clean `php artisan serve` runs the script synchronously *before* `DOMContentLoaded`, so it
worked locally — which is why the gap was invisible and why the new browser test is what proves operability.)
The reporter's "`$wire.toStep2` is not a function" was reproduced on a *working* wizard too, so it was an
`Livewire.first()` introspection artifact; the actionable symptom was "`wire:click` fires no request."

**Fix** (`resources/views/install/index.blade.php`, Blade-only — no JS/CSS rebuild): the standalone layout now
declares Livewire's runtime **itself** — explicit `@livewireStyles`/`@livewireScripts` (deterministic delivery
instead of relying on response post-processing; FrontendAssets' render-guards stop auto-injection from
double-injecting) — plus a tiny **boot guard** that calls `Livewire.start()` once if the bundle finished loading
after `DOMContentLoaded` already fired, with a `livewire:init` flag so it can never double-start the normal path
(the data-* attributes ask common optimizers to leave the guard alone; CSP nonce honoured under the strict-CSP
toggle).

**Coverage that was missing** (the real reason this slipped through — Dusk only ever drove the editor, never
`/install`):
- `tests/Browser/InstallerWizardTest.php` — drives the **FULL wizard** in real Chrome with real clicks/keystrokes:
  system → setup token → database (a disposable MySQL) → site & admin → install → **Done**, then asserts the
  installer **locks** (`/install` 403s). Every Continue is a `wire:click` and every field a `wire:model`, so a
  regression to the dead front-end fails it at step 1. Harness: `docker/dusk/compose.yml` (+ `run.sh`).
- `tests/Feature/Install/InstallerLayoutTest.php` — in-process guard (no browser): renders `/install` with
  Livewire auto-injection **disabled** and asserts the runtime is still shipped from the layout, the boot guard
  is present, and there is exactly **one** Livewire bundle (no duplicate). Fails on a revert to "auto-inject only".

Kickoff: [installer-fix-kickoff.md](installer-fix-kickoff.md).

### ⭐ RH-7 — Install-enforce middleware redirects Livewire's update endpoint → wizard can't complete — FIXED
**This was the actual reason the wizard "does nothing."** Confirmed by direct live-host inspection
(`hearth.adorablespider.com`, cPanel) through the browser, including a manual replay of the Livewire request:

**Proof (live host, pre-install, enforcement ON):**
- `livewire.js` loads once; the `installer.wizard` component is reactive; clicking **Re-check** *does* fire a
  request. The runtime is healthy.
- Every action then fails with `SyntaxError: Unexpected token '<', "<!DOCTYPE "... is not valid JSON` thrown
  inside `livewire.js` at `JSON.parse`.
- Replaying the update request shows why: `POST /livewire-2cd208c8/update` → **302, `redirected:true`,
  `finalUrl: /install`**, body = the install page HTML (`<!DOCTYPE html> … <title>Install Hearth</title>`).
  Livewire expects JSON, receives HTML, throws, and falls back to a **full page reload** → the wizard snaps
  back to a blank step 1. That is the "pasted the token, nothing happens" symptom exactly (the token field
  even clears, because the page reloaded).

**Root cause:** `app/Http/Middleware/RedirectIfNotInstalled.php` allowlists `'livewire/*'`, but **Livewire 4
serves its update/asset routes under a hashed prefix** — `livewire-<hash>/update` (observed
`livewire-2cd208c8/update`). `$request->is('livewire/*')` does **not** match `livewire-2cd208c8/update` (the
`-<hash>` breaks the `livewire/` prefix), so the wizard's own AJAX POST falls through the allowlist and is
redirected to `/install`. (The `livewire.js` **asset** route sits outside the enforced `web` group, so it is
*not* redirected — which is why the runtime boots and the failure looked like a front-end bug. A `GET` to the
update path returns 405, not a redirect, because method-not-allowed is thrown during routing before the
`web`-group middleware runs; only the real `POST` reaches the middleware and gets redirected.)

**Why every test missed it:** the wizard's only automated coverage runs with `HEARTH_INSTALL_ENFORCE=false`
(`Installer::shouldEnforce()` opts the test suite out), so `RedirectIfNotInstalled` is a no-op in CI and the
redirect never happens. The bug only appears with enforcement **on** — i.e. exactly the real-host pre-install
state, which nothing exercised.

**Fix (landed, server-side, surgical — `app/Http/Middleware/RedirectIfNotInstalled.php`).** The allowlist now
matches Livewire's *actual* hashed update endpoint, two complementary ways so it can't silently drift:
- a hash-agnostic **static pattern** added alongside the original — `'livewire-*/*'` (kept `'livewire/*'` too,
  for a custom un-hashed route). `Str::is` treats `*` as spanning `/`, so `livewire-*/*` matches
  `livewire-2cd208c8/update` and `livewire-2cd208c8/livewire.js`;
- the **live update path derived from Livewire at runtime** — `ltrim(app('livewire')->getUpdateUri(), '/')`,
  appended to the allowlist (guarded by try/catch; an empty result is never passed to `is()`, which would
  spuriously match the site root). This stays correct if the hash/route changes between builds or versions.

The rest of the allowlist (`install`, `install/*`, `build/*`, `vendor/*`, `up`, `health`, `favicon.ico`) is
unchanged, and a normal page is still redirected to the wizard (pinned by a test). A repo-wide sweep confirmed
no other code assumes the un-hashed `livewire/` prefix.

**Regression coverage (the missing test, now present) — `tests/Feature/Install/InstallerEnforcedLivewireTest.php`.**
These run with enforcement **ON** (the real pre-install state the suite at large opts out of) and exercise the
**real web-middleware stack** — not `Livewire::test()`, which disables middleware and is exactly why this
slipped through. They render `/install`, read the live update URI + the component snapshot from the page, and
`POST` a faithful Livewire update (the `X-Livewire` header + JSON body the JS client sends):
- the hashed update path is asserted to *not* match `livewire/*` but *to* match `livewire-*/*` (the root cause);
- the middleware lets the hashed update path through, yet still redirects a non-allowlisted page (no over-broadening);
- a real `POST` to the update endpoint is **not** redirected to `/install` (`assertOk`, real Livewire JSON body);
- a real wizard action (`Continue` → `toStep2`) **advances to step 2** end-to-end through the update endpoint.

Verified: these three failed on the unfixed middleware (`Expected 200, received 302` → `/install`) and pass
after the fix; full Pest **319 passed / 1 skipped (1047 assertions)**, Pint + Larastan + `composer audit` clean.
The deployable bundle was rebuilt (`scripts/build-release.sh`) and cold-boot-verified (fresh extract, empty
`APP_KEY`, no DB → `GET /` → **302 → /install**): `hearth-release.zip` **12,937,205 bytes**, sha256
`ebff39444dae1f6357e0f7b9c27fe5e0d4ad1ac58687d12da447ab15d27db956` (ships `bootstrap/cache/packages.php`; the
fixed middleware is inside). Kickoff: [installer-redirect-fix-kickoff.md](installer-redirect-fix-kickoff.md).

> **Note on browser coverage.** RH-7 is a purely *server-side* middleware redirect — a real browser adds nothing
> over an in-process `POST` through the same stack, so the enforcement-ON feature tests above are the authoritative
> guard and they run in the normal Pest CI job (no Chrome/MySQL needed). The Dusk `InstallerWizardTest` keeps
> running with `HEARTH_INSTALL_ENFORCE=false` on purpose: it shares one served app with `EditorJourneyTest`, which
> must reach `/forums` etc. and would be redirected to `/install` under enforcement (no install marker). Splitting
> the harness into a second enforce-ON serve pass is a possible follow-up but was out of scope here.

## Next

1. **RH-7 — FIXED in code + regression test + rebuilt bundle (this pass).** The `RedirectIfNotInstalled` allowlist
   now matches Livewire's hashed update endpoint, enforcement-ON regression tests are in place, and the bundle is
   rebuilt + cold-boot-verified. **Human step:** re-upload the new `hearth-release.zip` and complete the install on
   the live host — this is the one that actually unblocks the wizard.
2. Re-run the **subdomain** install + the §6 acceptance checklist → confirms the full end-to-end install on a
   real host (the validation's primary goal).
3. Fix cycle: **RH-4 design-first** (spike → ADR → implement + test), then **RH-5** (rebuild assets + CI guard).
   RH-1/RH-2 landed; RH-6 was a misdiagnosis (superseded by RH-7, now fixed).
