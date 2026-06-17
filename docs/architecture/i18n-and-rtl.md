# Internationalisation & RTL (Wave 8.1)

> Status: framework + RTL plumbing shipped; full string sweep is mechanical follow-up. ADR-0043.

NovFora localises through **Laravel's native localisation** — no third-party package. A string lives in a
PHP array under `lang/<locale>/<group>.php` and is rendered with `__('group.key')` (or `trans_choice` for
plurals). `en` is the authoritative, fallback locale.

## The locale allowlist

The set of switchable locales is declared once, in `config/novfora.php` under `locales`:

```php
'locales' => [
    'en'    => ['name' => 'English', 'native' => 'English', 'dir' => 'ltr'],
    'ar'    => ['name' => 'Arabic',  'native' => 'العربية', 'dir' => 'rtl'],
    // …
],
```

Everything that touches a locale value goes through `App\Support\Locales`, which reads this config:

- `Locales::isSupported($code)` — the allowlist guard.
- `Locales::codes()` — for `Rule::in(...)` validation.
- `Locales::direction($code)` / `isRtl($code)` — `ltr`/`rtl` from the entry's `dir`.
- `Locales::default()` — `app.locale`, falling back to `en` if it is not in the allowlist.

### Security: untrusted locale input never reaches `App::setLocale()` unvalidated

The active locale is reader-controlled (a POST to the switcher, a session value, the stored profile field).
Two choke points enforce the allowlist:

1. **`SetLocale` middleware** (web group, appended after the session starts) resolves the locale by
   precedence — **stored `users.locale` → session → default** — and only ever passes a value that has
   cleared `Locales::isSupported()` to `App::setLocale()`. An unknown/forged code is skipped, not used.
2. **`LocaleController::update`** (`POST /locale`, throttled, open to guests + members) validates with
   `Rule::in(Locales::codes())` *before* writing the session and (when signed in) the profile. An
   out-of-list value fails validation and never touches state.

## Adding a locale

1. Add a key to `config('novfora.locales')` with `name`, `native`, `dir`.
2. Create `lang/<code>/` and translate the groups in `lang/en/` (currently `common.php`, `search.php`).
   Anything not yet translated falls back to `en` automatically — partial locales are safe to ship.

That is the whole contract; the switcher, middleware, `<html lang/dir>` and RTL all pick it up with no
further code.

## RTL

Direction is **data on the locale**, not a parallel translation effort. The layout renders
`<html dir="{{ \App\Support\Locales::direction(app()->getLocale()) }}">`; for `ar`/`he` that becomes
`dir="rtl"`. Because the UI is built on Tailwind, **new styles should prefer logical properties / `ms-`/`me-`
(inline-start/end) utilities** over hard `left`/`right` so they mirror automatically. The plumbing is
validated end-to-end (`dir="rtl"` asserted for an RTL locale); a full visual RTL pass across every component
is a manual QA item, not covered by the automated gate.

## Phase 5 (P5.3) — proof locale + the auth/error externalisation wave

P5.3 added a **complete, human-translated proof locale (`es`)** and externalised the highest-traffic
unauthenticated surfaces — **every `auth/*` screen** (sign-in, register, password reset, 2FA challenge, email
verification, confirm-password, registration-closed) and **every `errors/*` page** (403/404/419/429/500/503 +
the standalone error layout). New catalogues: `lang/en/auth.php`, `lang/en/errors.php`. The `es` locale ships
`lang/es/{common,search,auth,errors}.php` — a curated human translation (NOT a machine translation of the whole
app), proving the switcher → `SetLocale` → `__()` path end-to-end with real non-English strings.

Tests added to `LocalizationTest`: the `es` proof locale renders Spanish for all four catalogues (incl.
localised pluralisation), and a registered-but-untranslated locale (`fr`, no `lang/fr/`) falls back **per key**
to `en` — the fence's "a missing key falls back to en" guarantee, now explicit.

### Coverage + the documented residue

- **Externalised + en-complete:** the framework strings, `common`, `search`/saved-search, **all auth screens**,
  **all error pages**. The `es` proof locale covers exactly these.
- **NOT yet externalised (documented residual, mechanical / community-contributable):** the bulk of the
  authenticated front-end (`forum/`, `clubs/`, `pm/`, `profiles/`, `settings/`, `members/`, discovery, the
  ~92 `components/`) and the staff-facing `admin/` ACP (~33 views). This is deliberately deferred per the Phase-5
  fence ("ship a complete `en` base + framework/RTL + ONE proof locale; other locales are
  community-contributable") and ADR-0043's framing of the full sweep as mechanical follow-up. **The framework
  makes this safe to do incrementally**: an un-externalised string renders its literal English, and any locale
  missing a key falls back to `en`, so partial externalisation and partial locales are always correct. There are
  no remaining design decisions — only string-by-string extraction.

## What is shipped vs. follow-up

- **Shipped:** the framework, the `Locales` guard, `SetLocale`, the `LocaleController` + `POST /locale`
  route, the footer language switcher, `<html lang/dir>`, `lang/en/{common,search,auth,errors}.php`, the
  Wave-6.1 search surface + the P5.3 auth/error wave, and the `es` proof locale. Tested in
  `tests/Feature/I18n/LocalizationTest.php` (11).
- **Follow-up (mechanical):** extracting the remaining authenticated-front-end + `admin/` Blade views' hardcoded
  English into `lang/en/*` groups and authoring further non-`en` locale files. No design decisions remain — it
  is string-by-string extraction plus translation, safe to land incrementally.
