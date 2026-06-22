<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# Deployable Bundle — Claude Code kickoff prompt

> Goal: produce a single **ready-to-upload `novfora-release.zip`** the owner can drop onto a shared host (no
> toolchain on their side) for the real-host validation. Small, focused build task — not features.
> The owner has **no local PHP/Composer**, so the build runs in the Docker env, and the artifact is delivered
> into `D:\Forum\` for download. Pairs with [REAL-HOST-VALIDATION.md](../../REAL-HOST-VALIDATION.md) §2.

---

```
Build the deployable novfora-release.zip for shared-host upload. No new features.

STEP 0: read PROJECT-STATE.md; confirm the suite is green (Pest 310) and the tree is clean.

BUILD (in the Docker php:8.3 env + host Node):
  1. composer install --no-dev --optimize-autoloader   (production deps only)
  2. npm ci && npm run build                            (refresh the committed public/build assets)
  3. Ensure a CLEAN, pre-install state before zipping — the bundle must boot to /install on the host:
     • php artisan optimize:clear  (drop any cached config/routes/views)
     • make sure NO install artifacts are present: storage/installed and storage/install-token.txt must NOT
       be in the bundle (they are created per-host at runtime — bundling either one would brick the installer).

  4. Zip the app to novfora-release.zip. INCLUDE: vendor/, public/build/, app, bootstrap (dirs only),
     config, database/{migrations,seeders}, resources, routes, artisan, composer.json/lock, LICENSE, README,
     .env.example. EXCLUDE (critical):
       .git/  node_modules/  tests/  docker/  .github/  docs/
       .env  .env.*  auth.json
       storage/logs/*  storage/framework/cache/*  storage/framework/sessions/*  storage/framework/views/*
       storage/installed  storage/install-token.txt  storage/backups/*  storage/*.key
       bootstrap/cache/*           (keep the dir; exclude cached *.php)
       database/*.sqlite
       novfora-release.zip          (don't zip the output into itself)
     PRESERVE the empty runtime dirs (their .gitkeep): storage/framework/{cache,sessions,views},
     storage/logs, bootstrap/cache — Laravel 500s if they're missing on the host.

VERIFY (prove the bundle is a clean, installable artifact):
  • Extract to a temp dir; assert vendor/autoload.php and public/build/ exist; assert NO .env, NO
    storage/installed, NO storage/install-token.txt, NO bootstrap/cache/*.php; assert the runtime dirs exist.
  • Fresh-boot check: from the extracted copy with a minimal env (APP_KEY empty, no DB configured), hit the app
    (or `php artisan route:list`) and confirm it boots to the PRE-INSTALL state (redirects to /install / does
    not think it's installed and does not fatal). This is the acceptance test for the bundle.
  • Report the final zip path, size, and a sha256.

DELIVER:
  • Write the artifact to D:\Forum\novfora-release.zip (repo root, so the owner finds it in their folder).
  • Add `/novfora-release.zip` to .gitignore (it's a build artifact — do NOT commit the binary).
  • Tighten REAL-HOST-VALIDATION.md §2's zip command to match the exclude list above (it currently omits
    storage/installed, storage/install-token.txt, bootstrap/cache, and database/*.sqlite — add them).
  • Commit ONLY the doc + .gitignore changes (conventional, DCO). Do NOT commit the zip.

REPORT BACK: the artifact path + size + sha256, the fresh-boot verification result, and confirm the bundle is
built for PHP 8.3+ (the host must run 8.3+ per the checklist).
```

---

## After this

The owner downloads `novfora-release.zip` from their `D:\Forum` folder and uploads it to each shared host per
[REAL-HOST-VALIDATION.md](../../REAL-HOST-VALIDATION.md) §3 onward (extract → point the doc root at `public/` →
create an empty DB → run the browser installer with the setup token). Cowork triages whatever the hosts surface.
