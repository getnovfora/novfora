<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# RH-4 — First-class subdirectory install: design spike + ADR-0070 (accepted)

> **Status: ACCEPTED & IMPLEMENTED — see ADR-0070 + ADR-0071 in `DECISIONS.md` (2026-06-16).** Drafted
> 2026-06-14; built on branch `claude/rh4-subdir-install`. This file is now the historical design reference.
> **ADR-number correction:** drafted as "ADR-0038", but 0038 was consumed (Sandboxed template editing) before
> this ran — the highest existing ADR is now 0069, so this decision was **renumbered ADR-0070** (and the
> canonical-home sub-decision is **ADR-0071**). The design below is unchanged. Supersedes the "open by design"
> placeholder for RH-4 in `real-host-findings.md` and `status-review-2026-06-06.md`.

## 1. Requirement

An end user must be able to install NovFora into a **subdirectory of a web root** (e.g.
`https://example.com/community/`), through the normal installer, not only at a domain/subdomain root.
This is a common shared-host scenario (one `public_html`, several apps). Acceptance: the wizard renders
**styled with working Livewire** at the subpath *before* any `.env` exists, and after install every route,
asset, Livewire endpoint, avatar/upload, and the canonical `/` → `/forums` redirect resolves under
`/community/…`.

Out of scope: multi-tenant path routing, running multiple NovFora instances under one app dir, and any
change to the **root**-subdomain layout (already supported and unchanged).

## 2. What already works, and what the earlier symptom actually was

A **subdomain/addon-domain root** install (docroot → `<app>/public`) is the supported, default layout and
is unchanged by this work. The `403 + 404 + redirect to /forums` seen on `hearth.adorablespider.com` was
**not** a subdirectory problem — it was a *root* install that (a) had a stale `storage/installed` marker
from a failed prior attempt (so `Installer::shouldEnforce()` flipped false → `/install` 403s and `/` 301s
to `/forums`), and/or (b) had the subdomain docroot pointed at the project folder rather than `public/`.
Both are operator/clean-state issues, not RH-4. RH-4 is specifically the **subpath** layout below.

## 3. Root causes (three, compounding) — confirmed in `real-host-findings.md` §RH-4

1. **Dual `public/` copies drift.** The documented §3b workaround copies `public/` into the web subdir and
   repoints `index.php`. That leaves two `public/build` trees: the app's (where the Vite **manifest** is
   read) and the served one (what the browser fetches). Any rebuild desyncs the hashes → **404 on CSS/JS**
   → unstyled page and dead Livewire.
2. **Base-path / URL generation under a subpath.** `route()`, Livewire 4's hashed update endpoint
   (`/community/livewire-<hash>/update` + `livewire.js`), and `@vite` asset URLs must all carry the
   `/community` prefix. This must hold **pre-install**, before any `.env`/`APP_URL` exists — so it cannot
   depend on a configured `APP_URL` alone.
3. **Storage publish target.** The installer/`novfora:storage:publish` publishes `public/storage` into the
   **app's** `public/`, not the served web subdir → avatars/uploads 404 in the split layout.

## 4. Goals / non-goals

- **G1 — Pre-install correctness.** The wizard at `/community/install` is styled and Livewire "Continue"
  works with **no** `.env` and **no** manual `APP_URL`.
- **G2 — No drift.** Exactly one canonical `build/` and one `storage/` are served; a rebuild can never
  desync the manifest from the served assets.
- **G3 — Graceful on hosts without symlinks.** Must have a working path on shared hosts that disallow
  `ln -s` (fallback to a published copy refreshed by cron).
- **G4 — Zero regression at the root layout** (subdomain/addon-domain → `public/`).
- **N1 — Not** a rewrite of asset bundling or the routing table.

## 5. Design options

### Option A — Symlinked `public` (preferred where symlinks are allowed)
`~/public_html/community` → symlink to `<app>/public`. One canonical `build/` + `storage/`; **no drift**
(solves cause 1 and 3). Still needs base-path awareness (cause 2). Blocked only on hosts that forbid
symlinks in the web root.

### Option B — Thin forwarding stub in the web subdir
`~/public_html/community/` holds only a generated `index.php` (boots the app from outside the web root)
and `.htaccess` (`RewriteBase /community/`). Assets and storage are served from the canonical app `public/`
via an **`Alias`/symlink** for `build/` and `storage/`, or a published copy when neither is available.
More moving parts than A, but works where a full-directory symlink is disallowed but a per-subpath alias or
per-folder symlink is.

### Option C — Copy layout, made robust (last resort / current §3b, hardened)
Keep the copy layout but make the installer the source of truth: **detect the subpath**, write
`APP_URL`/`ASSET_URL` with `/community`, and publish `build/` + `storage/` into the web dir on every
upgrade (hook the upgrade pipeline's cache/asset refresh so they can't drift). Most fragile; only if A/B
are both impossible on a target host.

### The base-path piece (needed by all three)
Add a tiny **request-time base-path detector** in the bootstrap path so URLs are correct *pre-`.env`*:
derive the prefix from `SCRIPT_NAME`/`REQUEST_URI` (honouring `RewriteBase`) and call
`URL::forceRootUrl()` + set the asset root **only when `APP_URL` is unset/localhost** (so a configured
`APP_URL` still wins post-install, and the root layout is untouched). Verify the three URL surfaces:
`route()`, Livewire `getUpdateUri()`/`livewire.js`, and `@vite`/`asset()`.

## 6. Recommendation

Adopt **base-path awareness (request-time detector) + Option A (symlinked `public`) as the documented
default, with Option B as the no-full-symlink fallback, and the installer detecting the subpath to wire
`APP_URL`/`ASSET_URL` and the storage target.** Option C is the explicit last-resort recipe. This gives
G1–G4: one canonical `build/`/`storage/` (no drift), correct `/community/…` URLs before `.env` exists, and
no change to the root layout.

## 7. Test matrix addition (must ship with the implementation)

Add a **subdirectory case** to the install matrix alongside the existing no-SSH cold-boot:

- Wizard at `/community/install` returns 200, CSS 200 (not 404), and a Livewire update POST to
  `/community/livewire-<hash>/update` succeeds (the "Continue does nothing" regression).
- Post-install: `/community/` 301 → `/community/forums`; a forum page renders styled; an uploaded avatar
  resolves under `/community/storage/...`.
- Root-layout regression guard: the same suite at a domain root still passes unchanged (G4).
- A rebuild + re-deploy does not 404 assets (drift guard for G2).

## 8. Open questions for sign-off

1. Symlink-first (A) vs stub-first (B) as the **documented default** — depends on how common symlink-denied
   shared hosts are across the target providers.
2. Should the installer **auto-detect** the subpath and pre-fill Site URL, or require the operator to enter
   the full `https://example.com/community` (current §3b)? Auto-detect is friendlier but adds pre-install
   request-introspection surface.
3. Does the base-path detector interact with the `RedirectIfNotInstalled` allowlist (`install`, `livewire-*`,
   `build/*`) — confirm `$request->is(...)` matches are path-relative and unaffected by the prefix.

---

## ADR-0070 (ACCEPTED 2026-06-16) — Subdirectory install support (RH-4)

> Originally drafted here as "ADR-0038". 0038 was already taken by the time this ran; the highest existing ADR
> is now 0069, so the accepted entry in `DECISIONS.md` is **ADR-0070** (with **ADR-0071** for the canonical-home
> change, RH-4.1b). The text below is the design of record, unchanged.

**Status:** Accepted — owner-authorized build (see `DECISIONS.md` → ADR-0070/0071).

**Context.** NovFora installs cleanly at a domain/subdomain root (docroot → `public/`) but not into a
**subdirectory** of a web root (`example.com/community`), a common shared-host need. The copy-`public/`
workaround (runbook §3b) breaks three ways: dual `public/build` trees drift → asset 404s and dead Livewire;
route/Livewire/`@vite` URLs don't carry the `/community` prefix pre-`.env`; and storage publishes into the
app's `public/`, not the served subdir. These are structural, so a patch is insufficient (per
`real-host-findings.md` §RH-4).

**Decision.** Make the app **request-time base-path aware** (derive the subpath from `SCRIPT_NAME`/
`RewriteBase` and force the URL/asset root **only when `APP_URL` is unset/localhost**, so the root layout
and any explicit `APP_URL` are unaffected), and serve a **single canonical `build/` and `storage/`** via a
symlinked `public` (**Option A**, default) with a thin-stub + alias fallback (**Option B**) where full
symlinks are disallowed; the installer detects the subpath to wire `APP_URL`/`ASSET_URL` and the storage
target. The hardened copy layout (**Option C**) is the last-resort recipe only. A **subdirectory case is
added to the install test matrix**, including a root-layout regression guard.

**Consequences.** Subdirectory installs become first-class and the §3b manual recipe is demoted to a
fallback. New surface: a small bootstrap base-path detector (must be conservative — never override a real
`APP_URL`, never alter the root layout) and an asset/storage strategy that varies by host symlink support,
so the install matrix must cover both. Until implemented, the runbook continues to steer users to a
subdomain root.

---

## Backlog entry (add to ROADMAP.md / PROJECT-STATE.md *after* this code run)

> Not added to the tracked lists yet, to avoid colliding with the in-flight merge. Drop this line into the
> "Next" list once the current run is merged:

- **RH-4 — first-class subdirectory install** (owner-flagged): **DONE 2026-06-16** — **ADR-0070 + ADR-0071**
  accepted (`docs/product/rh4-subdirectory-install-spike.md`). Default = Option A (symlinked `public/`), Option B
  thin-stub fallback, Option C copy last-resort; request-time base-path detector + installer subpath wiring +
  the subdirectory install-matrix case all shipped on branch `claude/rh4-subdir-install`.
