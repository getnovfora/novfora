# Spike memo — U7 · Embed API / SSI / web components (`NOV-106`, ◆ APEX)

> GO/NO-GO memo per `docs/product/FABLE-U7-U17-KICKOFF.md` Phase 1. Written 2026-07-01 before any U7
> code. Decision at the bottom. Product surface per `docs/product/feature-list.md` (U7) and
> `docs/product/reevaluation-synthesis.md` §Tier 2 ("JSON endpoints + `<novfora-…>` widgets for
> external sites … APEX: untrusted-origin boundary; CORS; never leak private/club content; cache").

## 1. What ships (v1 contract)

Three consumption modes from one server-rendered core, all **read-only, guest-visible content only**:

1. **Iframe / SSI widgets** — `GET /embed/v1/w/{widget}?site={key}&…` returns a small, self-contained
   HTML document (own minimal CSS, no app JS, no session) that an external page frames or includes
   server-side. Widgets v1: `topics` (latest topics, optionally scoped to one forum) and `stats`
   (public board aggregates). Single-topic teaser deferred to v1.1.
2. **JSON data** — `GET /embed/v1/d/{widget}.json?site={key}&…` returns the same payload as data for
   the web components, CORS-scoped to the registered origin.
3. **Web components** — `GET /embed/v1/embed.js` serves a dependency-free, hand-authored vanilla-JS
   custom-elements bundle (`<novfora-topics>`, `<novfora-stats>`) that fetches the JSON endpoint and
   renders in Shadow DOM. No Alpine/Livewire/Vite runtime coupling; the file is a static, versioned
   artifact (semver'd public contract, ADR to record).

**Per-embed allowlist:** an admin registers each consuming site in the ACP → an `embed_sites` row
holding a display name, an **origin** (`scheme://host[:port]`, exact match), a random public **site
key** (the "scoped read-only token" of the kickoff — scope = "render guest-visible embed widgets for
this origin", nothing else), an enabled flag, and an optional per-widget allowlist. The **feature
master switch is OFF by default** (`novfora.embeds.enabled`) — security by default.

## 2. Threat model (the APEX part)

| Vector | Position |
|---|---|
| **Private/club content leak** | Embeds never authenticate a member. Every read goes through the proven guest fence: `User::guest()->canDo('forum.view', $scope)` + `VisibleForumIds::for(User::guest())` + `Forum::clubContentVisibleTo(null-user)` — the exact `FeedController`/`SitemapController` pattern, **404 on deny** (never 403, no existence disclosure). No embed parameter can widen visibility because the viewer is hard-coded to the guest principal. |
| **Session riding / CSRF / clickjacking-for-actions** | Embed routes are **stateless**: registered OUTSIDE the `web` middleware group — no `StartSession`, no cookies read or set, GET-only, no state-changing endpoint exists on the surface. A framed embed carries no authority to steal. |
| **Clickjacking the embed itself / overlay abuse** | The embed HTML response sets its own `Content-Security-Policy: frame-ancestors <registered origin>` (plus `'self'` for previews). Unregistered origins can't frame it (modern browsers); legacy browsers without CSP2 fail-open on framing but the content is guest-public and action-free, so framing yields nothing — documented residual. The rest of the app keeps `frame-ancestors 'self'` untouched (the `SecurityHeaders` middleware only fills the header when a response hasn't set its own — the seam already exists). |
| **Cross-origin data theft (CORS)** | JSON endpoints emit `Access-Control-Allow-Origin: <registered origin>` + `Vary: Origin` only when the request's `Origin` matches the site row (no `*`, no credentials flag — content is public but the allowlist keeps freeloaders and quota abuse off). No CORS header at all on mismatch. |
| **Injection via embed params** | All params strictly typed and clamped server-side (`forum` int → must resolve to a guest-visible forum; `limit` int clamped 1..20; `theme` enum light|dark|auto; unknown widget → 404). All rendering is Blade-escaped server output; the web component renders **text nodes only** (no innerHTML of payload fields). Payload fields are plain text (titles, counts, URLs built server-side via `route()`). |
| **Key misuse (embedding on the wrong site)** | The site key is public by design (it appears in the page source of the consuming site). Its authority is capped at "read guest-visible widget data"; binding key→origin means a stolen key used from another origin gets no CORS grant and no frame-ancestors grant. Keys are rotatable and revocable in the ACP; create/update/rotate/revoke are audit-logged (`Audit::log`). |
| **DoS / scraping amplification** | Named rate limiter (per-IP, cache-backed — DB on Baseline, Redis on Enhanced, ADR-0011 pattern) + fragment cache per `(widget, normalized params, site)` with a 60s TTL + `Cache-Control: public, max-age=60` so intermediaries absorb repeats. Payload is bounded (limit ≤ 20 rows, excerpt-free). |
| **Enumeration** | Forum ids are already public in canonical URLs; the guest fence 404s invisible ones identically to nonexistent ones — no oracle. Site keys are 40-char random strings; lookup is by exact key (indexed), 404 on miss, rate-limited. |

## 3. Baseline-tier viability

- No daemon, no queue, no external service: rendering is synchronous Blade; caching uses the default
  cache store (database/file on Baseline); rate limiting is the cache-backed Laravel limiter;
  `embed.js` is a static file served by PHP/webserver. Nothing degrades — this feature is
  Baseline-native, Enhanced just makes the cache faster.
- Subdirectory installs: all URLs built with `route()`/`asset()` (subpath-aware per ADR-0078).
- No Node at runtime: `embed.js` is hand-authored vanilla JS committed to `public/` (not a Vite
  entry), keeping the "prebuilt assets" rule intact and the bundle dependency-free.

## 4. Reuse map (verified in-code before this memo)

- Guest fence: `app/Http/Controllers/FeedController.php` (guest `canDo` + club gate + 404 + 15-min
  cache) — the structural template.
- CSP seam: `app/Http/Middleware/SecurityHeaders.php` only sets CSP when absent → embed responses set
  their own. Note `X-Frame-Options: SAMEORIGIN` is set unconditionally there — embed routes live
  outside `web`, so it never applies to them; the app's own pages keep it.
- Rate-limiter registration: `AppServiceProvider::boot()` (`RateLimiter::for('api', …)` idiom).
- Audit: `App\Support\Audit::log()`.
- ACP surface: Livewire 4 SFC under `resources/views/components/admin/` (⚡ convention) + thin
  wrapper view + `AdminNavigation` entry under the plugins section, `admin.access` + staff-2FA
  re-asserted in `mount()` and every action (the `⚡webhooks`/`⚡modules` idiom).
- **CORS: nothing exists in the codebase (no config/cors.php, no HandleCors)** — the embed group
  emits its own two headers (`Access-Control-Allow-Origin`, `Vary`) from the controller/middleware;
  no global CORS layer is introduced (keeps the rest of the app closed).

## 5. Explicitly out of scope (v1)

- Authenticated/member-scoped embeds (would need real scoped tokens + a consent story — deferred;
  the ADR reserves the seam).
- oEmbed *provider* endpoint, single-topic teaser widget, per-embed theming beyond light/dark/auto.
- Any write path from an embed.

## 6. Acceptance mapping (kickoff → deliverable)

- Server-rendered embed endpoints + documented semver'd contract → §1 + ADR-0103 in `DECISIONS.md`.
- CSP-safe, degrades on Baseline, no auth/data leakage → §2/§3 + `EmbedSecurityTest` adversarial
  fixtures (forged origin, oversized/malformed params, cross-origin JSON, private-forum probe,
  disabled-feature probe, revoked/rotated key, rate-limit breach, no-cookie assertion).
- a11y on embedded widgets → semantic markup + lang + contrast tokens; the widget HTML joins the
  `novfora:a11y:audit` page gate.
- Adversarial verify-then-refute review before proposing merge → run as a multi-lens workflow;
  findings recorded in PROJECT-STATE + fixed pre-commit.

## 7. Decision

**GO.** The untrusted-embedding boundary reduces to (a) the already-proven guest visibility fence,
(b) response-owned CSP/CORS headers on a stateless route group, and (c) an admin-owned origin
allowlist with revocable public keys — all implementable Baseline-native with no new dependencies.
The residual risks (legacy-browser frame-ancestors fail-open on public, action-free content;
public-by-design site keys) are documented above and accepted.
