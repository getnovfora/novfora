<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# Real-Host Validation — Findings & Bug Log

> Running log of what the live shared-host validation surfaced (the part no container could). Test host:
> **cPanel / CloudLinux, PHP 8.4, MySQL**, installing at a `public_html` subfolder with the app above the web
> root. Feeds the post-validation fix cycle. Status legend: **FIXED · MITIGATED · OPEN**.

## Outcome so far

**The core promise is proven on real hardware, end-to-end:** after the RH-1 fix NovFora boots on a real cPanel
shared host with every system check green (PHP 8.4, all extensions, all paths writable, Baseline tier), and
after RH-7 the **no-SSH install completes through the browser** on the subdomain layout — wizard → demo
community → topics render (validated on `nevo.adorablespider.com`). The post-install smoke then surfaced two
real post-install bugs — **RH-8** (root served Laravel's scaffold page) and **RH-9** (a poisoned fragment cache
500'd `/forums`) — both now **FIXED** (below). The two no-SSH operability gaps the doc-vs-reality audit then
surfaced are also closed: **RH-10** (auto-upgrade — "it migrates automatically" is now true) and **RH-11**
(the Backups panel can now **restore**, not just create) — so a no-SSH operator has a real upgrade *and*
recovery path. The **subdirectory** layout remains blocked by RH-4.

## Findings

### RH-1 — Missing package manifest on cold boot — FIXED (`c203566`)
The `--no-scripts` bundle shipped no `bootstrap/cache/packages.php`; on a non-writable cache the cold boot threw
at `RegisterFacades` before any provider registered → "Target class [view] does not exist". Fix: the bundle now
runs `package:discover` and ships `packages.php`; the release verify does a true cold HTTP boot (no prior
`artisan`) so this can't slip through again.

### RH-2 — CloudLinux/suEXEC strictness on world-writable files — MITIGATED
Extraction can leave files `0777`, which strict PHP handlers 500 on. `novfora:doctor` now flags world/group-
writable app files, and runbook §3a adds a "set 755/644 after extract" step.

### RH-3 — Subfolder install recipe — documented (runbook §3b), but insufficient on its own — see RH-4.

### ⭐ RH-4 — Subdirectory install — RESOLVED (ADR-0070 + ADR-0071, 2026-06-16)
**Requirement:** an end user must be able to install NovFora into a **subdirectory of the web root**
(e.g. `example.com/community`), not only at a domain/subdomain root. Common shared-host scenario.

**Observed** (`example.com/NovForaBB`, app at `~/NovFora`): after the boot fix, the System step renders but the
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

**Resolution (2026-06-16, branch `claude/rh4-subdir-install`).** Design spike → **ADR-0070** (subdirectory
install) + **ADR-0071** (canonical home at the mount root). Shipped: (1) the forum index IS the home at the
mount root (`/` serves it; `/forums` 301s back) so a subpath install lands on `/community/`, not
`/community/forums`; (2) a conservative request-time **base-path detector** (`App\Support\Http\BasePathDetector`)
that forces the URL/asset root from the request only when `APP_URL` is unset/localhost — Laravel already carries
the subpath via the request base path (SCRIPT_NAME/RewriteBase), and the detector confirms + pins it without
ever overriding a real `APP_URL` or touching the root layout; (3) the installer auto-detects the subpath, pre-
fills the Site URL, and writes `APP_URL` + `ASSET_URL`; (4) **Option A** (symlinked `public/`, default) /
**Option B** (`novfora:subdir:scaffold` — generated stub + `.htaccess` + build/storage links) / **Option C**
(copy, last resort) for a single canonical `build/` + `storage/` (no drift); (5) a subdirectory case + a root-
layout regression guard + a rebuild-drift guard in the install test matrix. The runbook §3b is rewritten with
Options A/B/C + a concrete Hostinger walkthrough. **The §3b copy layout is now the documented last resort, not
the default.**

### RH-5 — Stale committed assets — FIXED
The committed `public/build` CSS hash had drifted from source (a P1.5 template change was never rebuilt). The
*bundle* shipped internally-consistent assets, but the repo's committed assets were stale — which both muddied
the RH-4 diagnosis and would ship outdated CSS to a git-based deploy.

**Root cause (confirmed):** the committed `app-QDMk9TCF.css` carried Tailwind utilities (`--tw-translate-*`,
`--tw-rotate-*`, `--tw-skew-*`, `space-x-reverse`) emitted for templates that were since changed/removed (e.g.
RH-8 deleted `welcome.blade.php`). A fresh `npm run build` produces `app-BzzAoEro.css` — the JS/font assets stay
byte-identical (verified), only `app.css` shrinks (42,977 → 18,200 bytes). app.css has zero `@font-face` rules
(the fonts ship as a separate `fonts-DkuEHybc.css`), so it is fully independent of the remote font fetch.

**Fix (this pass):**
- **Rebuilt + committed** `public/build` (the fresh `app.css` + its `manifest.json` entry; all other hashed
  assets unchanged) as a `chore(assets)` commit.
- **CI freshness guard** — the `assets` job (`.github/workflows/ci.yml`) now runs an **`assets-fresh`** step
  after `npm run build`: `git diff --exit-code -- public/build` fails the build whenever the committed bundle
  drifts from a fresh build (with a clear "rebuild + commit" error). Cheap — it reuses the build the budget
  step already runs.
- **Rule documented** in `CONTRIBUTING.md`: UI/template/JS/config changes must rebuild and commit `public/build`
  in the same PR; CI enforces it.
- **Sanity net in-app** — `tests/Feature/Assets/ViteManifestTest.php` renders the `@vite([...])` head and
  asserts every referenced hash exists on disk (plus a full manifest→disk consistency check), so a rendered
  page can never point at a missing asset. (Verified to fail on a stale/missing hash; forces manifest mode so a
  stray `public/hot` from `npm run dev` can't fool it.)

**Determinism (the guard must build like a real build).** The first PR-CI run of the guard surfaced that
`npm run build` was non-deterministic: `resources/css/app.css` `@source`s the framework's **Pagination Blade in
`vendor/`** and compiled Blade in **`storage/framework/views`**. The Node-only `assets` job (no `composer
install`) therefore built a smaller, *pagination-less* CSS that could never match the committed (vendor-present)
assets. Fix: the `assets` job now installs **composer/vendor** and **clears compiled views** before building, so
its `npm run build` reproduces the canonical committed assets exactly. (Confirmed by reproducing CI's vendor-less
hash byte-for-byte locally with `vendor/` moved aside.)

*(Sandbox note: the rebuild was done where `fonts.bunny.net` is unreachable. The font assets and JS are
deterministic and already committed — the offline toolchain reproduced the committed JS byte-for-byte — and a
local `fetch` shim served the committed font bytes so the REAL config built offline; the resulting `app.css`
hash (`app-BzzAoEro`) matches what CI's vendor-present build produces, fonts unchanged.)*

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

Kickoff: [installer-fix-kickoff.md](archive/installer-fix-kickoff.md).

### ⭐ RH-7 — Install-enforce middleware redirects Livewire's update endpoint → wizard can't complete — FIXED + VALIDATED on the live host
**This was the actual reason the wizard "does nothing."** Confirmed by direct live-host inspection
(`nevo.adorablespider.com`, cPanel) through the browser, including a manual replay of the Livewire request:

**Proof (live host, pre-install, enforcement ON):**
- `livewire.js` loads once; the `installer.wizard` component is reactive; clicking **Re-check** *does* fire a
  request. The runtime is healthy.
- Every action then fails with `SyntaxError: Unexpected token '<', "<!DOCTYPE "... is not valid JSON` thrown
  inside `livewire.js` at `JSON.parse`.
- Replaying the update request shows why: `POST /livewire-2cd208c8/update` → **302, `redirected:true`,
  `finalUrl: /install`**, body = the install page HTML (`<!DOCTYPE html> … <title>Install NovFora</title>`).
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

**Why every test missed it:** the wizard's only automated coverage runs with `NOVFORA_INSTALL_ENFORCE=false`
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
`APP_KEY`, no DB → `GET /` → **302 → /install**): `novfora-release.zip` **12,937,205 bytes**, sha256
`ebff39444dae1f6357e0f7b9c27fe5e0d4ad1ac58687d12da447ab15d27db956` (ships `bootstrap/cache/packages.php`; the
fixed middleware is inside). Kickoff: [installer-redirect-fix-kickoff.md](archive/installer-redirect-fix-kickoff.md).

**Validated on the live host:** the operator re-uploaded the bundle and the no-SSH wizard completed end-to-end
on `nevo.adorablespider.com` — system check → token → DB → site/admin → install → lock, then the demo
community and topics render. The post-install smoke is what surfaced RH-8 and RH-9 below.

> **Note on browser coverage.** RH-7 is a purely *server-side* middleware redirect — a real browser adds nothing
> over an in-process `POST` through the same stack, so the enforcement-ON feature tests above are the authoritative
> guard and they run in the normal Pest CI job (no Chrome/MySQL needed).
>
> **Follow-up LANDED — Dusk enforce-ON harness split.** The Dusk harness previously served ONE app with
> `NOVFORA_INSTALL_ENFORCE=false` (shared by `InstallerWizardTest` + `EditorJourneyTest`, since the editor needs
> `/forums` reachable), so the installer journey never exercised real pre-install enforcement in a browser. It is
> now split into **two sequential serve passes** (`docker/dusk/run.sh` + the CI Dusk job): **PASS 1 — INSTALLER**
> serves with `NOVFORA_INSTALL_ENFORCE=true` and no marker on a fresh DB, so `InstallerWizardTest`'s every
> `wire:click` flows through `RedirectIfNotInstalled` exactly like production (installing into a disposable MySQL);
> **PASS 2 — APP** serves enforcement-off for `EditorJourneyTest` (unchanged). Each pass gets its own `.env` + DB +
> installer sandbox — no shared state. The CI Dusk job gained a MySQL service + `pdo_mysql` as the wizard's install
> target. The enforcement-ON `InstallerEnforcedLivewireTest` feature tests remain the authoritative RH-7 guard; the
> split adds the real-browser belt. *(Not executed in this sandbox — no Chrome/MySQL; runs in `docker/dusk/` and
> the CI Dusk job. See PROJECT-STATE for what could not be run here.)*

### RH-8 — Root route served Laravel's scaffold welcome page — FIXED
**Observed (post-install, live host):** with the install complete and the demo community seeded, the site root
`/` rendered **Laravel's stock marketing page** (the "Documentation / Laracasts" scaffold), not the community.
The forum was reachable only at `/forums`.

**Root cause:** `routes/web.php` still carried the scaffold `Route::get('/', fn () => view('welcome'))`. It was
invisible until a real install because **pre-install every request is redirected to `/install`** (RH-7's enforce
middleware), so `/` never rendered its own view in any prior session — and **no test ever asserted the root
route** (the stock `ExampleTest` even asserted `/` → 200, locking the scaffold in).

**Fix:** `/` is now the community home. `/forums` stays the **single canonical** forum URL (it is referenced
across views and the XML sitemap), so the root **301-redirects** to `route('forums.index')` — one canonical URL,
no duplicate content. This is the lower-churn of the two options the kickoff offered: zero `route('forums.index')`
references change. The stock `resources/views/welcome.blade.php` is **deleted** (clean-room hygiene — it is
Laravel's page, not ours). Enforcement is unchanged: pre-install, `RedirectIfNotInstalled` (web group) still
intercepts `/` → the wizard; the 301 only applies once installed / when enforcement is off (so the cold-boot
contract `GET / → 302 → /install` is preserved).

**Coverage (the gap, closed) — `tests/Feature/Forum/RootRouteTest.php`:** `/` → **301 → `/forums`** post-install;
the welcome view no longer exists; `/forums` serves the real home; and `/` still **→ `/install`** pre-install
(enforcement ON). The stock `ExampleTest` is updated to the redirect contract.

### ⭐ RH-9 — Security hardening × object cache = poisoned fragment cache (the /forums 500) — FIXED
**Observed (post-install, live host):** `/forums` returned **HTTP 500** in a telltale pattern — it worked on a
cache **miss**, then 500'd for every request for the next ~60s (the TTL), then worked once, then 500'd again,
alternating. `laravel.log` (15 identical entries, authed AND anonymous):
> `Call to a member function isCategory() on string (View: resources/views/forum/index.blade.php) at`
> `storage/framework/views/<hash>.php:6`

**Root cause (exact chain — reproduced in a test):**
- `config/cache.php` sets `serializable_classes => false` — the P1.5 anti-object-injection hardening (**KEEP
  IT**). `CacheManager` passes it to the store; `DatabaseStore::unserialize()` (and file/redis) then calls
  `unserialize($value, ['allowed_classes' => false])` — **no class survives**; every object becomes a
  `__PHP_Incomplete_Class`.
- `ForumController@index` fragment-cached a **live Eloquent Collection**
  (`Cache::remember('forum.index.tree', 60, fn () => Forum::…->with('children')->get())`) — the one place in the
  app that cached model objects.
- On a cache **hit**, the Collection deserialized to `__PHP_Incomplete_Class`; Blade's `@forelse` iterated its
  raw properties, the first being the incomplete-class **name string** (`"Illuminate\…\Collection"`), so
  `$node->isCategory()` was called **on a string** → the exact 500.
- **Why every test missed it:** the suite runs `CACHE_STORE=array` with `serialize => false` — the array store
  round-trips objects **by reference**, so `allowed_classes` never applies. The bug needs a **serializing** store
  (database/file/redis) — i.e. any real deployment.

**Fix (keep the hardening; fix the data):**
1. `ForumController@index` now caches **primitives only** — a plain scalar array tree
   (`App\Forum\ForumNode::toArray()`: id / type / title / description / topic_count / post_count + nested
   children) — and rehydrates lightweight read-only `App\Forum\ForumNode` value objects **after** the cache
   boundary (`fromArray()`), so no object is ever serialized. A value object can't be cached either —
   `allowed_classes:false` blocks **all** classes, so rehydration must happen outside the cache. The view +
   `forum-row` partial render from the node; behaviour, the 60s TTL, and the ≤15-query index budget are unchanged.
2. **Repo-wide cache-write sweep:** every `Cache::put/remember/forever/increment` value in `app/` (+ the cron
   heartbeat) was audited. The **only** object-write was `ForumController@index` (now fixed). All others already
   store scalars/arrays: the queue heartbeat stores an **epoch int** (`now()->timestamp` — so the live
   `queue.ok:null` was the **cron not yet running**, NOT a deserialization failure), the sitemap a **string**,
   the resolved permission a **bool**, the ACL version an **int**, the CAPTCHA nonce a **bool**.
3. A **defensive note** added in `config/cache.php` above `serializable_classes`: cached values must be
   scalars/arrays; objects do not survive a serializing store under this hardening — and do **not** allow-list
   classes to "fix" a caching bug (that re-opens the object-injection surface the hardening closed).

**Coverage (the missing class — cache HIT through a SERIALIZING store):**
- `tests/Feature/Forum/ForumIndexCacheTest.php` — drives the **database** cache store and requests `/forums`
  twice: both **200**, the second (hit) shows the seeded category/forum, the stored value is asserted a plain
  array, and per-viewer `forum.view` filtering still hides a NEVER'd forum on the hit. *Verified to FAIL on the
  unfixed controller* with the exact live error (`isCategory() on string`, 500 on the hit) and to pass after.
- `tests/Feature/Operability/QueueHeartbeatCacheTest.php` — the heartbeat round-trips through a serializing
  store; `queue.ok` is a real boolean (fresh→true, stale→false), never null-from-deserialization.
- `tests/Feature/Smoke/PublicRoutesSmokeTest.php` — installed + demo seed, every public route returns no 5xx
  **twice** through a serializing store (the cheap net that catches this whole miss-ok / hit-500 class early).

### ⭐ RH-10 — "it migrates automatically" was never implemented — FIXED (ADR-0021)
**Found (doc-vs-reality audit, pre-deploy):** `docs/getting-started.md` §5 promised "deploy the new version
(it migrates automatically)", but **nothing implemented it.** The only `migrate` call was in `InstallRunner`
(install time); the scheduler had no upgrade task. A no-SSH operator who extracts a new release over a live
install runs **new code against the old schema, with no way to migrate** — concretely, the themed release
adds `users.color_mode`/`density`; until they're applied, **saving Appearance settings errors** (a write to a
column that isn't there yet), and any future release that drops/renames/retypes a column the request path
reads would break pages site-wide — with the operator stranded on a half-deployed site and no no-SSH recourse.
(Additive reads degrade gracefully — `$user->color_mode` is `null` pre-migration, strict mode off — so it
isn't "every page 500s"; the gap is the *missing migrate path itself*, which any non-trivial schema change
turns into a real outage.) A beta gate that blocked the themed live deploy.

**Fix (this pass) — a cron-driven, backup-first, maintenance-safe automatic migration (`App\Upgrade`):**
- **Detection** — `SchemaState`: an O(cache-read) request-path check (cached flag + a release-**fingerprint**
  = sha256 of the deployed migration filenames; a glob, no DB) that gates the moment new code lands, refreshed
  by the scheduler tick's real `migrator` check. `GET /health` gains a non-secret `schema` block
  (`pending`/`upgrading`/`stuck`/`auto`/`last`) — how the owner & Cowork watch a live upgrade without SSH.
- **The run** — `UpgradeRunner` (every minute, `withoutOverlapping` + a cache lock): enter maintenance →
  **backup first** (failure aborts) → `migrate --force` in-process → refresh caches → exit maintenance →
  audit-log. A coarse-cron kill resumes idempotently on the next tick.
- **The window** — `PreventRequestsDuringUpgrade` serves a branded **503** (Retry-After, self-refreshing)
  instead of a SQL error, except `/health` + assets.
- **Failure** — best-effort roll back **this run's** batch only; stay in maintenance; **hold**
  (`schema.stuck`, no retry loop) after ≤`max_auto_attempts`; the maintenance page names the pre-upgrade
  backup; the hold self-clears when the operator re-uploads the previous release.
- **Controls** — `NOVFORA_AUTO_UPGRADE=true` by default; `false` = manual (*Admin → System → Upgrade* /
  `php artisan novfora:upgrade`). Documented asymmetry: auto mode protects signed-in pages; manual mode keeps
  the site reachable so the admin can apply.

**Coverage** — `tests/Feature/Operability/{AutoUpgradeTest,SchemaStateTest,UpgradeMaintenanceTest,AdminUpgradePanelTest}.php`
+ extended `SchedulerTest`/`HealthCheckTest`: detection on/off · lock prevents concurrent · backup→migrate
ordering & backup-abort · failure→rollback + maintenance retained + health `stuck` · `AUTO_UPGRADE=false` →
no auto-run + admin/CLI apply works · `/health` schema block · requests during the window get 503-maintenance,
not SQL errors. An **adversarial multi-lens review** of the diff then found + fixed two HIGH hard-kill
failure modes (a 24h scheduler overlap-mutex that could strand the auto-upgrade for a day; a `upgrading` flag
left by a process killed mid-run that wedged the site at 503) plus 4 nits. Suite **Pest 381 passed / 1
skipped (1295 assertions)**; Pint + Larastan + `composer audit` clean; `assets-fresh` reproduces the
committed bundle. Bundle rebuilt + cold-boot-verified (`RELEASE_VERIFY=PASS`, `GET / → 302 /install`):
`novfora-release.zip` **12,813,544 bytes**, sha256
`451def6a40c3aed76ff3c3dfc235bc221a0c0ae39d2db5d101f3368ea2c30b5d`. **This is the mechanism that makes the
themed live deploy safe** — deploying that bundle onto the live site is RH-10's first real-world validation
(the appearance migration applies itself via cron).

### ⭐ RH-11 — the Backups panel could create but not RESTORE (no-SSH recovery was impossible) — FIXED (ADR-0022)
**Found (doc-vs-reality audit, same class as RH-10):** `novfora:restore` existed only as a **CLI** command;
*Admin → System → Backups* could create/download/delete but **not restore**. A no-SSH operator therefore had
**no recovery path at all** — and the RH-10 recovery guidance pointed them at "restore the pre-upgrade backup
via the admin Backups panel," which did not exist. Documented-but-unimplemented; a beta gate (invites waited
on it).

**Fix (this pass) — a no-SSH restore behind the RH-10 machinery (`App\Backup`, ADR-0022):**
- **Why cron-driven, not synchronous or a DB queue job.** A restore OVERWRITES the live DB — and on the
  baseline tier the cache, session, AND queue all live in that DB (`.env.example`:
  CACHE_STORE/SESSION_DRIVER/QUEUE_CONNECTION=database). So a synchronous web restore would wipe the very
  session/cache backing the request mid-flight (and is bounded by PHP's request-time limit on large
  archives), and a DB-queue job would erase its own `jobs` row mid-restore. The restore state is therefore a
  **file** (`RestoreState` → `storage/novfora-restore.json`, outside the `storage/app` restore target, so it
  survives the DB swap and keeps the maintenance gate up across it), and the run is drained by the **single
  cron line** (`RestoreRunner::runPending`) in CLI context with no web timeout. `RestoreRunner::runNow` is the
  synchronous path the CLI uses; both reach one `execute()` — CLI and panel share ONE pipeline.
- **Choreography (mirrors RH-10):** validate the archive (manifest + streamed dump SHA-256 — **refuse before
  touching anything**) → take a **pre-restore safety snapshot** of the current state (so the restore is itself
  reversible) → restore DB + storage → flush caches + `SchemaState::refresh()` → exit → audit-log. The target
  is staged to a private temp copy first, so the safety snapshot / a prune / a delete can't change the bytes
  restored.
- **The RH-11 → RH-10 hand-off (got right + tested):** a restored DB may carry an **older schema**. After the
  restore, the schema state is re-derived, so the RH-10 maintenance gate keeps the site held and the
  auto-upgrade tick **migrates it forward** on the next tick (auto mode). `UpgradeRunner` stands down while a
  restore is in progress, so the two never race the database.
- **The window:** `PreventRequestsDuringUpgrade` now serves the branded maintenance 503 for a restore too
  (restore variant), deciding from the **file** state first (survives the DB/cache wipe) then the RH-10 cache
  state. `GET /health` gains a non-secret `restore` block (`requested`/`running`/`stuck`/`last`); a stuck
  restore reads as `degraded`.
- **The panel action:** each backup row gains **Restore** — `admin.access` + **staff-2FA** (self-guarded in
  the SFC, like the RH-10 Upgrade panel) + a **typed confirmation** (the backup's exact name) + an explicit
  "this overwrites the database and files" warning showing the backup's date/size. It only **records** the
  request (after re-validating), then sends the operator to the self-refreshing maintenance page.
- **Failure policy (single-attempt, fail-safe):** a restore is destructive, so it is **never auto-retried**.
  A validation failure (nothing touched) refuses and lifts the gate. A failure during the restore step — or a
  process killed mid-restore, detected on the next cron tick because the file lock is free yet the state still
  says `running` — **HOLDS** the site in maintenance (`restore.stuck`) rather than serving a possibly
  half-restored DB. **No-SSH recovery from a held restore:** the maintenance page tells the operator to delete
  `storage/novfora-restore.json` via the host file manager (the same deliberate filesystem action that resets
  the install marker), then restore a known-good backup / the named pre-restore safety snapshot from the panel;
  with a shell, `php artisan novfora:restore` does it directly (and clears the hold on success).
- **Cron-side stand-down:** while a restore is requested/running/stuck, the scheduler skips every DB-touching
  job (queue drain, backups, trust/anti-spam) so nothing reads or writes the database being replaced; the
  auto-upgrade tick already self-guards. The HTTP maintenance gate + this scheduler `->skip()` are the two
  sides of the same window.

**Coverage** — `tests/Feature/Operability/{RestoreRunnerTest,RestoreMaintenanceTest,PanelRestoreTest}.php` +
extended `HealthCheckTest`/`SchedulerTest`: panel authz (non-admin / non-2FA refused) · typed-confirm
required · happy-path round-trip in a sandbox (create → mutate → restore → mutation gone) · the
restore→pending-migrations hand-off (restore an older-schema backup → RH-10 detects + upgrades cleanly) ·
validation refusal on a corrupt archive (gate not left up, data intact) · maintenance entered/exited · audit
entry · `/health` during the window · the auto-upgrade standing down during a restore.

**FLAGGED follow-up (not built — scope fence):** **restore from an UPLOADED archive** (the operator's
off-host copy, when no on-host backup survives) — needs a guarded upload of an untrusted zip (size/zip-bomb/
path-traversal limits, the same integrity gate) into the restore pipeline. Tracked for a later pass; until
then, an off-host copy is restored by placing it under `storage/backups` (FTP / cPanel File Manager) so it
lists in the panel, or via the CLI.

**Verification status (this env):** code + tests written on `claude/rh11-panel-restore`. This delivery
environment has **no PHP/Composer/Docker/MySQL**, so the Pest suite, Pint, Larastan, `composer audit`, and the
release rebuild (`scripts/build-release.sh` + `verify-release.sh` → size + sha256) are the **Docker `php:8.3`
/ human step** — same as every prior RH-* pass (see PROJECT-STATE). The change is server-rendered + PHP only
(no asset rebuild; `assets-fresh` should reproduce the committed bundle unchanged).

## Next

1. **RH-7 / RH-8 / RH-9 — all FIXED + the bundle rebuilt (this pass).** The live-host install completed
   end-to-end (RH-7 validated: wizard → demo community → topics render); the post-install smoke then found RH-8
   (root = scaffold welcome) and RH-9 (poisoned fragment cache → `/forums` 500), both fixed with the missing
   serializing-store / root-route coverage. Suite **Pest 331 passed / 1 skipped (1108 assertions)**; Pint +
   Larastan + `composer audit` clean. Bundle rebuilt + cold-boot-verified (`RELEASE_VERIFY=PASS`,
   `GET / → 302 → /install`): `novfora-release.zip` **12,924,197 bytes**, sha256
   `f48862b0aed5cef7323d4d9a8d43ad977c9ff9b90271de716e7c2fe9834c0e86` (ships `bootstrap/cache/packages.php`; the
   fixes are inside; `/novfora-release.zip` stays gitignored). **Human step:** redeploy the rebuilt bundle (or the
   changed files) — `/` becomes the community, `/forums` is stable under cache hits, and `/health`'s queue check
   reports truthfully once cron is running.
2. **RH-5 — stale committed assets + CI freshness guard — FIXED (this pass).** Rebuilt + committed
   `public/build` (fresh `app.css` + manifest), added the **`assets-fresh`** CI guard, documented the rule in
   `CONTRIBUTING.md`, and added `ViteManifestTest`. **Dusk enforce-ON harness split — LANDED (this pass):** two
   serve passes (installer enforce-ON, then editor) in `docker/dusk/run.sh` + the CI Dusk job (see the RH-7
   entry). Suite **Pest 333 passed / 1 skipped (1128 assertions)**; Pint + Larastan + `composer audit` clean.
   Bundle rebuilt + cold-boot-verified (`RELEASE_VERIFY=PASS`, `GET / → 302 → /install`): `novfora-release.zip`
   **12,918,488 bytes**, sha256 `3844efebfd8a5dbc378e7f33595ac924a45b596feb171a5427107f9c5bb22d56`
   (`/novfora-release.zip` stays gitignored). *(Dusk not executed in this sandbox — no Chrome/MySQL; landed via
   PR #2, where the assets-fresh + Dusk jobs are the live check.)*
3. **RH-4 — subdirectory install (design-first):** spike → ADR → implement + add a subdirectory case to the
   install test matrix. Still the owner-flagged priority. RH-1/RH-2 landed; RH-6 was a misdiagnosis (superseded
   by RH-7). **The next phase is the default theme / UI polish pass** (`theme-design-brief.md`); RH-4 follows.
