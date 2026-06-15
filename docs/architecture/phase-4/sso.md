<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# Phase 4 · M2 — SSO (social login + SAML scaffold)

> Design record. ADRs: **0053** (OAuth login), **0054** (account linking + collision safety), **0055** (PKCE /
> state / CSRF / SSRF analysis), **0056** (SAML scaffold). The password login path is **unchanged** throughout.

## OAuth social login (ADR-0053)

Providers — **Google**, **GitHub** (core Laravel Socialite), **Discord** (socialiteproviders/discord, registered
via `Socialite::extend`). All **MIT**-licensed. Every provider is **OFF by default**; an admin enables it and
pastes a client id + **encrypted** client secret on **Admin → Settings → Social login**. `SocialProviders`
configures the Socialite driver **per request** from those encrypted settings — no `.env` or
`config/services.php` entries needed, and a disabled/unknown provider 404s before any redirect.

A `social_accounts` table links one local user to one identity per provider, with
`unique(provider, provider_user_id)` and `unique(user_id, provider)`.

### The auth-boundary rule (APEX)

`App\Auth\Social\SocialLogin`:

- A **known** `(provider, provider_user_id)` always resolves the SAME account — never a duplicate.
- A **new** identity creates a verified account (members + TL0, random unknown password).
- **EMAIL COLLISION → REFUSE.** If the provider's email already belongs to a local account, sign-in is refused
  with **no merge**; the user must sign in with their password (proving control) and then **link** the provider
  from settings. Control is proven by the password session, never asserted by a matching email.

## Account linking (ADR-0054)

**Settings → Linked accounts** lets a signed-in user link / unlink each enabled provider. Linking reuses the
same callback (disambiguated by an `oauth.link_intent` session flag), attaches the identity to the current
account, and refuses if the identity is already linked elsewhere. Unlink is always safe (email + password
remain). The full collision → password-login → link flow is covered by one end-to-end test.

## Hardening (ADR-0055)

- **State**: Socialite runs **stateful** — a `state` nonce in the session, validated on callback; a mismatch
  **fails closed**. The round-trip is GET-only (state-protected); the link initiator is a POST with CSRF.
- **PKCE** (RFC 7636, S256): enabled for **Google + Discord**; **GitHub** OAuth Apps don't support it and rely
  on `state`. Proven by real-driver tests asserting `code_challenge` presence/absence.
- **SSRF**: not applicable — Socialite's outbound calls target **library-fixed** provider endpoints, never an
  attacker-controlled URL; the only persisted attacker value (the avatar URL) is clamped to https + length and
  never fetched server-side.
- SSO does **not** bypass the staff-2FA gate.

> **⚠ Scaffolded — not validated against a live provider.** No real OAuth apps/credentials exist in the build
> environment, so the end-to-end round-trip with Google/GitHub/Discord is **unverified**; the flow is proven
> against **mocked** Socialite responses. Validate with real client credentials before relying on it.

## SAML (ADR-0056) — SCAFFOLD ONLY, NOT VALIDATED

NovFora ships the **seam** for SAML, not a working integration: the `SamlProvider` contract, a `SamlAssertion`
DTO, a `SamlManager` (detection), a detection-gated `SamlController` (login / ACS / metadata), the `auth.saml.*`
settings, and routes — but **NO concrete provider implementation** (a real one needs a SAML toolkit + a live IdP
to validate XML-signature handling). It is **inert by default**: every SAML route 404s unless `auth.saml.enabled`
is on AND an operator/module binds a configured `SamlProvider`. Account mapping reuses `social_accounts`
(`provider='saml'`); JIT provisioning is intentionally not implemented. **This does not work end to end.**

## How it works (for members)

On the login page, "Continue with Google/GitHub/Discord" appears when an admin has enabled it. New to the site?
It creates your account. Already have one with that email? Sign in with your password first, then connect the
provider under **Settings → Linked accounts**.
