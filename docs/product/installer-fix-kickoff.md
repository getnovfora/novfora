<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The Hearth Authors
-->
# Installer Wizard — front-end fix + full browser test — Claude Code kickoff prompt

> Real-host bug (RH-6): the install wizard renders and Livewire+Alpine initialize, but its `wire:click`
> actions fire **no request** — the install can't be completed in a browser. The installer wizard was **never
> browser-tested** (Dusk covered the editor, not `/install`), so this slipped through. This is the blocker for a
> real install. Fix the installer's front-end and add a full-wizard browser test, then rebuild the bundle.

---

```
Fix the installer wizard's broken front-end and cover it with a browser test, then rebuild the bundle. No new
features. Found on a real cPanel host (clean subdomain, all assets 200, no console errors).

THE BUG (evidence captured in-browser):
  • `Livewire.all()` returns the live `installer.wizard` component (snapshot: {step:1, setupToken:"",
    dbDriver:"mysql", dbHost:"127.0.0.1", ...}). `window.Alpine` is present (v3.15.12, __fromLivewire:true).
  • The Continue button is `<button type="button" class="btn" wire:click="toStep2">Continue</button>`; the token
    field is `<input wire:model="setupToken">`.
  • Clicking Continue fires NO network request and logs NO console error. wire:click is simply not handled.
  • CONFIRMED the runtime is only HALF-booted: `Livewire.first().$wire` exists but has NO working methods —
    `$wire.$set(...)`, `$wire.set(...)`, and even `$wire.toStep2()` all throw "is not a function". So Livewire
    registered the component SHELL (from the HTML snapshot) but never attached its interactive runtime (the
    $wire proxy, the wire: directive bindings, and click delegation). Root-cause WHY start()/binding doesn't
    complete on the standalone installer layout — that's the fix.
  • The Livewire script is present and correct: <script src=".../livewire-<hash>/livewire.js"
    data-update-uri=".../livewire-<hash>/update" data-navigate-once="true">.
  → Livewire core initializes (component registered) but its DOM event handling isn't active on the installer
    page. The MAIN app layout works (the editor's Livewire passes Dusk), so the bug is specific to the
    installer's STANDALONE pre-install layout (it can't use the main layout, which assumes DB/auth).

STEP 0: read PROJECT-STATE.md + docs/product/real-host-findings.md (RH-6). Confirm baseline green; reproduce
the wizard locally in a real browser (Dusk/Chrome) to see the dead click first-hand.

FIX:
1. Root-cause the installer's standalone layout vs the working main layout. Make Livewire fully initialize so
   wire:click / wire:model bind on the installer page — correct @livewireStyles/@livewireScripts (or the v3
   auto-inject), the Alpine bootstrapping (no missing or duplicate Alpine — note Alpine is currently coming
   from Livewire with __fromLivewire:true), and confirm the component's events are actually delegated. The
   wizard's Continue/Back/model bindings must work.
2. Add a Dusk test that drives the FULL installer wizard in a real browser (this is the missing coverage):
   GET /install fresh (pre-install) → step 1 renders → fill the setup token → Continue → Database step → fill
   a test MySQL connection → Continue → Site & admin → Continue → Install → assert the Done/installed state and
   that /install then 403s (locked). This both proves the fix and catches any downstream step bug in one pass.
3. Rebuild the deployable bundle via scripts/build-release.sh (ships bootstrap/cache/packages.php) and re-run
   scripts/verify-release.sh (cold HTTP boot). Deliver the new hearth-release.zip to D:\Forum with size + sha256.

DEFINITION OF DONE: the installer wizard is fully operable in a real browser (the new full-wizard Dusk test is
green); the existing Pest suite + M0–M5 + the new Dusk test all pass; Pint/Larastan/composer-audit clean; the
bundle is rebuilt and cold-boot-verified. Report: the root cause, the fix, the Dusk run, and the new artifact's
size + sha256. Update docs/product/real-host-findings.md (RH-6 → FIXED) and PROJECT-STATE.

SCOPE FENCE: installer front-end fix + browser test + rebuild only. No product features.
```

---

## After this

The owner re-uploads the rebuilt `hearth-release.zip` (or just the corrected installer views/assets) and runs
the wizard, which should now click straight through to a completed install — the real-host validation's primary
goal. Remaining open items afterward: RH-4 (subdirectory install, design-first) and RH-5 (stale assets + CI guard).
