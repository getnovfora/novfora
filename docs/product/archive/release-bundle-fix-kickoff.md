<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# Deployable Bundle — REBUILD with real-host fixes — Claude Code kickoff prompt

> First real-host install surfaced a blocker: the prior `novfora-release.zip` fails on a fresh shared host with
> **`Target class [view] does not exist`** because it shipped **without** Laravel's package manifest
> (`bootstrap/cache/packages.php`) — a side effect of building with `--no-scripts`. The previous bundle
> verification masked it by running `php artisan route:list` (which regenerates the manifest) *before* its boot
> check. This rebuild fixes the bundle, fixes the verification so the class can't recur, and folds in two more
> real-host findings (CloudLinux permission strictness; the missing `public_html`-subfolder install recipe).

---

```
Rebuild the deployable novfora-release.zip so it boots on a fresh no-SSH shared host with ZERO commands, and
fold in the real-host findings below. Investigate → REPRODUCE → fix → verify. No new product features.

STEP 0: read PROJECT-STATE.md; confirm the suite is green (Pest 310) and the tree is clean.

REAL-HOST FINDINGS TO ADDRESS:
  • RH-1 (BLOCKER): the bundle shipped without bootstrap/cache/packages.php (built with --no-scripts). On a
    COLD first boot on the host (no prior artisan run), provider/view registration fails →
    "Target class [view] does not exist". The old verify ran `php artisan route:list` first, which regenerates
    the manifest and masked the bug.
  • RH-2: CloudLinux/suEXEC hosts 500 on world-writable (0777) files; cPanel extraction produced 0777.
  • RH-3: no runbook recipe for installing into a public_html SUBFOLDER (the index.php path edit, APP_URL with
    the subpath, RewriteBase, and public-storage handling for the split layout).

PART 1 — REPRODUCE (prove the diagnosis, and prove the old verify was insufficient):
  Build the bundle the OLD way (composer install --no-dev --optimize-autoloader --no-scripts), extract to a
  temp dir, and do a TRULY COLD boot — bootstrap/cache empty, NO artisan command run first — by serving it with
  PHP's built-in server (php -S 127.0.0.1:PORT -t <extract>/public) and curl GET /. Confirm it reproduces the
  "Target class [view] does not exist" failure (or a 500). Capture it. This is the host's exact scenario.

PART 2 — FIX THE BUILD:
  • After `composer install --no-dev --optimize-autoloader`, run `php artisan package:discover --ansi` so
    bootstrap/cache/packages.php is generated and SHIPS in the bundle.
  • Do NOT cache env-specific state — no config:cache / route:cache / event:cache / optimize. Those bake the
    build env's config into the bundle and break portability across hosts. Ship ONLY packages.php from
    bootstrap/cache (services.php is written per-host at runtime and must stay out).
  • Update the zip include/exclude so bootstrap/cache/packages.php is INCLUDED while config.php/routes.php/
    events.php/services.php are still excluded.
  • Investigate WHY Laravel didn't self-heal the missing manifest on the host (it normally rebuilds it when
    bootstrap/cache is writable). If a small, clean, conservative app-side guard makes a missing-manifest cold
    boot self-recover (without baking env state), add it WITH a test; otherwise document the requirement. Don't
    over-engineer — shipping the manifest is the primary fix.

PART 3 — FIX THE VERIFICATION (so RH-1 can never slip through again):
  The bundle's acceptance test must be a COLD HTTP boot with NO prior artisan invocation: extract → serve via
  php -S with bootstrap/cache starting empty and a minimal env (APP_KEY empty, no DB) → curl GET / → assert
  302 → /install. Run this AGAINST THE FIXED BUNDLE and confirm it now passes (and that PART 1's old bundle
  fails it). The previous filesystem-presence assertions stay; just replace/augment the boot check so it never
  runs artisan before booting.

PART 4 — DOCTOR + RUNBOOK (the other findings):
  • novfora:doctor: add a check that flags world/group-writable (0777-style) app files/dirs — the CloudLinux 500
    cause — with the chmod hint. Add a test.
  • docs/REAL-HOST-VALIDATION.md:
    – Add a prominent PERMISSIONS step for CloudLinux/suEXEC hosts: after extract, set 755 dirs / 644 files
      (`find ~/app -type d -exec chmod 755 {} \;`, `... -type f -exec chmod 644 {} \;`, `chmod 755 artisan`),
      keeping storage/ and bootstrap/cache/ writable by the owner.
    – Add an "Install into a public_html subfolder" recipe: copy <app>/public/ contents into
      public_html/<SUBDIR>/; edit that index.php's two require paths to __DIR__.'/../../<app>/vendor/autoload.php'
      and __DIR__.'/../../<app>/bootstrap/app.php' (case-sensitive); set the installer Site URL / APP_URL to
      include /<SUBDIR>; add `RewriteBase /<SUBDIR>/` if routes 404; and publish storage into the web dir
      (symlink public_html/<SUBDIR>/storage → <app>/storage/app/public, or copy for no-symlink hosts).
    – Note: do NOT copy docs/*.md into the web folder.

DELIVER:
  • Write the corrected artifact to D:\Forum\novfora-release.zip; report path, size, sha256, and the PART 1 (old,
    fails) vs PART 3 (new, passes) cold-boot results side by side.
  • Keep /novfora-release.zip gitignored (don't commit the binary). Commit the code + doc + verify changes
    (conventional, DCO). Full M0–M5 suite STAYS green + any new tests pass; Pint/Larastan/audit clean.

REPORT BACK: the reproduce/fix/verify evidence (cold boot now → 302 /install with zero commands), the new
artifact's size+sha, and confirm packages.php ships in the zip.

FLAG (do NOT build now): a deeper installer enhancement — let the operator set the app/public path so the
storage publisher targets a subfolder web root automatically — for owner decision as a follow-up.
```

---

## After this

The owner re-downloads `D:\Forum\novfora-release.zip` and resumes the real-host install (the subfolder recipe +
permissions step are now in the runbook). Cowork triages whatever the live host surfaces next.
