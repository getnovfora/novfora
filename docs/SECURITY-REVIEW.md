<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# NovFora — Adversarial Security Review (Phase 1.5)

> **Date:** 2026-06-03 · **Scope:** the complete Phase-1 codebase, reviewed as untrusted (installer,
> permission engine, anti-spam, cross-cutting OWASP, supply chain). **Specs reviewed against:**
> [security-and-permissions.md](architecture/security-and-permissions.md) §1/§2/§4, PROJECT-BRIEF §6/§9,
> the M5 installer (`app/Install/`), ADR-0007/0015/0020.
>
> **Baseline before this pass:** Pest **272 passed / 879 assertions**, Pint clean, Larastan clean,
> `composer audit` clean (verified in Docker `php:8.3` + `mysql:8`).
>
> **Rules of engagement:** clear-cut issues were FIXED with a regression test each; anything that is a
> design change, a real vulnerability with tradeoffs, or would alter approved behaviour is **FLAGGED for
> owner decision** below, not silently changed.

---

## 1. Summary

NovFora's security fundamentals are **strong**: the permission engine matches its spec (NEVER is absolute,
deny-by-default, group-merge is most-permissive, bans evaluated first); the XSS canonical→sanitize boundary
is a genuine allowlist with an escaping renderer; CSRF is on for every state-changing route; SQLi surface is
effectively nil (one constant `orderByRaw`, everything else Eloquent); the install lock is a file marker
written last with a CLI-only reset. The findings below are mostly **defence-in-depth gaps and shared-host
hardening**, plus one real IDOR and one real anti-spam bypass.

| # | Severity | Area | Finding | Disposition |
|---|---|---|---|---|
| H‑1 | **High** | IDOR | Attachment download ignored `forum.view` for attached files → anyone could enumerate ids and pull files from private forums | **FIXED** + test |
| H‑2 | **High** | Anti-spam | TL0 link/image suppression was bypassed via the **signature** (a public surface) | **FIXED** + test |
| H‑3 | **High** | Real-host | Backups depend on `mysqldump`/`proc_open`; both commonly disabled on shared hosts → cron backups silently fail | **FIXED** (pure-PHP fallback) + test |
| M‑1 | **Med** | Mass-assignment | `User` had `trust_level`/`status`/`signature_html`/`avatar_path`/`cover_path` in `#[Fillable]` (latent priv-esc / stored-XSS one `update($request)` away) | **FIXED** + test |
| M‑2 | **Med** | Installer | If `storage/` is unwritable, the install could finish (admin created, DB migrated) but fail to write the lock → an **unlocked, re-runnable** installer (second-admin takeover) | **FIXED** + test |
| M‑3 | **Med** | Headers | No CSP or security headers at all (brief §4 mandates "strict CSP") | **FIXED** (baseline CSP+headers) · strict nonce-CSP shipped behind a toggle, see F‑M3 |
| M‑4 | **Med** | Secrets | `.env` written world-readable (`0644`) — readable by co-tenants on shared hosting | **FIXED** (`0600`) + test |
| L‑1 | **Low** | Installer | `APP_DEBUG=true` during the pre-install window → stack traces to an anonymous visitor | **FIXED** (forced off pre-install) |
| L‑2 | **Low** | Cookies | HTTPS-only session cookie not enforced (brief §4 "HTTPS-only cookies") | **FIXED** (installer sets it on https) + test |
| F‑A | **High*** | Installer | **Unauthenticated install window**: whoever reaches `/install` first owns the site (no install token) | ✅ **FIXED** (setup token) + test |
| F‑B | **Med** | Anti-spam | Registration has no rate-limit; honeypot timing is skippable; Q&A answer is static/replayable | ✅ **FIXED** (throttle + mandatory timing + nonce) + tests |
| F‑C | **Med** | Anti-spam | StopForumSpam degrades **block→allow** on a cold cache; the "cron-cached blocklist" is never warmed | ✅ **FIXED** (degrade→pending + warm cron) + tests |
| F‑D | **Low‑Med** | Anti-spam | Trust promotion is gameable (self-posts count; the spec's read-time signals are unimplemented) | ✅ **FIXED** (topics-read signal) + tests |
| F‑E | **Low** | Anti-spam | Suppression is baked into `body_html_cache` at write time; a later demotion does not re-restrict old posts | ✅ **FIXED** (re-render on trust change) + test |
| F‑F | **Low** | AuthZ | No actor-vs-target rank check (a moderator can ban/warn an admin) | ✅ **FIXED** (rank guard) + tests |
| F‑G | **Low** | Mass-assignment | Six authz models (`AclEntry`, `Role*`, `Group`, `Permission`) are fully unguarded | ✅ **FIXED** (explicit fillable) + test |
| F‑H | **Low** | Tenancy | `tenant_id` is mass-assignable (left untouched per the scope fence) | ✅ **FIXED** (removed from fillable) + test |
| F‑I | **Low** | Audit | Authentication events (login/failed/logout/password-reset/2FA) are not audit-logged | ✅ **FIXED** (auth-event subscriber) + tests |
| F‑M3 | **Med** | Headers | Strict nonce-based CSP (follow-up to M‑3) | ✅ **shipped behind a toggle** (default = baseline) + test; default-on tracked below |
| F‑J | **Info** | Misc | Mention-any-user notification spam; attachment stored-extension from client; `/health` reveals install state/tier unauth | **NOTES** (unchanged) |

\* F‑A's severity is high in principle but is the inherent tradeoff of a no-SSH installer; the setup token now
closes it (whoever finds `/install` also needs filesystem access to read the token), backed by the operational
guidance in [REAL-HOST-VALIDATION.md](REAL-HOST-VALIDATION.md).

**Initial pass: 9 issues fixed with tests; 10 flagged.** **Fix pass (owner-approved): all 10 flagged items
addressed** — F‑A..F‑I + tenant_id fixed with regression tests, F‑M3 shipped behind a toggle (§5). The
remaining `F‑J` items stay as informational notes. Per-item detail in §5.

---

## 2. Fixed (clear-cut, each with a regression test)

### H‑1 · IDOR — attachment download bypassed `forum.view` *(High)*
`GET /attachments/{attachment}` is a public route. `AttachmentController@show` only authorised *orphan*
(unattached) files; an attachment with a `post_id` was streamed **unconditionally**. Because the route key
is a sequential auto-increment id, an anonymous attacker could walk `/attachments/1,2,3,…` and pull every
uploaded file — including those in private/staff-only forums. Every other read path (`ForumController`,
`TopicController`) gates on `forum.view`; attachments didn't.

**Fix** ([AttachmentController.php](../app/Http/Controllers/AttachmentController.php)): for an attached file,
resolve the post→topic and `abort_unless($viewer->canDo('forum.view', $topic->permissionScope()))`, with
`User::guest()` for anonymous and an uploader-owns-it fallback. Added `X-Content-Type-Options: nosniff` on
the stream. **Test:** `tests/Feature/Security/AttachmentAuthorizationTest.php` (public forum still served;
private-forum file refused to guest + non-member; uploader keeps access; orphan unchanged).
*Follow-up (not blocking):* finer per-attachment gating for pending/soft-deleted posts remains future work.

### H‑2 · Anti-spam — TL0 link/image suppression bypassed via signatures *(High)*
The headline TL0 spam-vector lockdown (`post.links`/`post.images` = NEVER) is enforced by passing a
suppression list to the sanitizer for post bodies — but `ProfileController@update` rendered the **signature**
with no suppression, and the signature is shown raw (`{!! $user->signature_html !!}`) on the public
`/users/{user}` page. A TL0 spammer could put live links/images in their signature. (Not XSS — the allowlist
sanitizer still runs — a *trust-gate* bypass.)

**Fix** ([ProfileController.php](../app/Http/Controllers/ProfileController.php)): compute the same
`post.links`/`post.images` restriction via the permission engine (at global scope, where the TL gates are
seeded) and pass it into the signature render. **Test:**
`tests/Feature/Security/SignatureSuppressionTest.php` (TL0 stripped, TL1 kept).

### H‑3 · Real-host — backups required `mysqldump`/`proc_open` *(High; baseline-readiness)*
`BackupService`/`RestoreService` shell out to `mysqldump`/`mysql` (and `pg_dump`/`psql`) via Symfony
`Process` (`proc_open`). Cheap shared hosts frequently **disable `proc_open`/`exec`** or ship without the
client binaries, so the cron-driven backup (exit criterion 6) would silently fail exactly where it matters.

**Fix** ([BackupService.php](../app/Backup/BackupService.php),
[RestoreService.php](../app/Backup/RestoreService.php)): a **pure-PHP MySQL/MariaDB dump+restore** over the
live PDO connection (`SHOW TABLES`/`SHOW CREATE TABLE`/batched `INSERT`s; restore via `PDO::exec`). Selected
automatically when `proc_open` is unavailable (`config novfora.backup.db_method = auto|php|shell`), and falls
back if the binary is missing. The `.sql` is interoperable with the `mysql` client. PostgreSQL stays on
`pg_dump` (enhanced-tier, where tools exist). **Test:** `tests/Feature/Operability/PhpBackupTest.php`
(MySQL-gated round-trip forcing `db_method=php`) + a new CI job step (`ci.yml`) exercising it on MySQL.
*Caveat:* the PHP path loads the dump in memory — fine for the small/medium DBs of a cheap-host forum; very
large boards should use the enhanced tier.

### M‑1 · Mass-assignment hardening on `User` *(Medium)*
`#[Fillable]` included `trust_level`, `status` (both authorization-relevant — `BanChecker` reads `status`),
`signature_html` (server-rendered HTML), and `avatar_path`/`cover_path`. No current call site exploited this,
but a single future `$user->update($request->validated())` would become self-promotion, self-unban, or stored
XSS — exactly the bug class M5 already caught once.

**Fix** ([User.php](../app/Models/User.php)): removed those five from the mass-assignable set (kept the
documented `tenant_id`, see F‑H). The two writers that relied on them now set them explicitly —
`CreateNewUser` (server-decided `status`) and `InstallRunner::createAdmin` (`forceFill` of `status`/
`trust_level`). **Test:** `tests/Feature/Security/MassAssignmentTest.php` + extended
`InstallerTest` (admin still `trust_level=4`/`status=active`).

### M‑2 · Installer — partial-install-then-unlocked window *(Medium)*
`InstallRunner` wrote the lock marker **last**, but never checked up front that it *could*. If `storage/` was
unwritable, the run could migrate the DB and create the admin, then throw on the marker write — leaving a
fully-built but **unlocked** site whose unauthenticated installer could be re-run to mint a second admin.

**Fix** ([InstallRunner.php](../app/Install/InstallRunner.php)): `assertMarkerWritable()` runs before any
destructive step (probe-writes the marker directory), so the install either completes-and-locks or changes
nothing. **Test:** `InstallerTest` ("refuses to run — writing nothing — when the install lock directory is
not writable").

### M‑3 · No security headers / CSP *(Medium)*
The app emitted **no** CSP, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, or
`Permissions-Policy`, although the brief §4 lists "strict CSP" as a hard rule.

**Fix** ([SecurityHeaders.php](../app/Http/Middleware/SecurityHeaders.php), wired in `bootstrap/app.php`,
configurable in `config/novfora.php`): a baseline **non-breaking** CSP plus the standard headers on every web
response. The CSP keeps `script-src`/`style-src` permissive (`'unsafe-inline'`/`'unsafe-eval'`) because
Livewire + Alpine + the inline-styled views + JSON-LD currently require them, **but** locks down
`object-src 'none'`, `base-uri 'self'`, `frame-ancestors 'self'`, `form-action 'self'`, and constrains
`default-/img-/connect-/media-src`. **Test:** `tests/Feature/Security/SecurityHeadersTest.php`. *A strict
nonce-based CSP is flagged below (F‑M3).*

### M‑4 · `.env` world-readable on shared hosts *(Medium)*
`EnvWriter` `chmod`-ed the secrets file (`APP_KEY`, DB password) to `0644` (world-readable). On shared hosting
a co-tenant can read it. **Fix** ([EnvWriter.php](../app/Install/EnvWriter.php)): `0600` (owner-only; the same
account user writes and reads it under FPM/suexec). **Test:** `InstallerTest` (perms assertion, skipped on
Windows).

### L‑1 · Pre-install debug exposure *(Low)*
A fresh `.env` (copied from `.env.example`) ships `APP_DEBUG=true`, so the unauthenticated pre-install surface
would render stack traces. **Fix** ([AppServiceProvider.php](../app/Providers/AppServiceProvider.php)): the
pre-install boot hook now forces `app.debug=false` until the installer writes the production `.env`.

### L‑2 · HTTPS-only session cookie *(Low)*
`SESSION_SECURE_COOKIE` was unset. **Fix** ([InstallRunner.php](../app/Install/InstallRunner.php)): the
installer writes `SESSION_SECURE_COOKIE=true` when the site URL is `https://`; documented in `.env.example`.
**Test:** `InstallerTest` (set for https, not for http).

---

## 3. Flagged for owner decision → **ALL RESOLVED in the fix pass**

> **Update (owner-approved fix pass).** The owner reviewed these and chose to fix all of them toward
> public-1.0 readiness. Every item below was implemented with a regression test (F‑M3 shipped behind a
> toggle); the original finding text is kept for context, and each entry's resolution is recorded in **§6
> (Fix pass — what was implemented)**. The recommendations below were the plan; §6 is what shipped.

### F‑A · Unauthenticated install window *(High in principle; inherent)*
A freshly-uploaded, not-yet-installed site lets **anyone** who reaches `/install` run the wizard — point it
at their own DB, become the admin, or hijack the owner's intended DB. This is intrinsic to the no-SSH model
(WordPress has the same exposure) and there is currently no mitigation. **Options:** (a) document "install
immediately; don't leave an uninstalled site exposed" (done in the runbook) and accept the risk; (b) add an
install **token** the wizard requires — but with no SSH the operator can't read a generated file, so this
needs a UX (e.g. a token mailed to the host account, or a value the operator sets in `.env` before upload).
**Recommendation:** ship the operational mitigation now; consider (b) before public 1.0.
*Related:* during that same window the **"Test connection" step is an SSRF primitive** — an attacker can
make the server open a DB connection to an arbitrary `host:port` and infer open ports from the timing/error.
It's bounded (a 5s timeout, a sanitised message) and only reachable while the installer is open, so closing
the window (install immediately) closes this too; post-install the Livewire component can't be invoked
(no signed snapshot is ever issued because `/install` 403s).

### F‑B · Registration anti-abuse gaps *(Medium)*
Three weaknesses compound to make scripted mass-registration cheap (results land as `pending`, never blocked):
(1) **no rate-limit on `/register`** — Fortify throttles only login/2FA; (2) the timing trap is **skipped when
`hp_ts` is absent** (`CreateNewUser::looksAutomated`), so a bot just omits it; (3) the Q&A answer is **static,
public, case-insensitive, and replayable** (`config/novfora.php` → `'blue'`). All "work as the flag-don't-block
philosophy intends", but registration is the highest-value abuse target and the one auth route with no
throttle. **Recommendation:** add a per-IP `register` throttle; make the timing token mandatory (reject when
absent); add a per-render nonce to the Q&A. (Changes registration behaviour → owner call.)

### F‑C · StopForumSpam fail-open on a cold cache *(Medium)*
On API timeout/outage the screener returns "no signal → allow", and the promised cron-cached blocklist is only
ever warmed as a side effect of prior *successful* live lookups — there is no warming job. So during any
outage (or on a fresh install) a high-confidence spammer that *should* be blocked is allowed.
**Recommendation:** add a scheduled SFS blocklist warm command, and/or fail **closed** for high-confidence
listings when degraded. (Tradeoff: availability vs strictness — owner call.)

### F‑D · Trust promotion is gameable *(Low‑Med)*
`TrustLevelManager::earnedLevel` counts the user's own posts (including in their own self-created topic) with
no distinct-topic / posts-by-others / read-time signal — the spec's "read ≥5 topics, ≥10 min" signals are
unimplemented. A patient, un-flagged account can self-inflate to TL1 (lifting the link/image NEVER gate) with
5 self-posts + 24h. (Flagged/infraction accounts are correctly frozen.) **Recommendation:** gate promotion on
approved posts and engagement with *other* authors' content before beta.

### F‑E · Suppression is fixed at write time *(Low)*
`body_html_cache` bakes the suppression decision when the post is written; nothing re-renders on a later trust
change, so a **demoted** author's older posts keep their links/images. The canonical source is lossless, so a
re-render pass is possible. **Recommendation:** decide whether demotion should retroactively re-restrict
(needs a re-render job).

### F‑F · No actor-vs-target rank check *(Low)*
Any holder of `bans.manage` (e.g. a moderator) can ban/warn/spam-clean **any** user including an
admin/owner — there is no "can't action someone more privileged than you" guard
(`BanController`, `WarningController`, `SpamCleaner`). **Recommendation:** add a rank/role guard before beta.

### F‑G · Unguarded authorization models *(Low; latent)*
24 of 25 models use `$guarded = []`, saved today only by disciplined call sites. The dangerous set:
`AclEntry`, `RolePermission`, `RoleAssignment`, `Group`, `Permission`, `Role` (a `Model::create($request)`
in a future roles/permissions ACP becomes direct privilege escalation), plus `Post`/`Topic` `approved_state`
& `user_id`, and `Post.body_html_cache`. No exploit exists today (no ACP writes them from request data).
**Recommendation:** add explicit `$fillable` allowlists to the six authz models **before** the roles/groups
admin UI is built. (Left unchanged now to avoid rippling into seeders/tests for a not-yet-reachable risk.)

### F‑H · `tenant_id` mass-assignable *(Low; scope-fenced)*
`tenant_id` remains in `User`'s fillable set. The Phase-1.5 scope fence says "keep the nullable `tenant_id`
seam untouched", so it was **left as-is**. It is harmless today (always null, no tenancy isolation to escape).
**Recommendation:** remove it from the mass-assignable set when multi-tenancy is implemented.

### F‑I · Audit-log completeness — auth events *(Low)*
The audit log covers moderation/anti-spam actions well, but **authentication events** (login success/failure,
logout, password reset, 2FA enable/disable) and account changes (email/password change, registration) are not
recorded, though brief §4 calls for "audit logging of security-relevant events". **Recommendation:** add
listeners for the Laravel/Fortify auth events that write to `Audit`. (Observability addition — left out of this
hardening pass to respect the no-new-features fence.)

### F‑J · Notes (informational)
- **Mention-any-user:** a user can `@mention` any user id (crafted canonical JSON) → notification to anyone.
  Low (TL0 posts are held; dedup per post). Consider capping mentions / requiring same-thread participation.
- **Attachment stored extension** is taken from the client filename (`AttachmentService`), though stored off
  web-root and streamed with `nosniff` — cosmetic, not exploitable. Derive it from the validated MIME for
  tidiness.
- **`/health`** exposes install state / tier / version unauthenticated — standard for a health endpoint;
  acceptable.

### F‑M3 · Strict nonce-based CSP *(follow-up to M‑3)*
The shipped CSP is non-breaking but keeps `'unsafe-inline'`/`'unsafe-eval'` for scripts. A strict policy
(`script-src 'self' 'nonce-…'`) needs: Livewire nonce configuration, the Alpine CSP build, moving the views'
inline `style="…"` to classes, and a nonce on the JSON-LD block. **Recommendation:** schedule for the pre-1.0
hardening pass; the config (`novfora.security.csp.policy`) makes it a one-line swap once the app is nonce-ready.

---

## 4. Verified safe (reviewed, no change needed)

- **Permission engine** ([PermissionResolver.php](../app/Permissions/PermissionResolver.php)): NEVER
  short-circuits across all holders/scopes; group-merge is most-permissive over {ALLOW,NO} (NEVER already
  removed); user-overrides-group at a scope; deny-by-default; scope chain built from the materialised path
  (global always present); guests-as-group and banned-first all match security §1.2/§1.5. The resolved cache
  is keyed by **ACL version × group-set signature**, so membership changes and ACL edits both invalidate it.
- **Ban → cache staleness:** every path that sets `status='banned'` (BanController, WarningService,
  SpamCleaner) creates a `Ban` row whose model `saved` event bumps the ACL version, so a cached ALLOW cannot
  outlive a ban. (The `moderate`/`pending`↔`active` transitions touch no ACL/ban state, so no stale window.)
- **XSS boundary** ([ContentSanitizer.php](../app/Content/ContentSanitizer.php),
  [CanonicalRenderer.php](../app/Content/CanonicalRenderer.php)): allowlist sanitizer (drops script/style,
  forces `rel="nofollow noopener noreferrer"`, restricts link/media schemes) behind an escaping JSON→HTML
  renderer that validates every href/src; uniform across the TipTap node set, Markdown, mentions, and now
  signatures. Code blocks escape their content. Client HTML is never trusted.
- **CSRF:** no `validateCsrfTokens` exclusions, no `withoutMiddleware`, no api route file; every
  state-changing route is in the `web` group with `@csrf` forms / `X-CSRF-TOKEN` for the editor.
- **SQLi:** one constant `orderByRaw('last_posted_at IS NULL')` (no user input); everything else is Eloquent.
- **IDOR elsewhere:** notifications are relationship-scoped (and UUID-keyed); warning acknowledgement checks
  ownership; moderation/bans/reports gate on `topic.moderate`/`bans.manage` at the target scope; post
  edit/delete use `PostPolicy` own-vs-any.
- **Install lock** ([Installer.php](../app/Install/Installer.php)): file marker, written last, no web reset
  route (CLI-only), 403 at route + action + runner.
- **EnvWriter injection:** values are quoted/escaped; multi-key writes are surgical; `DatabaseVerifier`
  returns only a SQLSTATE class, never the DSN/password.
- **Supply chain (ADR-0015):** `composer audit --no-dev` and `npm audit` both report **0** advisories; the JS
  deps are TipTap MIT core/extensions only (no Pro/commercial), Vite/Tailwind (MIT); lockfiles pin versions.

---

## 5. What changed in this pass (files)

**Fixes:** `app/Http/Controllers/AttachmentController.php`, `app/Http/Controllers/ProfileController.php`,
`app/Models/User.php`, `app/Actions/Fortify/CreateNewUser.php`, `app/Install/InstallRunner.php`,
`app/Install/EnvWriter.php`, `app/Providers/AppServiceProvider.php`, `app/Http/Middleware/SecurityHeaders.php`
(new), `app/Backup/BackupService.php`, `app/Backup/RestoreService.php`, `bootstrap/app.php`,
`config/novfora.php`, `.env.example`.

**Real-host tooling (Part 2):** `app/Install/HostDoctor.php` (new), `app/Console/Commands/DoctorCommand.php`
(new, `novfora:doctor`), `app/Install/PublicStorageLinker.php` (new),
`app/Console/Commands/StoragePublishCommand.php` (new, `novfora:storage:publish`), `routes/console.php`.

**Tests:** `tests/Feature/Security/{AttachmentAuthorization,MassAssignment,SecurityHeaders,SignatureSuppression}Test.php`,
`tests/Feature/Operability/{HostDoctor,PhpBackup}Test.php`, extended `tests/Feature/Install/InstallerTest.php`,
plus a MySQL pure-PHP backup step in `.github/workflows/ci.yml`.

---

## 6. Fix pass — what was implemented (the flagged items)

> Owner-approved follow-up: all flagged items fixed (hardening only, no new product features; the spec's
> "flag-don't-block on uncertainty" was preserved — nothing was turned into a false-positive hard-block).
> Each strict control has a test-env opt-out (mirroring the existing `NOVFORA_CAPTCHA`/`NOVFORA_SFS_API`
> pattern) so the M0–M5 + P1.5 suites stay frictionless; dedicated tests opt the control back in.

- **F‑A · Install setup token.** The pre-install boot writes a random `storage/install-token.txt` (0600);
  the wizard (step 1, gating the DB-test SSRF too) and `novfora:install` require it; it is consumed on a
  successful install. `config: novfora.install.require_token` (off in tests). *Files:* `Installer`,
  `InstallRunner`, `InstallInput`, the wizard, `InstallCommand`, `AppServiceProvider`. *Test:* `InstallerTest`
  (refuses without the token / installs + consumes with it / wizard step-1 blocked). *Runbook updated.*
- **F‑B · Registration anti-abuse.** A per-IP `/register` throttle (429), a **mandatory** honeypot timing
  token (the "omit the token" skip is closed), and a **single-use Q&A nonce** (consume-on-success → a
  captured answer can't be replayed). *Files:* `CreateNewUser`, `QaCaptchaProvider`, `register.blade.php`,
  config. *Test:* `RegistrationHardeningTest`.
- **F‑C · StopForumSpam fail-safe + warm cron.** A degraded check (API down, cold cache) now **flags →
  pending** instead of allowing (still never a hard-block), and `novfora:antispam:warm` (daily) downloads a
  toxic-domains list into `blocklist_cache` so it's never cold. *Files:* `RegistrationGuard`,
  `WarmBlocklistCommand`, `routes/console.php`. *Tests:* `RegistrationGuardTest` (degrade→flag, toxic-domain,
  warm populates the cache).
- **F‑D · Trust promotion signals.** TL0→TL1 now requires the §2.3 engagement signals (posts **and** tenure
  **and** topics-read via M4's `topic_reads`), so a self-poster who reads nothing can't lift the link/image
  NEVER gate. *Files:* `TrustLevelManager`, `GroupSeeder`. *Tests:* `TrustPromotionTest` (self-poster stays
  TL0; legitimate activity promotes).
- **F‑E · Re-render on trust change.** A trust change dispatches a queued `RegenerateUserPostHtml` job that
  re-renders the user's posts at their new level — re-suppressing links/images on demotion (revealing on
  promotion). The permission memo is flushed on the group swap so the re-render resolves correctly. *Files:*
  `TrustLevelManager`, `PostService::rerender`, the job. *Test:* `TrustPromotionTest` (demotion re-suppresses).
- **F‑F · Actor-vs-target rank check.** A staff member can't ban/warn/spam-clean a target of equal-or-higher
  rank (mods can't action admins or — by default — each other; admins outrank everyone; `allow_equal`
  configurable). *Files:* `ActorRank`, `User::isAdmin/rankPriority`, `BanController`, `WarningController`,
  config. *Test:* `RankGuardTest`.
- **F‑G · ACL-model mass-assignment.** `AclEntry`, `Group`, `Role`, `RolePermission`, `RoleAssignment`,
  `Permission` carry explicit `$fillable` allowlists (no longer fully unguarded); the seeders/RoleExpander
  still write every column they need (verified by the seed-dependent suite). *Test:* `MassAssignmentTest`.
- **F‑H · `tenant_id` guarded.** Removed from `User`'s mass-assignable set. *Test:* `MassAssignmentTest`.
- **F‑I · Auth-event audit logging.** A subscriber records login / failed login / logout / lockout /
  password-reset / 2FA-enable·confirm·disable to the append-only `audit_log` (actor, event, ip, ua). *Files:*
  `AuditAuthEvents`, `AppServiceProvider`. *Test:* `AuthAuditTest`.
- **F‑M3 · Strict nonce-based CSP — shipped behind a toggle (`NOVFORA_CSP_STRICT`, default OFF).** When on,
  the middleware emits a per-request nonce (which `@vite` and Livewire pick up automatically via
  `Vite::cspNonce()`, and NovFora's two inline `<script>` blocks carry), so **`script-src` drops
  `'unsafe-inline'`** — inline-script injection is blocked. *Test:* `SecurityHeadersTest` (strict mode).
  **Why a toggle, not default-on:** two things still need `unsafe-*` and so block a fully-strict default — (1)
  **Alpine v3** evaluates expressions with `new Function`, needing `'unsafe-eval'` until the Alpine *CSP
  build* is adopted (which requires rewriting Alpine expressions into registered components); and (2) the
  core Blade views use inline **`style="…"` attributes**, which CSP nonces do **not** cover, so a strict
  `style-src` would need them all refactored to classes. Both are sizeable, browser-verified refactors;
  doing them half-way would break the editor/Livewire/Alpine (explicitly out of bounds for this pass). The
  toggle + nonce plumbing make the eventual switch a one-line config change once that refactor lands.
  **Dusk-verified both ways:** the editor compose-and-post journey passes under the shipped baseline CSP AND
  under the strict toggle — so the strict policy is functional today (it already blocks inline-script
  injection); it's held opt-in only until it can drop `unsafe-eval`/`unsafe-inline` entirely.

**Validation:** full M0–M5 + P1.5 suite green (Pest), Dusk editor journey green under the shipped (baseline)
CSP, Pint + Larastan + `composer audit` + `npm audit` clean. New strict controls are off by default in the
test env so M1's `RegistrationTest` and the installer suite stay green.
