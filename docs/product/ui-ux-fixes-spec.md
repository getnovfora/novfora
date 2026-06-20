<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# UI/UX fixes — build-ready spec (nav · login i18n · info center)

> **Author:** Tommy Huynh · **Prepared in Cowork for Code to implement.** Three independent fixes,
> each its own gated, conventional, DCO-signed commit. None of these hit the apex (no permission-mask,
> concurrency, or untrusted-input boundary) — **route all three to Sonnet 4.6** per `CLAUDE.md §Model
> routing` (view boilerplate / CRUD-shaped). Build on a fresh branch off `main` (not on the unpushed
> `claude/rh4-subdir-install` tree, which still carries the foreign import-seed WIP).
>
> Suggested branch: `claude/ui-ux-nav-login-infocenter`, **cut off `main` AFTER `claude/phase-5-ga` lands**
> (phase-5-ga carries the nevo→novfora rename + a CI brand gate and the auth i18n that Fix 2 depended on;
> RH-4 is already in `main`). Keep each fix a separate commit so they can be reviewed/reverted independently.

---

## Fix 1 — Header is not gracefully responsive (CSS-only)

**File:** `resources/views/layouts/app.blade.php` (the `<header>`, ~lines 124–256).

**Root cause.** The header is a single flex row (`flex h-14 items-center gap-2 sm:gap-3`) with only **one
breakpoint** (`sm` = 640px). The brand `<a>` has no `whitespace-nowrap`/`shrink-0`, so when the row gets
tight the wordmark is what collapses → "NovFora Dev Build" wraps to two lines. Between ~640–1024px the
brand + four nav links (2px gap) + a fixed `max-w-xs` (320px) search + auth buttons all compete on one
56px row, with no flexible child designated to absorb the squeeze.

**Target end-state (apply against the actual lines; verify class names against the file first):**

1. **Brand link** (~line 159) — stop it wrapping and let it keep its intrinsic width:
   - Add `shrink-0 whitespace-nowrap`.
   - Optional small-screen guard so an unusually long wordmark truncates instead of pushing the burger
     off-screen: `max-w-[55vw] truncate sm:max-w-none`.
2. **Search form** (~line 182, currently `hidden sm:flex ml-auto w-full max-w-xs`) — make it the flexible
   child and defer it to where there's room:
   - `hidden md:flex ml-auto w-full min-w-0 max-w-[11rem] lg:max-w-xs`
   - `min-w-0` is the key bit — it lets the search box shrink rather than force the row to overflow/wrap.
   - Below `md` (768px) search lives in the existing mobile/hamburger panel (already implemented), so
     nothing is lost.
3. **Primary nav** (~line 169, currently `hidden sm:flex items-center gap-0.5`) — give the links breathing
   room: `hidden sm:flex items-center gap-0.5 md:gap-1`.
4. **Right/auth cluster** (~line 192, `flex items-center gap-1 ml-auto sm:ml-1`) — mark it `shrink-0` so the
   sign-in/up buttons never compress; the search (now `min-w-0`) absorbs the squeeze instead.

**Why this works:** exactly one flexible child (`min-w-0` search) absorbs width; brand and auth are
`shrink-0`; nav appears at `sm`, search at `md`. No markup restructure, no new component.

**Verification (manual — this is CSS):** load the forum index + `/login` at **360, 640, 768, 1024,
1280px**. Assert: wordmark never wraps; no horizontal scrollbar; search visible from 768px up; hamburger
owns nav+search below `md`. Add one cheap render assertion in an existing layout/smoke test that the
header markup contains `whitespace-nowrap` on the brand link (guards against regression). No new test file
needed.

**Commit:** `fix(ui): make site header responsive at mid widths (no wordmark wrap)`

---

## Fix 2 — Login i18n tokens — ✅ ALREADY IMPLEMENTED on `claude/phase-5-ga`; live tokens are a DEPLOY gap

> **Superseded by investigation 2026-06-17.** This work already exists on the unmerged branch
> **`claude/phase-5-ga`**, commit **`dd2e08b`** ("i18n(P5.3): externalize the auth + error surfaces"):
> `resources/views/auth/login.blade.php` already uses `__('auth.login.*')` keys, and **`lang/en/auth.php`
> (120 lines) + `lang/es/auth.php` already exist** — covering login / register / forgot / reset / 2FA /
> verify, plus `lang/en/errors.php`. **Do NOT recreate any of this.**
>
> The live `dev.novfora.com` still renders raw `auth.login.*` because the **deployed build is missing the
> backing strings at runtime** — a partial deploy that shipped the updated Blade views but not the `lang/`
> directory (or a stale config/translation cache). `__()` resolves at runtime, so a stale compiled view
> alone wouldn't freeze the keys — the `lang/` files simply aren't on the host.
>
> **Resolution (no new code):** (1) land `claude/phase-5-ga` into `main` (see the sync/merge plan), then
> (2) redeploy **including the `lang/` directory** and run `php artisan optimize:clear` on the host. Confirm
> first on the dev host: `ls -l lang/en/auth.php` (likely missing) and `config('app.locale')` /
> `config('app.fallback_locale')`.
>
> The original spec text below is retained only as a description of what `phase-5-ga` already contains —
> not as work to be done.

## Fix 2 (original spec — now reference only) — Login renders i18n tokens (`auth.login.*`)

**Important — this is a deploy drift, not a bug in the current branch.** The repo's
`resources/views/auth/login.blade.php` (read 2026-06-17) uses **hardcoded English** and renders fine. The
live `dev.novfora.com/login` shows raw keys (`auth.login.title`, `auth.login.email_label`,
`auth.login.password_label`, `auth.login.remember_me`, `auth.login.submit`, `auth.login.forgot_password`,
`auth.login.create_account`) — i.e. **the deployed build is running a keys-based version of this view that
is not in this branch, shipped without a backing `lang/en/auth.php`.** Laravel echoes the raw key when the
string is missing.

**Step 0 (reconcile the drift — do this first).** Identify what `dev.novfora.com` is actually running:
which branch/build was deployed, and whether an auth-i18n externalization already exists in another branch
or an uncommitted experiment (the RH-4 handoff flagged a separate concurrent session). Do **not** create a
second, conflicting version. If that work exists, finish *it* (add the missing `lang/en/auth.php`); if it
doesn't, do the externalization below in-repo so the next deploy fixes the live host. Reconciling first
avoids a merge collision on the auth views.

**The fix (do the deferred i18n externalization correctly — ADR-0043 framework, `lang/<code>/*.php`):**

1. **Create `lang/en/auth.php`.** It must include the **framework defaults** (`failed`, `password`,
   `throttle` — Laravel 11/13 doesn't publish these by default, and overriding the `auth.*` namespace with
   a file that omits them would break `__('auth.failed')`), **plus** the new `login` group. Use the exact
   key names the deployed view already references so the redeploy resolves them:

   ```php
   <?php
   // SPDX-License-Identifier: Apache-2.0
   return [
       // Framework auth scaffolding messages (keep these — do not drop).
       'failed'   => 'These credentials do not match our records.',
       'password' => 'The provided password is incorrect.',
       'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',

       'login' => [
           'title'           => 'Sign in',
           'email_label'     => 'Email',
           'password_label'  => 'Password',
           'remember_me'     => 'Remember me',
           'submit'          => 'Sign in',
           'forgot_password' => 'Forgot your password?',
           'create_account'  => 'Create an account',
       ],
   ];
   ```

2. **Make `resources/views/auth/login.blade.php` use the keys** (replace the hardcoded strings), matching
   the deployed view so repo and host converge:
   - `@extends('layouts.auth', ['authTitle' => __('auth.login.title')])`
   - `<x-ui.input label="{{ __('auth.login.email_label') }}" ... />`
   - `<x-ui.input label="{{ __('auth.login.password_label') }}" ... />`
   - `Remember me` → `{{ __('auth.login.remember_me') }}`
   - `Sign in` button → `{{ __('auth.login.submit') }}`
   - `Forgot your password?` → `{{ __('auth.login.forgot_password') }}`
   - `Create an account` → `{{ __('auth.login.create_account') }}`
   - The social-provider strings already use the JSON/string-key form (`__('Continue with :provider')`,
     `__('or sign in with your password')`) — leave them, or fold them into `auth.php` for consistency;
     either resolves.

3. **Sweep the rest of the auth surface in the same pass** (same latent bug once those views are
   externalized/deployed): `register`, `password/email` (forgot), `password/reset`, and email-verification
   views. Add `register`, `password`, `verify` groups to `auth.php`. Keeps the whole `auth.*` namespace
   coherent and prevents the next "tokens on screen" report.

**Tests** (Pest feature):
- `GET /login` (guest) returns 200 and the body contains **"Sign in"/"Email"/"Password"** and **does not
  contain the substring `auth.login.`** — a direct regression guard for the reported bug.
- Assert `__('auth.failed')`, `__('auth.login.title')`, and every key the auth views reference resolve to a
  non-key string (i.e. `trans($key) !== $key`) — catches any missing string before deploy.

**Commit:** `fix(i18n): externalize auth views + add lang/en/auth.php (kills raw token render)`
*(No ADR — this is executing the i18n string-externalization follow-up already recorded under ADR-0043.
Do note the deploy-drift finding in `PROJECT-STATE.md` so the host/branch mismatch is tracked.)*

---

## Fix 3 — Classic "Info Center" block on the forum index

**Goal.** Replace the bare `<livewire:community.activity-feed />` drop at the bottom of the board index
with a phpBB/XenForo/SMF-style **Info Center**: a **Statistics** panel + a **Who's Online** panel, with the
existing Recent-activity feed kept beneath it.

**Current render point:** `resources/views/forum/index.blade.php` line 63 (`<livewire:community.activity-feed />`),
just after `<x-region name="forum_bottom" />`.

**Parts that already exist (reuse, don't reinvent):**
- `app/Theme/Widgets/ForumStatsWidget.php` — members/topics/posts counts (cached 60s).
- `app/Presence/OnlineMembers.php` — `recent(limit, minutes)` + `count()`, opt-in (`show_online_status`),
  baseline-safe (uses `last_active_at`, no daemon).
- `app/Community/ActivityFeed.php` + the `community.activity-feed` component — keep as the "Recent activity"
  row beneath the info center.

**New cheap data to add (chosen scope — "proper info-center block"):**
- **Newest member** — `User::where('status','active')->latest('id')->first()` (link to profile).
- **Posts today** — `Post::whereDate('created_at', today())->count()` (use the app timezone).
- **Online now count** + the online-members list — from `OnlineMembers`.
- *(Out of scope per decision: persisted "record online" high-water mark, guest counting, birthdays — those
  need new tracking/schema and concurrency care. Leave seams clean so they can be added later.)*

**Implementation:**
1. **`app/Forum/InfoCenter.php`** — a small read-model service. One method `statistics(): array` returning
   **primitives only**, cached 60s (`Cache::remember('novfora:infocenter:stats', 60, …)`), following the
   established RH-9 cache discipline: store `posts`, `topics`, `members`, `postsToday`, and
   `newestMemberId` (an int/null) — **not** an Eloquent model. Rehydrate the newest member with
   `User::find($id)` **after** the cache boundary (mirror `ActivityFeed`). A second method delegates to
   `OnlineMembers` for the presence panel (already cached in its widget; reuse the service directly).
2. **Blade block** — `resources/views/forum/partials/info-center.blade.php` (or a
   `<x-forum.info-center />` component). Two `<x-ui.card>` panels styled like the existing widgets:
   - **Statistics:** "Total posts / Total topics / Total members / Posts today" (number-formatted) +
     "Newest member: <link>".
   - **Who's Online:** "N members online (in the last 15 min)" + the member links, or "No one online right
     now" (the opt-in empty state). Keep the existing 15-min window.
   - Header the block **"Info Center"** to match the genre.
3. **Wire it in** at `forum/index.blade.php` ~line 63: render the info-center block, then keep
   `<livewire:community.activity-feed />` below it under its existing "Recent activity" heading.

**Privacy / correctness (state in the commit body):** the statistics are **aggregate counts only — no post
content** — identical in exposure to the existing `ForumStatsWidget`, so no new privacy boundary and no
hidden-forum leak. Who's-Online stays **opt-in** via `OnlineMembers` (`show_online_status`), unchanged.
Newest member is public (the members directory already exposes it). Keep it baseline-safe: plain Eloquent +
cache, works on the file/DB cache driver, no Redis/daemon dependency (progressive-enhancement rule).

**Tests** (Pest feature, per "tests with every feature"):
- Forum index renders the **Info Center** block with correct `posts`/`topics`/`members` counts.
- **Newest member** = the most recently registered *active* user (seed two; assert the latest renders;
  assert a banned/inactive user is excluded).
- **Posts today** counts only posts created today (seed one today + one backdated; assert == 1; check the
  app-timezone boundary).
- **Who's Online** respects opt-in: a user with `show_online_status = false` and a recently-active opted-in
  user → only the opted-in one appears; stale `last_active_at` is excluded by the window.
- Cache returns primitives (no serialized model) and the rehydrated newest member is correct.

**ADR:** add a short **ADR-0077** (next free number — highest is 0076) recording the info-center as a
**default** forum-index surface and the two scope fences (statistics are aggregate-only; who's-online stays
opt-in; record-online/guests/birthdays deferred). Update `ROADMAP.md` / `PROJECT-STATE.md` notes.

**Commit:** `feat(forum): classic info-center block (statistics + who's online) on the board index`

---

## Gates & wrap (all three)

Run the canonical gate in `forum-dev` after each commit and at branch HEAD:
`docker.exe exec forum-dev php artisan test --parallel` · `pint` clean · `phpstan` (level 5) 0 errors ·
`php artisan migrate` clean. Each unit committed only at a green boundary (the repo's standing discipline).
No DB migration is required for the chosen scope (no new columns) — keep it that way so the change stays a
trivial, reversible deploy.

**Order:** Fix 2 (login) first — it's the visible production bug and is independent; then Fix 1 (nav,
CSS-only); then Fix 3 (info center, the only one with new code + tests). Open one PR per fix, or a single PR
with three reviewable commits.
