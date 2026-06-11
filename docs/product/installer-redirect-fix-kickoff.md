<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# Installer blocker (RH-7) — install-enforce middleware eats Livewire's update endpoint — Claude Code kickoff

> **This supersedes the RH-6 diagnosis.** RH-6 assumed Livewire never `start()`s on the host; that is wrong.
> Live-host inspection proved the Livewire runtime is healthy — the wizard's failure is a **server-side
> middleware redirect**. Fix is small and surgical. Do **not** chase Livewire boot timing / `DOMContentLoaded`
> / Alpine again.

---

```
Fix the real-host installer blocker (RH-7): the pre-install enforce middleware redirects Livewire's own
update endpoint to /install, so the wizard can never complete in a browser. Reproduce → fix → regression
test → rebuild the bundle. No product features. No front-end/Livewire-boot changes.

STEP 0: read PROJECT-STATE.md and docs/product/real-host-findings.md (RH-7 — the corrected root cause).
Confirm the suite is green and the tree is clean.

THE BUG (proven on the live cPanel host by direct browser inspection + a manual request replay):
  • The Livewire runtime boots fine: livewire.js loads exactly once, the installer.wizard component is
    reactive, and wire:click fires a POST to the update endpoint. NOT a front-end bug.
  • Every wizard action then fails with: SyntaxError: Unexpected token '<', "<!DOCTYPE "... is not valid JSON
    (Livewire JSON.parse on an HTML body), and the page hard-reloads back to a blank step 1 — which is the
    "pasted the setup token, nothing happens" symptom.
  • Replaying the request shows the cause: POST /livewire-2cd208c8/update → 302 redirect → /install, body =
    the install page HTML. Livewire expects JSON, gets HTML, reloads.
  • Root cause: app/Http/Middleware/RedirectIfNotInstalled.php allowlists 'livewire/*', but Livewire 4 serves
    its update/asset routes under a HASHED prefix — livewire-<hash>/update (observed livewire-2cd208c8/update).
    $request->is('livewire/*') does NOT match 'livewire-2cd208c8/update' (the -<hash> breaks the 'livewire/'
    prefix), so the wizard's POST falls through the allowlist and is redirected to /install.
  • Corroboration: the livewire.js ASSET route is outside the enforced web group, so it is NOT redirected
    (that's why the runtime boots). A GET to the update path returns 405 (method-not-allowed thrown during
    routing, before the web-group middleware) — only the real POST reaches the middleware and gets redirected.

WHY CI MISSED IT (fix the gap too): the wizard's existing coverage runs with NOVFORA_INSTALL_ENFORCE=false
(Installer::shouldEnforce() opts the test suite out), so RedirectIfNotInstalled is a no-op in every test and
the redirect never happens. The bug only exists with enforcement ON — the real pre-install state — which
nothing exercised.

PART 1 — REPRODUCE (prove it before fixing):
  Write a feature test that fails on main: force enforcement ON (config novfora.install.enforce=true, marker
  absent), GET /install, parse the Livewire update URI from the returned HTML (the data-update-uri attribute /
  the livewire script), POST a minimal Livewire update payload to it, and assert the response is a redirect to
  /install (the bug). This reproduces the host failure in-process, no browser needed. Confirm it currently
  fails-as-buggy (redirects).

PART 2 — FIX (app/Http/Middleware/RedirectIfNotInstalled.php), make the allowlist match Livewire's ACTUAL
update endpoint, hash-agnostic and future-proof — do NOT hardcode the observed hash:
  • Preferred: derive the Livewire update path at runtime (e.g. the path component of Livewire's configured
    update URI — Livewire\Livewire::getUpdateUri() or the equivalent in the installed Livewire 4.x) and allow
    that exact path. This stays correct if the hash changes between builds/versions.
  • Also broaden the static pattern so both shapes match regardless: keep 'livewire/*' AND add 'livewire-*/*'
    (Str::is treats * as matching across '/', so 'livewire-*/*' matches 'livewire-2cd208c8/update' and
    'livewire-2cd208c8/livewire.js'). Avoid the over-broad bare 'livewire*' if you can match precisely.
  • Keep the rest of the allowlist (install, install/*, build/*, vendor/*, up, health, favicon.ico) intact.
  • Sanity-check there is no equivalent prefix assumption elsewhere (search the repo for the 'livewire/'
    literal and for any place that assumes the un-hashed prefix).

PART 3 — REGRESSION TEST (this is the missing coverage; it must run with enforcement ON):
  • Turn PART 1's reproduce test into the regression: with enforcement ON and the marker absent, a POST to the
    real Livewire update URI must NOT redirect to /install (assert not-redirect / not 302-to-install). It must
    fail on main and pass after the fix.
  • Add/confirm at least one test that drives a real wizard action through the update endpoint with enforcement
    ON and asserts the component advances (e.g. toStep2 with a valid token moves to step 2), so a future
    prefix/allowlist regression is caught end-to-end, not just at the redirect layer.
  • If the existing InstallerWizardTest (Dusk) runs with enforcement OFF, make it (or a sibling) run with
    enforcement ON so the browser path is covered under real pre-install conditions.

PART 4 — REBUILD + VERIFY + DELIVER:
  • Rebuild the deployable bundle via scripts/build-release.sh (ships bootstrap/cache/packages.php) and re-run
    scripts/verify-release.sh (cold HTTP boot → 302 /install). Deliver the new novfora-release.zip to D:\Forum
    with size + sha256. Keep /novfora-release.zip gitignored.

DEFINITION OF DONE: the new enforcement-ON test fails on main and passes after the fix; the full Pest suite +
M0–M5 + the installer browser test pass; Pint/Larastan/composer-audit clean; bundle rebuilt + cold-boot
verified. Commit (conventional, DCO). Update docs/product/real-host-findings.md (RH-7 → FIXED) and
PROJECT-STATE.md. Report: the reproduce evidence (redirect on main), the exact allowlist change, the test
additions, and the new artifact's size + sha256.

SCOPE FENCE: middleware allowlist + enforcement-ON tests + bundle rebuild only. Do not touch the installer
Blade/JS (the RH-6 boot guard is harmless and out of scope), and do not add product features.
```

---

## After this

The owner re-uploads the rebuilt `novfora-release.zip` and runs the wizard on the live host — with RH-7 fixed,
Continue should advance through every step to a completed install (the real-host validation's primary goal).
Remaining open items afterward: **RH-4** (first-class subdirectory install, design-first) and **RH-5** (rebuild
stale committed assets + CI freshness guard). The harmless RH-6 boot guard can be left as-is or reverted in a
later tidy-up.
