# Security review sweep — mega-build waves (Wave 8.4)

> Status: complete. One MEDIUM found and fixed; the rest of the new attack surface refuted. ADR-0046.

## Method

Adversarial review with a strict **verify-then-refute** discipline: every candidate finding is chased to the
exact failing line and an explicit exploit (input + effect), and is **refuted by default** unless that
concrete exploit holds. Two independent reviewers ran in parallel over non-overlapping surfaces, plus a
first-party apex pass on the permission/visibility core. Threat model: untrusted input = the raw HTTP request
(query/body/headers/route params) from a guest or member; trusted = an operator running an artisan command.

## Scope (new code from this build)

- **Untrusted-input parsing:** `SearchQueryParser` (inline operators), `SearchQuery::fromRequest`.
- **Permission / visibility:** `SearchService` (forum-facet ∩ `VisibleForumIds`), the `posts()`/suggest path.
- **Authorization (own-only):** `SavedSearchService`, `SavedSearchController`, `SavedSearch`.
- **Locale (untrusted → app state):** `LocaleController`, `SetLocale`, `Locales`.
- **HTML parsing:** `AccessibilityAuditor` (DOMDocument), `AccessibilityAuditCommand`.
- **Commands / config:** `LoadTestSeedCommand`, the `bootstrap/app.php` middleware order, the new routes,
  `icon.blade.php`, `language-switcher.blade.php`, and the search/saved-search views.

## Finding (fixed)

### MEDIUM — Unauthenticated DB query amplification via inline search operators
`SearchQueryParser::parse` resolved each operator with its **own** DB lookup inside the token loop, with no
cap on token count, on the public (unthrottled) `/search` endpoint. A crafted `?q=tag:a tag:b … author:x …`
near the query-string limit drove ~1000+ synchronous indexed lookups per request — an unauthenticated
resource-exhaustion amplifier (each unique query string also bypasses caching).

**Fix (commit in Wave 8.4):**
- Resolve operators **once after the token loop**: ≤1 lookup for author, ≤1 for forum, one batched `whereIn`
  for tags (capped at `MAX_TAGS = 16`), and ≤2 date parses — bounded by a small constant regardless of token
  count, preserving the missing→empty (id 0) semantics.
- Defensive `?q` length cap (512 chars) before tokenising.
- `throttle:120,1` added to `/search` and `/search/suggest` (defence-in-depth; the parser fix already
  neutralises the amplification).
- Regression tests: query-count stays ≤3 for a pathological 80×-repeated operator query; `MAX_TAGS` cap.

## Refuted (verified safe — representative)

- **SQL injection / LIKE-wildcard widening** — term is backslash-escaped (`SearchService::escape`); all facets
  are bound Eloquent params.
- **Forum-visibility bypass via `in:` / `?forum=`** — `effectiveForumIds` intersects the facet with
  `VisibleForumIds`; an unseen forum yields `[]` → empty result (no existence oracle). The post query is
  always gated by the intersected forum-id set.
- **IDOR on saved searches** — every read/write is scoped to `user_id`; delete of another user's id removes 0
  rows; route binds a raw int, not a model.
- **Mass assignment** — `SavedSearch.user_id` is set server-side from the authed user; `User.locale` is not
  fillable and is written via `forceFill` only after `Rule::in` validation.
- **XSS** — `q`, `getQueryString()`, saved-search `name`/`term` all go through Blade `{{ }}` (HTML-escaped);
  hrefs always start with the `/search` route path (no `javascript:`); `icon.blade.php` emits only trusted
  map values, never the `name` input.
- **Locale → `App::setLocale` / open redirect** — every candidate passes the `Locales` allowlist before use;
  `back()` resolves to app routes, not request-controlled targets.
- **CSRF** — `/locale`, saved-search store/destroy are in the `web` group and not CSRF-exempt; forms carry
  `@csrf`.
- **XXE / billion-laughs in the auditor** — `DOMDocument::loadHTML` (HTML parser) does not expand custom or
  external entities; no DTD fetch. Non-test caller is operator-CLI only.
- **XPath injection in the auditor** — values are embedded in a double-quoted XPath literal with `"` stripped;
  no metacharacter can restructure the query (worst case: a missed a11y match).
- **SSRF in `novfora:a11y:audit`** — the URL is an operator-supplied CLI argument, not a web surface.
- **LoadTest seeder** — random 24-char passwords (never persisted in clear), `@example.test` emails,
  production confirmation guard.
- **Middleware order** — Laravel's `web` group starts the session before the appended `SetLocale`; auth is
  resolved lazily, so reading `$request->user()` is position-independent; install/board-offline gates run
  before `SetLocale`.

## Also tidied

`Finding`'s `criterion` property (which held the conformance *level*) renamed to `level` for clarity — the
rendered label was already correct; this only removes a misleading field name.
