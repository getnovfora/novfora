<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# Phase 3 — REST API + outbound webhooks (B3)

> A versioned, token-authenticated, engine-authorized REST API and HMAC-signed outbound webhooks delivered on
> the baseline (cron) tier. **Status: Accepted — owner-authorized overnight build; flagged for review (ADR-0033).**

## 1. REST API (`/api/v1`)

```
Authorization: Bearer nvf_<token>
```

- **Tokens** — `api_tokens`. The plaintext (`nvf_…`) is shown ONCE at creation and stored only as a sha256
  hash; resolution is a hash lookup that rejects an expired token or an inactive owner. Users manage their own
  tokens at *Settings → API tokens*.
- **Auth = act as the user** — `AuthenticateApiToken` sets the resolved user as the request user, so every
  endpoint authorizes through the **existing permission engine** (`forum.view`, `post.create`, PostService).
  The API can never do more than the user could in the web UI. A bad/expired/inactive token → JSON `401`.
- **Endpoints**

  | Method | Path | Authorization |
  |---|---|---|
  | GET | `/api/v1/me` | the token's user |
  | GET | `/api/v1/forums` | filtered by `forum.view` |
  | GET | `/api/v1/forums/{forum}/topics` | `forum.view` (paginated) |
  | GET | `/api/v1/topics/{topic}` | `forum.view` (paginated posts) |
  | POST | `/api/v1/topics/{topic}/posts` | `post.create` (via PostService) |

- **Rate limit** — `throttle:api` = 60/min, keyed by user or IP. Responses are explicitly shaped (no internal
  columns) and collections carry pagination `meta`.
- **Versioning** — `/v1` in the path; new majors are additive under the same contract.

## 2. Outbound webhooks

```
WebhookEventSubscriber  ── bridges core domain events → WebhookDispatcher (insert pending deliveries only)
WebhookDeliveryRunner   ── cron egress: sign + POST + retry/backoff
webhooks:deliver        ── scheduled every minute (overlap-guarded, skipped during a restore)
```

- **Events** (a closed set): `post.created`, `topic.created`, `user.followed`, `reputation.awarded`,
  `message.sent`. Payloads carry **IDs + minimal fields only** — never message bodies or PII.
- **Baseline-safe delivery** — the dispatcher only inserts `webhook_deliveries` rows on the action's path (no
  HTTP, and it swallows errors so it can never break the triggering action). The **cron runner** does the
  actual POST with a short timeout, marking 2xx delivered and otherwise scheduling an exponential-backoff retry,
  failing after `max_attempts`. No persistent worker is required.
- **Signing** — reuses the inbound verifier's scheme: `HMAC-SHA256("{timestamp}.{body}", secret)` sent as
  `X-NovFora-Signature` + `X-NovFora-Timestamp`, so a receiver verifies identically and can reject replays. The
  per-endpoint secret is **encrypted at rest**.
- **SSRF guard (apex, DNS-rebinding-safe)** — `App\Webhooks\WebhookUrlGuard`, in two layers. **(1) Create/update
  time** (`assertConfigUrl`): a cheap http(s) + literal-IP + obvious-internal-hostname check (no DNS — a public
  hostname's A records aren't knowable or stable when the endpoint is saved). **(2) Delivery time** (the
  AUTHORITATIVE boundary, in `WebhookDeliveryRunner`): resolve every A/AAAA record and refuse if ANY resolves to
  a private / loopback / link-local / reserved / CGNAT / cloud-metadata (`169.254.169.254`) / IPv6-ULA /
  IPv4-mapped / 6to4 / NAT64 address; **pin** the connection to a validated IP (`CURLOPT_RESOLVE`) so it can't
  be rebound between resolve and connect; and **re-validate every redirect hop** (a 30x to an internal URL is
  the classic bypass). Because (2) runs at delivery, a hostname that looked public when saved but is later
  re-pointed at an internal address (DNS rebinding) is caught. The address deny-list
  (`App\Support\Ssrf\IpClassifier`) is shared with the oEmbed fetcher — one source of truth for both egress
  surfaces. `novfora.webhooks.allow_private` opens it for local dev only.
- **ACP** — *Admin → Webhooks* (admins-only: `admin.access` + 2FA) creates/toggles/removes endpoints; every
  write is audited.

## 3. Tests

`tests/Feature/Api/` (token 401 cases, engine-denied reads/writes, pagination, own-token revoke) and
`tests/Feature/Webhooks/` (SSRF refusal, subscribe filter, a verifiable HMAC delivery, retry/backoff, ACP authz).
