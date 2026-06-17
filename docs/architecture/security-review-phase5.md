<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# NovFora — Phase 5 Adversarial Security Review (P5.1)

> **Date:** 2026-06-16 · **Scope:** the **entire** application surface, reviewed as untrusted — with emphasis
> on the Phase 3/4 additions the earlier passes never covered (clubs + PM scoping, OAuth/SAML/2FA, the
> module trust boundary + template sandbox, the REST API + tokens, webhooks HMAC + SSRF, broadcast/channel
> authz, uploads/installer/upgrade, the Stripe money path, CSP, and the untrusted-input parsers).
> **Supersedes nothing** — it extends [`../SECURITY-REVIEW.md`](../SECURITY-REVIEW.md) (Phase 1.5) and
> [`security-review-wave8.md`](security-review-wave8.md) (mega-build Wave 8.4). ADR-0072.
>
> **Method:** adversarial, **per-finding verify-then-refute**. 11 domain reviewers fanned out over the surface;
> every HIGH/MEDIUM candidate was then put to an independent 3-lens refuter panel (reachability · existing
> mitigation · severity), and only survivors were carried as confirmed. The synthesiser (this author) then
> re-read the cited code for every confirmed finding before fixing it.
>
> **Model routing note (recorded assumption).** CLAUDE.md routes all security/permission/untrusted-input work
> to **Fable @ max** as the apex. In this build environment the `claude-fable-5` model was **not available**
> (every Fable-targeted sub-agent erred out), so the apex rung was taken at the **next-highest available tier,
> Opus 4.8 (1M)** — for both the reviewers and the refuter panels. This is a conservative,
> security-preserving fallback (a more capable model would only find *more*); recorded here and in DECISIONS.md
> so it is not mistaken for a routing violation.
>
> **Baseline before this pass:** Pest **1525 passed / 1 skipped**, Pint clean, PHPStan (level 5) 0 errors.

---

## 1. Summary

The Phase 3/4 surface is **fundamentally sound** — the permission engine, the no-leak club fences, the bespoke
template sandbox, the webhook HMAC + SSRF guard, the channel authorizer, OAuth's no-silent-merge rule, and the
Stripe HMAC gate all held under adversarial probing. The confirmed findings are **parity gaps** (a protection
the web surface enforces that a newer surface — search facet, REST API, SSO — did not) plus two
untrusted-input hardening gaps (mention fan-out, importer path) and the Stripe payment-proof check. **No HIGH
was confirmed; no RCE, no auth-bypass-without-precondition, no cross-tenant data read.** All eight MEDIUMs and
the actionable LOW/INFO items were fixed with regression tests; six candidates were refuted.

| # | Sev | Area | Finding | Disposition |
|---|---|---|---|---|
| 1 | **Med** | Clubs / search facet | `/search` forum-facet dropdown leaked private-club **names + ids** to logged-in non-members (only the bare `forum.view` check, not `clubContentVisibleTo`) | **FIXED** + test |
| 2 | **Med** | Auth / staff 2FA | OAuth + SAML login skipped the TOTP challenge, defeating **mandatory staff 2FA** (the gate only checked 2FA was *configured*, not *verified this session*) | **FIXED** + test |
| 3 | **Med** | Auth / OAuth signup | OAuth just-in-time account creation bypassed `registration.enabled`, the anti-spam screener, and **email/IP bans** | **FIXED** + test |
| 4 | **Med** | REST API | `POST /api/v1/topics/{t}/posts` ignored the **locked-topic** moderation gate the web enforces | **FIXED** + test |
| 5 | **Med** | Installer / SSRF | The DB-test SSRF sink (`testDatabase()`) was reachable as a direct Livewire action, **bypassing the setup-token gate** that only guarded `toStep2()` | **FIXED** + test |
| 6 | **Med** | Money / Stripe | The webhook granted a tier on `checkout.session.completed` **without checking `payment_status`** (delayed/async methods complete `unpaid`) | **FIXED** + test |
| 7 | **Med** | Untrusted input | **Unbounded `@mention` fan-out** from a client-controlled canonical doc → mass-notification/email + synchronous request-thread DoS | **FIXED** + test |
| 8 | **Med** | Importers | Legacy-attachment reader did `file_get_contents($legacyPath)` with no traversal guard → **arbitrary local-file disclosure** from a hostile legacy-DB filename | **FIXED** + test |
| 9 | **Low** | Money / concurrency | Stripe webhook idempotency was a racy `exists()`-then-`create()` with **no DB UNIQUE** on `(provider, provider_ref)` (double-grant under concurrent re-delivery) | **FIXED** (unique index + violation catch) + test |
| 10 | **Low** | REST API / ops | `/api/v1` ran **without the install + upgrade/restore maintenance gates** (could hit a half-migrated schema) | **FIXED** + test |
| 11 | **Low** | Attachments | An attachment on a **soft-deleted (moderated) post** stayed downloadable by any `forum.view` holder | **FIXED** + test |
| 12 | **Info** | Module manifest | Reserved-namespace guard was case-sensitive (`app\Foo` slipped past `App\Foo`) | **FIXED** + test |
| 13 | **Info** | Auth / OAuth | Provider `display_name`/`nickname` stored verbatim (display-spoof / length) | **FIXED** (clamp + strip control/bidi) + test |
| R‑1 | Med | Clubs | Sole-owner account-deletion orphans a club + corrupts `member_count` | **REFUTED** — real, but a **data-integrity/governance** defect, not a security/privacy bug; already a documented fast-follow (ADR-0047). Logged below. |
| R‑2 | Med | REST API | API write path skips the trust-tiered `PostRateLimiter` (only `throttle:api` 60/min) | **REFUTED** — bounded by the generic limiter + the engine/anti-spam pipeline; not a privilege bypass. Logged. |
| R‑3 | Low | Permissions | `acl_entries` has no DB UNIQUE on the resolution key | **REFUTED** — resolver is provably duplicate-tolerant (NEVER short-circuit, max() over group values); no writer creates conflicting dupes. |
| R‑4 | Low | Auth / 2FA | Disabling 2FA / viewing recovery codes needs only a live session (no password re-confirm) | **REFUTED** — explicitly documented Phase-2 deferral (config/fortify.php, ADR-0019), not a regression. |
| R‑5 | Low | OAuth | OAuth callback throttle is IP-only (no per-identity ceiling) | **REFUTED** — the `throttle:30,1` protocol-level cap bounds the claimed velocity; no reachable abuse beyond it. |
| R‑6 | Low | Sandbox | `e()`-escaped value in a quoted URL attribute doesn't neutralise `javascript:` | **REFUTED** — gated by admin trust (the sandbox runs admin-authored templates); not a cross-user bypass. |

**Outcome: 13 fixed (8 MEDIUM + 3 LOW + 2 INFO), 6 refuted.** Every fix keeps the existing control intact and
adds a regression test; none weakens a control for convenience.

---

## 2. Fixed (each with a regression test)

### 1 · Clubs — search forum-facet leaked private-club names *(Medium)*
`SearchController::index` built the facet `<select>` from `Forum::where('type','forum')->filter(canDo('forum.view'))`.
The board's global `members forum.view = ALLOW` means a logged-in non-member resolves ALLOW on a private club
forum, so the club's name + forum id rendered in the dropdown — even though its content, directory listing, and
search *results* are all gated. **Fix:** add the authoritative `clubContentVisibleTo($viewer)` gate (M1.5) to
the facet filter, matching every other forum-list surface. **Test:** `ClubPrivacyLeakTest` (the dropdown omits
the private club's name for a non-member, shows it to a member).

### 2 · Auth — SSO bypassed mandatory staff 2FA *(Medium)*
`RequireTwoFactorForStaff` only checks whether an authenticator is *configured* (`two_factor_confirmed_at`),
not whether one was *verified this session*. The password path is fine (Fortify challenges before the session),
but `SocialAuthController::callback` and `SamlController::consume` called `Auth::login()` directly — so a staff
account with a linked provider reached the admin panels with no TOTP. **Fix:** a shared
`App\Auth\ChallengesStaffTwoFactor` trait; for staff with a confirmed authenticator it stashes `login.id` and
hands off to Fortify's existing `two-factor.login` challenge instead of granting a session, on **every** SSO
path. Non-staff (and staff without 2FA, whom the middleware routes to setup) log in normally. **Test:**
`SocialLoginTest` (staff+2FA → redirected to the challenge, still a guest; staff without 2FA → normal login).

### 3 · Auth — OAuth signup bypassed the registration gates *(Medium)*
`SocialLogin::createUser` minted an `active`, verified account with no checks, so a provider identity bypassed
the `registration.enabled` toggle, the anti-spam screener, and — critically — an **email/IP ban** (a banned
email could re-register via Google/GitHub/Discord). **Fix:** `resolveForLogin` now mirrors `CreateNewUser`
before provisioning: closed registration / a banned email / a high-confidence listing **refuses**; a borderline
signal routes the account to the moderation queue (`status = pending`). The provider-verified email still skips
email verification. **Test:** `SocialLoginTest` (registration-closed refused, banned-email refused, flagged →
pending).

### 4 · REST API — reply to a locked topic *(Medium)*
`V1Controller::createPost` authorised `post.create` + club participation but never checked the topic's
`locked` status the web reply path enforces, so a moderator's lock was silently ignored over the API. **Fix:** a
single `Topic::isReplyable()` predicate shared by the API, the web composer, and `TopicController` (so the gate
cannot drift). **Test:** `ApiV1Test` (a token reply to a locked topic → 403, no post written).

### 5 · Installer — DB-test SSRF bypassed the setup token *(Medium)*
The setup-token gate (phase-1.5 F-A) lived only in `toStep2()`, but `testDatabase()` is an independently
invokable Livewire action that opens a real PDO connection to a client-chosen `host:port`. An unauthenticated
pre-install visitor could POST the hashed Livewire update endpoint to call it without the token — an internal
port/reachability oracle. **Fix:** re-assert `verifyToken()` at the sink itself (a no-op when tokens aren't
required, so the wizard flow is unchanged). **Test:** `InstallerTest` (no token → `testDatabase` errors and
never runs the verifier; with the token it runs).

### 6 · Stripe — grant without payment proof *(Medium)*
The webhook granted on a verified `checkout.session.completed` regardless of `payment_status`. Stripe fires that
event with `payment_status = unpaid` for delayed/async methods (ACH/SEPA), and for subscriptions whose first
invoice has not settled. **Fix:** require `payment_status ∈ {paid, no_payment_required}` before granting;
otherwise acknowledge `200 {status: unpaid}` without a grant (the settled outcome belongs to a future
`async_payment_succeeded` handler — a documented ADR-0065 follow-up). The HMAC gate is unchanged; this only adds
the proof Stripe's fulfilment guidance mandates. **Test:** `StripeWebhookTest` (unpaid → no grant; paid →
grants).

### 7 · Untrusted input — unbounded `@mention` fan-out *(Medium)*
`PostService::dispatchPostNotifications` iterated **every** distinct mention id in the client-controlled
canonical doc, synchronously sending an in-app notification (+ queued email) to each — one crafted post could
notify the whole board and flood the request thread. **Fix:** cap the honoured recipients per post to
`config('novfora.antispam.mention_fanout_cap', 10)` (phpBB/Discourse-style); the per-recipient privacy gate is
untouched. **Test:** `NotificationTest` (cap=3, 8 mentions → 3 notified).

### 8 · Importers — legacy-attachment path traversal *(Medium)*
`ImportRunner::importAttachments` read `$row['path']` (a trusted base dir joined with an **untrusted** legacy-DB
filename: phpBB `physical_filename`, MyBB `attachname`, SMF `id_attach`+hash) with no guard, so a `../`-laden
filename could disclose arbitrary local files (e.g. the new host's `.env`) via the imported attachment. **Fix:**
reject any path containing a `..` segment or a stream-wrapper scheme at the single read site (covering every
current and future `ProvidesAttachments` driver). **Test:** `PhpbbImportTest` (a `../secret` physical_filename
is skipped — no attachment row, the secret bytes never reach the disk).

### 9 · Stripe — racy idempotency (no DB UNIQUE) *(Low)*
Dedup was `exists()`-then-`create()` with no constraint, so two concurrent re-deliveries of the same signed
event could both insert an `active` row. **Fix:** a reversible, idempotent migration adds
`UNIQUE(provider, provider_ref)` (NULL `provider_ref` — the manual path — is exempt, MySQL treats NULLs as
distinct), and the controller catches `UniqueConstraintViolationException` as the duplicate outcome. The DB is
now the authoritative at-most-once guard. **Test:** `StripeWebhookTest` idempotency case (unchanged, now
constraint-backed).

### 10 · REST API — not gated during install/upgrade *(Low)*
`/api/v1` carried only `throttle:api` + token auth, not the web group's `RedirectIfNotInstalled` +
`PreventRequestsDuringUpgrade`, so it could serve reads/writes against a half-migrated schema during an RH-10
upgrade or RH-11 restore. **Fix:** apply both gates ahead of token auth (a JSON 503 during the window). **Test:**
`ApiV1Test` (API returns 503 `{status: maintenance}` during an upgrade window, before any token lookup).

### 11 · Attachments — soft-deleted content still downloadable *(Low)*
`AttachmentController::show` loaded the post/topic `withTrashed()` and gated only on `forum.view`, never the
trashed state — so a moderated (recycle-binned) post's attachment stayed readable to ordinary viewers.
**Fix:** mirror `TopicController`'s trashed gate — a trashed post/topic's attachment is served only to the
uploader or a `topic.moderate` holder. **Test:** `AttachmentAuthorizationTest` (guest + plain member denied;
uploader + moderator allowed).

### 12 · Module manifest — case-sensitive reserved namespace *(Info)*
`ManifestValidator` compared the namespace root against the reserved set case-sensitively, so `app\Foo` (which
PHP resolves to `App\Foo`) slipped past. **Fix:** compare case-insensitively. **Test:** `ManifestValidatorTest`.

### 13 · OAuth — verbatim provider profile strings *(Info)*
`display_name`/`nickname` were stored straight from the (attacker-controlled) provider profile. **Fix:** a
`clampName()` helper trims, strips control + bidi-override characters, and bounds length (parity with the
username). **Test:** covered by the `SocialLoginTest` signup paths.

---

## 3. Refuted (verified-then-refuted — recorded, not fixed)

- **R‑1 sole-owner club orphan (Med→refuted).** Mechanics confirmed (`AccountDeletionService` does no club
  handling; `club_user` cascades), but this is a **data-integrity/governance** defect, not a
  security/permission/privacy vulnerability — and ADR-0047 already records the ownership-transfer-before-deletion
  guard as a documented fast-follow. **Carried to the fast-follow backlog, not a P5 security fix.**
- **R‑2 API skips the trust-tiered post rate limiter (Med→refuted).** True that `createPost` uses only
  `throttle:api` (60/min) and not `PostRateLimiter`, but the write still passes the full engine + anti-spam
  pipeline (`ContentModerator`, trust gates), so it is not a privilege/spam-gate bypass — only a looser cadence
  cap. A follow-up could route the API write through `PostRateLimiter` for parity; not a security defect.
- **R‑3 `acl_entries` no DB UNIQUE (Low→refuted).** The resolver is duplicate-tolerant by construction (first-NEVER
  short-circuit; `max()` over group values; user-override is value-equality), and no writer produces conflicting
  duplicate keys. Pure defence-in-depth, no live bug.
- **R‑4 2FA mutations need no password re-confirm (Low→refuted).** `confirmPassword => false` is an explicitly
  documented Phase-2 deferral (config/fortify.php, ADR-0019); intended posture, not a regression.
- **R‑5 OAuth callback IP-only throttle (Low→refuted).** The `throttle:30,1` protocol-level cap already bounds
  the account-creation velocity the finding claims is missing; no reachable abuse beyond it.
- **R‑6 sandbox quoted-URL-attribute scheme escape (Low→refuted).** Correct that `e()` doesn't neutralise a
  `javascript:`/`data:` scheme, but the sandbox executes **admin-authored** templates under full trust at its
  intended exposure; this is not a cross-user control bypass.

---

## 4. Files changed

**App:** `app/Http/Controllers/SearchController.php`, `app/Import/ImportRunner.php`,
`app/Modules/ManifestValidator.php`, `resources/views/components/installer/⚡wizard.blade.php`,
`app/Http/Controllers/Api/V1Controller.php`, `app/Models/Topic.php`,
`app/Http/Controllers/TopicController.php`, `resources/views/components/forum/⚡reply-composer.blade.php`,
`routes/api.php`, `app/Auth/ChallengesStaffTwoFactor.php` (new),
`app/Http/Controllers/Auth/SocialAuthController.php`, `app/Http/Controllers/Auth/SamlController.php`,
`app/Auth/Social/SocialLogin.php`, `app/Http/Controllers/StripeWebhookController.php`,
`database/migrations/2026_06_16_000001_add_unique_provider_ref_to_member_subscriptions.php` (new),
`config/novfora.php`, `app/Forum/PostService.php`, `app/Http/Controllers/AttachmentController.php`.

**Tests:** `ClubPrivacyLeakTest`, `PhpbbImportTest`, `ManifestValidatorTest`, `InstallerTest`, `ApiV1Test`,
`SocialLoginTest`, `StripeWebhookTest`, `NotificationTest`, `AttachmentAuthorizationTest`.
