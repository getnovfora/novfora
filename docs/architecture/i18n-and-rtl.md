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

## What is shipped vs. follow-up

- **Shipped:** the framework, the `Locales` guard, `SetLocale`, the `LocaleController` + `POST /locale`
  route, the footer language switcher, `<html lang/dir>`, `lang/en/{common,search}.php`, and the Wave-6.1
  search / saved-search surface externalised as the proven pattern. Tested in
  `tests/Feature/I18n/LocalizationTest.php` (9).
- **Follow-up (mechanical):** extracting the remaining ~100 Blade views' hardcoded English into
  `lang/en/*` groups and authoring the non-`en` locale files. No design decisions remain — it is
  string-by-string extraction plus translation.
