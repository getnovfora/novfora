# NovFora UI/UX Audit — Code-Level Fix Spec

**Source:** Black-box browser audit (`novfora-ui-audit.md`, 21 findings) — Sonnet via Claude-in-Chrome on the demo forum.
**This document:** White-box diagnosis against the actual codebase. Each finding was traced to a file:line, and every diagnosis below was confirmed by reading the code (admin P0/P1 and the two ambiguous bugs were verified by hand; the rest via targeted code search). Written so Code can pick it up **cold**.
**Generated:** 2026-06-19 (Cowork → Code handoff).

---

## How to use this spec

1. Work **top-down by priority**. The P0 unblocks all admin navigation and is a one-file change — do it first.
2. Each entry gives: **Root cause** (file:line + the actual code), **Fix** (exact edit, or approach where it's a design call), **Test** (Pest feature/unit unless Dusk is called for), and a **suggested model rung** per CLAUDE.md routing.
3. **Verify line numbers before editing** — locate by the quoted snippet, not the line number (files drift). The harness tracks file state; don't re-read after editing.
4. **Gates are the correctness signal.** After each slice: `pint`, `larastan`, `pest` (and `pest --group=dusk` where browser tests are added). Cap output (`tail -n N`).
5. **Git happens on the real machine, never from the Cowork sandbox.** Commit as `Tommy Huynh <tommy@saturnhq.net>`, `-s` (DCO), no AI trailers. Small conventional commits, one logical change each.
6. **Three findings are NOT defects or are data-only** — see the "Reclassified" section. Don't burn a code change on correct code.

---

## Execution & locked decisions (2026-06-19)

**Directive:** implement **all 21 findings** per this spec (six items are reclassified as "no change" or "data-only" — honour those). Work the sequencing at the end of this doc. The two previously-open product calls are now **locked**:

- **URLs (BUG-002/003): non-breaking dual resolver.** Forum and User get a `resolveRouteBinding()` that resolves numeric input by `id` and non-numeric by `slug`/`username`, plus `getRouteKeyName()` returning `slug`/`username` so generated links use the clean form. Both `/forums/2` and `/forums/announcements` (and `/users/6` and `/users/tommy`) must keep working. Additive and reversible — do **not** drop numeric resolution and do **not** add 301s.
- **Usernames (BUG-019): display name only.** Add the **Display name** field to profile settings now. **Username stays read-only** this pass — do not add a username input, a `username_changed_at` cooldown, or redirect logic. (Username editing is deferred pending a separate decision.)

**Standing rules (CLAUDE.md):** every fix ships with tests; `pint` + `larastan` + `pest` (and Dusk where noted) are the correctness gate, run per slice with capped output; small conventional commits, one logical change each, authored **and** committed as `Tommy Huynh <tommy@saturnhq.net>` with `-s` (DCO), **no AI co-author trailers**; migrations reversible/non-destructive; nothing relitigates a locked stack decision. Do all git on the build host — never the Cowork sandbox.

---

## Cross-cutting themes (read before starting)

These four root causes explain 10 of the 21 findings. Fixing the *theme* is cleaner than fixing each symptom:

- **T1 — Section landing view lost its layout envelope.** BUG-001. One file.
- **T2 — Breadcrumbs are 100% hardcoded literals, per view.** BUG-005, 013, 021. No central generator; each author typed their own parent label, so some are wrong. 9 literal edits across 8 files now; a route-meta/ViewComposer generator is the real long-term fix (out of scope — file as tech-debt).
- **T3 — The seed importer bypasses Eloquent events.** BUG-009, BUG-011. `ImportForumSeedCommand` uses raw `DB::table()->insert()`, so `Post::booted()` hooks never fire and denormalized counters (`users.post_count`) and `topics.view_count` are never written. These are **data artifacts of seeding, not live-code defects** — the runtime paths are correct. Fix = run the existing backfill + harden the importer.
- **T4 — Models lack `getRouteKeyName()`.** BUG-002, BUG-003. Forum/User resolve route bindings by numeric `id`, so slug/username URLs 404. **Canonical-URL form is a product decision** (see BUG-002) — recommend a non-breaking dual resolver.

---

# 🔴 P0 — Blocks all admin navigation

## BUG-001 — Admin section landing pages render as a giant gear icon

**Affected:** `/admin/forums`, `/admin/members`, `/admin/groups`, `/admin/moderation`, `/admin/appearance`, `/admin/plugins`, `/admin/settings`, `/admin/system`, `/admin/security` — all nine share one view.

**Root cause (confirmed):** `resources/views/admin/section.blade.php` is the shared view for every section-index route (rendered by `SectionController`, which correctly does `view('admin.section', ['section' => $section])`). Unlike **every** other admin view, it has **no layout envelope** — no `@extends('layouts.app', …)` and no `@section('content') … @endsection`. It emits `<x-admin.shell>` as a bare top-level fragment. With no `<html>/<head>/<body>` and no stylesheet, the browser renders the shell's first descendant — a `cog` icon (`<x-ui.icon name="cog">` → a raw `<svg>`) — as an unconstrained replaced element that fills the viewport. That is the "giant gear."

Confirmed by comparison: `admin/dashboard.blade.php:2` and `admin/structure.blade.php:2` both open with `@extends('layouts.app', [...])` and wrap their body in `@section('content')`. `section.blade.php` skips both.

**Fix:** Wrap the existing body in the standard envelope, mirroring `dashboard.blade.php`. The `@php` block moves **inside** `@section('content')` (that's where `dashboard.blade.php` puts its `@php`). Replace the file with:

```blade
{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- A per-section dashboard landing (ACP v3 · v3-h). The shell renders the rail (this section highlighted) +
     the section sidebar; the content here is the section summary + quick-access cards for its sub-pages. --}}
@extends('layouts.app', ['title' => 'Admin · '.__('admin.landing.'.$section.'.title')])

@section('content')
@php
    $clusters = \App\Admin\AdminNavigation::sidebar($section);
    $items = collect($clusters)->flatMap(fn ($c) => $c['items']);
@endphp

<x-admin.shell :title="__('admin.landing.'.$section.'.title')" :description="__('admin.landing.'.$section.'.intro')">
    @if ($items->isEmpty())
        <div class="rounded-lg border border-line bg-surface-raised p-6 text-sm text-ink-muted">
            {{ __('admin.landing_empty') }}
        </div>
    @else
        <p class="text-sm text-ink-muted">{{ __('admin.landing_jump') }}</p>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($items as $item)
                <a href="{{ $item['url'] }}"
                   class="group flex items-center gap-3 rounded-lg border border-line bg-surface-raised p-4 hover:border-accent hover:bg-surface-sunken focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-accent-soft text-accent-soft-ink">
                        <x-ui.icon :name="$item['icon']" class="h-4.5 w-4.5" />
                    </span>
                    <span class="min-w-0 flex-1 text-sm font-medium text-ink">{{ $item['label'] }}</span>
                    @if ($item['external'])
                        <x-ui.icon name="external" class="h-3.5 w-3.5 shrink-0 text-ink-subtle" />
                    @else
                        <x-ui.icon name="chevron-right" class="h-4 w-4 shrink-0 text-ink-subtle group-hover:text-ink-muted" />
                    @endif
                </a>
            @endforeach
        </div>
    @endif
</x-admin.shell>
@endsection
```

**Optional (verify first):** add a breadcrumb to match other admin pages — only if `route('admin.dashboard')` and `admin.sections.<section>` lang keys exist:
```blade
@section('breadcrumbs')
    <x-ui.breadcrumbs :items="[['label' => 'Admin'], ['label' => __('admin.sections.'.$section)]]" />
@endsection
```

**Test (Pest feature):** for each section in the list, `get("/admin/{$section}")->assertOk()` as an admin, and assert the response contains the shell/layout chrome (e.g. `assertSee` the section sidebar nav or a known layout landmark) and does **not** render a bare `<svg>` as the document root. A regression guard that the response contains `<html` (i.e. the layout ran) is the cheapest signal.

**Suggested rung:** Sonnet — mechanical view-boilerplate mirror of an existing pattern.

---

# 🔴 P1 — High-priority blockers

## BUG-002 — Forum slug URLs 404 (`/forums/announcements`)

**Root cause:** `routes/web.php` (route named `forums.show`) declares `/forums/{forum}` with no column qualifier, so route-model binding uses the default key `id`. `app/Models/Forum.php` has **no** `getRouteKeyName()` (confirmed — the model uses `$guarded = []` and a `booted()` hook that sets `path`/`depth` but never a slug). The `slug` column **exists and is `UNIQUE`** (migration `2026_06_02_000105_create_structure_scope_tables.php`), so no schema change is needed. Every internal link currently generates the numeric form (`/forums/2`); `forum/partials/forum-row.blade.php:11` even passes `$forum->id` explicitly.

**DECISION (locked 2026-06-19): non-breaking dual resolver** — both URL forms resolve, slug is canonical for link generation. Do **not** swap to a slug-only key and do **not** add 301s. Implement:

1. Add a dual resolver to `Forum` so **both** forms resolve:
   ```php
   public function resolveRouteBinding($value, $field = null): ?Model
   {
       return $this->where($field ?? (ctype_digit((string) $value) ? 'id' : 'slug'), $value)->first();
   }
   ```
2. Set the **canonical** key for URL generation to slug:
   ```php
   public function getRouteKeyName(): string { return 'slug'; }
   ```
3. Update `forum-row.blade.php:11` to pass `$forum` (the model), not `$forum->id`, so generated links use the slug.
4. **Backfill guard:** ensure every forum has a non-null `slug` (the `booted()` hook does not auto-generate one). Add slug generation on create and a one-off backfill for existing rows, else slug-keyed generation throws.

(Slug-only + 301 was considered and rejected for this pass — keep numeric resolution working.)

**Test:** `get('/forums/announcements')->assertOk()` resolves the right board; `get('/forums/2')` still resolves (dual resolver); slug generation on `Forum::create()`; a forum with a null slug doesn't 500 link generation.

**Suggested rung:** Opus `high` — route-model binding on permission-scoped content; the resolver must not widen visibility.

## BUG-003 — User profile URLs 404 by username (`/users/tommy`)

**Root cause:** identical class to BUG-002. `routes/web.php` (`profiles.show`) declares `/users/{user}` → binds by `id`. `app/Models/User.php` has no `getRouteKeyName()`. `username` **exists and is `UNIQUE`** (migration `2026_06_02_000101_extend_users_table.php`) but is **nullable**. Registration requires username (`CreateNewUser`), so live users have one; only legacy/seed rows might be null. All internal links generate `/users/{id}`.

**Fix (mirror BUG-002, null-safe):**
1. Dual resolver on `User`:
   ```php
   public function resolveRouteBinding($value, $field = null): ?Model
   {
       return $this->where($field ?? (ctype_digit((string) $value) ? 'id' : 'username'), $value)->first();
   }
   ```
2. `public function getRouteKeyName(): string { return 'username'; }`
3. Update views that pass `$member->id`/`$member['id']` to profile routes to pass the model where practical: `resources/views/forum/partials/info-center.blade.php` and `resources/views/components/⚡online-members.blade.php`.
4. Guard against null usernames (the dual resolver already falls back to `id` for numeric input; ensure any null-username rows are backfilled or remain reachable by id).

**Note — ties to BUG-019:** username is the canonical profile key but stays **read-only** this pass (locked decision), so no URL-churn/redirect handling is needed now. Revisit if username editing is enabled later.

**Test:** `get('/users/tommy')->assertOk()`; `get('/users/6')` still works; a null-username user is still reachable by id and doesn't 500.

**Suggested rung:** Opus `high`.

## BUG-004 — `&amp;` rendered literally in admin "Forums & structure" heading

**Root cause (confirmed):** `resources/views/admin/structure.blade.php:13` passes a **literal HTML entity** in a plain Blade attribute: `<x-admin.shell title="Forums &amp; structure" …>`. The shell renders `{{ $title }}`, which HTML-escapes again → `&amp;amp;` → the browser shows the literal `&amp;`.

**Important correction to the browser audit:** the **browser tab is fine to leave as-is.** `layouts/app.blade.php:59` is `<title>{{ $title ?? config('app.name','NovFora') }}</title>` and line 2 of `structure.blade.php` already passes a **bare** `&` (`'Admin · Forums & structure'`); `{{ }}` correctly encodes it to `&amp;` in the HTML source, which renders as `&` in the tab. **Do NOT change the layout to `{!! !!}`** — that would be a correct escape removed for no reason (and an XSS footgun for any title carrying user data). The only fix is the heading attribute.

**Fix:** `resources/views/admin/structure.blade.php:13` — change `title="Forums &amp; structure"` → `title="Forums & structure"`.

**Test:** `get('/admin/forums/structure')` as admin `assertSee('Forums & structure', false)` and `assertDontSee('&amp;amp;', false)`.

**Suggested rung:** Sonnet.

---

# 🟠 P2 — Errors

## BUG-005 — Admin breadcrumb says "Content" instead of "Forums"

**Root cause (confirmed, theme T2):** `resources/views/admin/structure.blade.php:7` hardcodes `['label' => 'Content']` in the breadcrumb array. The page is Forums & structure; the label is just wrong. (`lang/en/admin.php` defines `sections.forums => 'Forums'`.)

**Fix:** line 7 → `['label' => 'Forums'],` (or `['label' => __('admin.sections.forums')],`).

**Test:** folded into the BUG-013 breadcrumb test — assert `/admin/forums/structure` breadcrumb contains "Forums", not "Content".

**Suggested rung:** Sonnet.

## BUG-006 — Reaction count badges "inconsistent" → **RECLASSIFIED: not a defect** (see Reclassified section)

## BUG-007 — "1 topics" pluralization on the forum index

**Root cause:** `lang/en/forum.php` stores always-plural static strings (`'topics' => 'topics'`, `'posts' => 'posts'`), and `resources/views/forum/partials/forum-row.blade.php` prints `{{ __('forum.topics') }}` / `{{ __('forum.posts') }}` next to the count (≈ lines 20, 24, 38, 39 — mobile + desktop columns). No singular form exists.

**Fix (house pattern is `trans_choice`):**
- `lang/en/forum.php`: `'topics' => 'topic|topics'`, `'posts' => 'post|posts'`.
- In `forum-row.blade.php`, replace each `__('forum.topics')` with `trans_choice(__('forum.topics'), $forum->topic_count)` and each `__('forum.posts')` with `trans_choice(__('forum.posts'), $forum->post_count)`. (The count `<span class="nums">` stays `number_format(...)`; only the word is pluralized.)

**Test (unit/feature):** render a board with `topic_count = 1` → "1 topic"; `= 2` → "2 topics"; same for posts.

**Suggested rung:** Sonnet.

## BUG-008 — "1 views" on /trending (replies are correct, views aren't)

**Root cause:** `resources/views/discovery/partials/topic-line.blade.php` — replies (≈ line 10) correctly use `Str::plural('reply', (int) $topic->reply_count)`; views (≈ line 11) print a **bare literal** `views`.

**Fix:** mirror the replies line — `… {{ \Illuminate\Support\Str::plural('view', (int) $topic->view_count) }}`.

**Test:** topic with `view_count = 1` → "1 view"; `= 2` → "2 views".

**Suggested rung:** Sonnet.

## BUG-009 — View counts show 0 → **mostly a seed-data artifact** (theme T3)

**Root cause (confirmed):** the runtime increment is correct — `TopicController@show` does `Cache::add($viewKey, …)` then `Topic::increment('view_count')`, throttled once/viewer/hour, counting guests too. `TrendingService` reads `topics.view_count` directly. The zeros come from **seeding**: `ImportForumSeedCommand` inserts topics via raw `DB::table()->update()` and never sets `view_count`, so seeded topics start at 0 and only climb when actually visited (the one topic showing 2 was visited).

**Fix:** No controller/service change. Harden the importer to set `view_count` (from source data if present, else 0) at the topic insert, and/or accept that counts accrue correctly going forward. If the demo needs realistic numbers now, run a one-off seed/update.

**Test:** feature test that two distinct sessions hitting `/topics/{id}` increment `view_count` by 1 each (cache TTL respected); a third hit within the hour does not.

**Suggested rung:** Sonnet (importer) — runtime path is already correct.

## BUG-010 — "Who's Online (0)" header vs green "Online" badges on cards

**Root cause (confirmed, real logic bug — and a privacy leak):** the two surfaces use **different predicates**.
- Header count: `OnlineMembers::baseQuery()` requires `status='active'` **AND `show_online_status = true`** AND `last_active_at` within the window.
- Card badge: `resources/views/components/⚡members-directory.blade.php` (≈ line 158) calls `$member->isOnline()`, which checks **only** `last_active_at` and ignores `show_online_status`.

`show_online_status` defaults to `false` (security-by-default), so users who opted out are excluded from the count (correct) but still flash a green "Online" badge on their card (wrong — both inconsistent **and** a presence leak).

**Fix (targeted, don't change `isOnline()` globally):** in `⚡members-directory.blade.php`, gate the badge on the same opt-in:
```blade
@if ($member->isOnline() && $member->showsOnlineStatus())
```
(Changing `User::isOnline()` itself would also affect the topic poster sidebar `ui/online-badge.blade.php`; keep the fix in the directory unless you intend a global presence-policy change — if so, that's a deliberate, tested decision.)

**Test:** user with `show_online_status=false` + recent `last_active_at` → no badge on /members AND not in the count; with `true` → both show. Counts and badges agree.

**Suggested rung:** Opus `high` — presence/privacy correctness.

## BUG-011 — Member post counts show 0 → **seed-data artifact + un-run backfill** (theme T3)

**Root cause (confirmed):** `⚡members-directory.blade.php` (≈ line 156) reads the denormalized `users.post_count`. The live path is correct (`Post::booted()` increments via `adjustAuthorPostCount` on created/deleted/restored). But the seeder inserts posts with raw `DB::table('posts')->insertGetId()`, so **model events never fire** and `post_count` stays 0. A correct, **idempotent backfill migration already exists** (`2026_06_12_000301_backfill_user_post_count.php`) — it just hasn't run against the seeded data (or ran before the seed).

**Fix:** run `php artisan migrate` (or re-run that migration / execute its `UPDATE users SET post_count = (SELECT COUNT(*) …)`). Going forward, Eloquent-created posts maintain the counter. Secondary hardening: make the importer use `Post::create()` or call `adjustAuthorPostCount` after bulk insert.

**Test:** after backfill, `users.post_count` equals the user's non-deleted post count; creating a post via Eloquent increments it; soft-deleting decrements.

**Suggested rung:** Sonnet — run backfill + optional importer hardening.

## BUG-012 — "Recent activity" on the homepage has no limit / admin control

**Root cause:** the homepage embeds `<livewire:community.activity-feed />` (`resources/views/forum/index.blade.php`); the `ActivityFeed` service (`app/Community/ActivityFeed.php`) hardcodes `WINDOW = 100` and slices to `LIMIT = 50` (≈ line 97) with no settings hook and no pagination. So it's capped at 50, not truly unbounded, but the cap isn't admin-configurable.

**Fix:** make the limit a runtime setting (e.g. `config('novfora.activity_feed_limit')` backed by the settings store, default 10–20) and have `ActivityFeed` read it (e.g. a `forLimit(User $viewer, int $limit)` method). Add a "Recent activity limit" field in the ACP (Settings → General, or a Forum Index section). Optionally add a "See all activity" link when truncated. Pairs with BUG-020.

**Test:** setting the limit to N caps the homepage feed at N items; default applies when unset.

**Suggested rung:** Sonnet (Opus `high` only if the setting touches the cron-baseline cache invalidation).

---

# 🟡 P3 — UX inconsistencies

## BUG-013 / BUG-021 — Breadcrumbs inconsistent site-wide (theme T2)

**Root cause (confirmed):** all breadcrumbs are hardcoded `<x-ui.breadcrumbs :items="[…]" />` literals inside each view; `layouts/app.blade.php:298` just `@yield`s the section if present. No generator, so authors guessed parent labels — several wrongly nest top-level nav items under "Forums."

**Fix — 9 edits across 8 files** (drop the false `Forums` parent on top-level nav; add the missing one on notifications):
- `discovery/trending.blade.php` (≈ 9): `:items="[['label' => 'Trending']]"`
- `whats-new/index.blade.php` (≈ 5–8): `:items="[['label' => \"What's new\"]]"`
- `clubs/index.blade.php` (≈ 5): `:items="[['label' => 'Clubs']]"`
- `clubs/show.blade.php` (≈ 5–9): root becomes `['label' => 'Clubs', 'url' => route('clubs.index')]`, then `['label' => $club->name]`
- `clubs/members.blade.php` (≈ 5–10): `Clubs › {club} › Members` (drop Forums)
- `clubs/create.blade.php` (≈ 5–9): `Clubs › Create`
- `clubs/edit.blade.php` (≈ 5–9): `Clubs › {club} › Manage`
- `notifications/index.blade.php`: add the missing `@section('breadcrumbs')` → `[['label' => 'Notifications']]`
- `admin/structure.blade.php:7`: `'Content'` → `'Forums'` (this is BUG-005)

Members and Messages (`members/*`, `pm/*`) are already correct — leave them.

**Schema rule (document in the edit):** a breadcrumb root equals that section's top-level nav label; no cross-section nesting. **Tech-debt follow-up (out of scope):** replace hardcoded crumbs with a route-meta/ViewComposer generator so this can't recur — file in `tech-debt`.

**Test:** feature tests asserting breadcrumb trails on trending, what's-new, clubs (index + a sub-page), notifications, and admin/forums/structure match the schema.

**Suggested rung:** Sonnet.

## BUG-014 — "Draft restored · Discard" persists on a blank reply form

**Root cause (corrected from the browser audit):** the flag logic is actually sound — `ManagesDrafts::$draftRestored` (`app/Forum/Concerns/ManagesDrafts.php`) defaults false and is set true **only** when `restoreDraft()` finds a draft with `! empty($draft->body_canonical['content'])`; the banner (`⚡reply-composer.blade.php:160`) gates on it. So the audit's "shows on a truly fresh load" is **either** (a) the viewer typed earlier and a draft was genuinely autosaved (expected), **or** (b) the real bug: `empty($canonical['content'])` is too weak for TipTap — an "empty" editor still emits a doc whose `content` is `[{type:'paragraph'}]` (non-empty array), so `saveDraft()` persists it and `restoreDraft()` flags it, making the banner stick forever.

**Fix:** add a single source-of-truth emptiness check that inspects the doc for real text, and use it in **both** `saveDraft()` (line 46, don't persist) and `restoreDraft()` (line 78, don't flag). Pseudocode:
```php
protected function docIsBlank(?array $canonical): bool
{
    // true if no text node with non-whitespace content anywhere in the TipTap doc
}
```
Replace `empty($canonical['content'])` with `$this->docIsBlank($canonical)` and `! empty($draft->body_canonical['content'])` with `! $this->docIsBlank($draft->body_canonical)`. (Belt-and-suspenders: the template could also gate on non-blank canonical, but fixing the persistence check is the root fix. The sub-agent's `#[Locked]` theory is **not** the cause — `mount()` re-runs on `wire:navigate`.)

**Repro first** to confirm (a) vs (b): load a topic you've never typed in as a fresh user → if the banner shows, it's (b); fix as above. If it only shows after focusing/typing, harden the blank check anyway so an empty paragraph never autosaves.

**Test:** focusing the editor without typing real text creates no `PostDraft` and never shows the banner; typing real text → autosaves → banner on reload; Discard clears it.

**Suggested rung:** Opus `high` — subtle persistence logic; reproduce before fixing.

## BUG-015 — Moderation uses the lazy "(s)" pattern

**Root cause:** `resources/views/moderation/dashboard.blade.php` inlines `… item(s) awaiting review` (≈ line 35) and `… open report(s)` (≈ line 46). No lang file for moderation.

**Fix:** `trans_choice` the suffix, matching the `follower|followers` house idiom:
- `{{ $queueCount }} {{ trans_choice('item awaiting review|items awaiting review', $queueCount) }}`
- `{{ $counts['open_reports'] }} {{ trans_choice('open report|open reports', $counts['open_reports']) }}`

**Test:** 0 → "0 items…", 1 → "1 item…", 2 → "2 items…"; same for reports.

**Suggested rung:** Sonnet.

## BUG-016 — User Settings tabs wrap to two rows

**Root cause:** `resources/views/components/ui/tabs.blade.php:5` uses `flex flex-wrap` on the tab `<nav>`; the settings shell (`components/settings/shell.blade.php`) defines 10 tabs, which overflow to a second row.

**Fix (recommended — Option B, scoped):** convert the settings shell to a left **sidebar nav**, reusing the proven admin rail pattern (`components/admin/rail.blade.php`): a two-column grid (`grid grid-cols-[14rem_1fr]`) with a vertical nav, scoped to `shell.blade.php` (≈ lines 19–27). Avoids touching the shared `tabs.blade.php` (used elsewhere). *Option A (one-row scroll strip) is a one-class swap (`flex-wrap` → `overflow-x-auto flex-nowrap`) but mutates the shared component — only do this if every `<x-ui.tabs>` caller should scroll.*

**Test (Dusk):** settings nav renders on a single axis (no second row) at standard desktop width; all 10 destinations reachable.

**Suggested rung:** Sonnet.

## BUG-017 — Public profile page is sparse (no Activity/Posts/About)

**Root cause:** `resources/views/profiles/show.blade.php` renders only a hero card (cover/avatar/badges/follow), the staff-tools card, staff notes, a conditional About card, and a conditional signature. No tabbed Activity/Posts/About. `ProfileController::show()` passes only `user, fields, values`.

**Fix (feature work — do with BUG-018):** after the hero (≈ line 65) add a `<x-ui.tabs>` with Activity / Posts / About. Reuse the existing `components/community/⚡activity-feed.blade.php` for Activity; add a posts query and pass the collections from `ProfileController::show()`. Keep "About" (custom fields + signature) as a tab rather than inline.

**Test:** profile renders Activity/Posts/About tabs; Activity lists the user's recent items; empty states render.

**Suggested rung:** Sonnet (Opus `high` if post visibility must respect per-forum permissions in the query — it should, so treat the posts query as correctness-load-bearing).

## BUG-018 — Red "Delete account" button is front-and-center on profiles

**Root cause:** `profiles/show.blade.php` (≈ lines 68–78) renders the "Staff tools" card with a `variant="danger"` "Delete account…" button immediately under the hero. The **gate is correct** — `AccountDeletionService::canForceDelete($viewer, $user)` requires `bans.manage` and self-exclusion, so regular members never see it — and the action routes to a full confirmation page (`moderation/confirm-delete.blade.php`) with acknowledgement. The problem is purely **placement/prominence**.

**Fix:** move the staff-tools card into a collapsed `<details><summary>Staff tools</summary>…</details>`, or into a staff-only tab once BUG-017's tabs exist. Keep the permission gate and the existing confirmation page as-is.

**Test:** non-staff viewer never sees the card; staff viewer sees it collapsed/secondary, not as the first post-hero element.

**Suggested rung:** Sonnet.

## BUG-019 — No display-name / username field in profile settings

**Root cause:** `resources/views/profiles/edit.blade.php` has signature, custom fields, avatar, cover — no `display_name`/`username`. `ProfileController::update()` (≈ lines 46–51) doesn't validate or persist them. Both columns exist and are in `User`'s `#[Fillable]`. Registration validates username as `alpha_dash|min:3|max:30|unique`. There is **no** username-change cooldown column anywhere.

**Fix (locked: display name only; username read-only this pass):**
1. Add a **Display name** input to `edit.blade.php` (a card before signature). Do **not** add a username input this pass.
2. In `ProfileController::update()` validate and persist `display_name` (`nullable|string|max:…`) before `$user->save()`. Leave `username` unhandled (read-only).
3. Username editing — including any `username_changed_at` cooldown and old-handle redirect — is **deferred** (separate decision). Do not implement it now.

**Test:** updating display_name persists and shows on the profile; the form exposes no username input (username unchanged).

**Suggested rung:** Sonnet (Opus `high` for the username-change policy decision).

---

# 🟢 P4 — Feature gaps

## BUG-020 — No "Recent activity" widget in Layout & Widgets

**Root cause:** the widget registry (`app/Theme/WidgetRegistry.php`) is populated in `app/Providers/ThemeServiceProvider.php` (≈ lines 42–47) with six widgets (HTML block, Forum stats, Recent topics, Online users, Search, Featured). There is no `RecentActivityWidget`, so the homepage activity feed is hardcoded, not widget-controlled.

**Fix:** add `app/Theme/Widgets/RecentActivityWidget.php` (copy the `RecentTopicsWidget` pattern — it already clamps a `count` field and has `fields()`), `key()='recent_activity'`, a `count` field (1–50, default 20), and `render()` calling `ActivityFeed` with the clamped count. Register it in `ThemeServiceProvider`. Depends on BUG-012's `ActivityFeed` limit parameterization.

**Test:** the widget appears in Layout & Widgets, persists its `count`, and renders that many items.

**Suggested rung:** Sonnet.

## BUG-021 — Trending breadcrumb nests under Forums

Same fix as BUG-013 (`discovery/trending.blade.php`). Covered above.

---

# Reclassified — do NOT change correct code

## BUG-006 — Reaction count badges "inconsistent" → **WORKS AS DESIGNED**

Verified in `components/forum/⚡post-reactions.blade.php` and `ReactionService::countsForPost()`. The count keys align: config types are `like/love/helpful/insightful/funny/disagree` and `countsForPost()` returns `pluck('count','type')` on the same keys — so a nonzero count is **never** silently dropped. The bar renders a button for every reaction type when `$canReact` is true (so the viewer can pick any), and shows the count badge only when `count > 0`. Hence "👍 1 … ❤️ (no badge) … 💡 1 …" is correct: the unbadged ones simply have zero reactions, and they only appear at all because the viewer can react. Guests/non-reactors see only reacted types (all badged). **No code change.** Optional UX nicety (separate ticket, not a bug): visually distinguish "react" affordances from tallied reactions (e.g. an "＋" picker) so the affordance reads less like an inconsistency.

## BUG-009 (view counts) and BUG-011 (post counts) — **data artifacts, not code defects**

The runtime paths are correct (see T3). The fix is operational (run the existing backfill; harden the seed importer to fire model events / set `view_count`), not a change to the view/controller logic. Listed above under their IDs for completeness.

---

# Suggested sequencing for Code

1. **BUG-001** (P0, one file) — unblocks admin. Ship alone.
2. **BUG-004 + BUG-005 + BUG-013/021** — the breadcrumb/title literal cluster (theme T2), one commit per file group.
3. **BUG-007 + BUG-008 + BUG-015** — pluralization (`trans_choice`), one commit.
4. **BUG-010** — presence/privacy fix (Opus).
5. **BUG-011 backfill + BUG-009 importer** (theme T3), one commit.
6. **BUG-002 + BUG-003** — route binding (Opus); confirm canonical-URL decision first.
7. **BUG-012 + BUG-020** — activity limit + widget, together.
8. **BUG-016**, then **BUG-017 + BUG-018** (profile tabs), then **BUG-019** (ties to BUG-003).
9. **BUG-014** — reproduce, then fix the blank-doc check (Opus).

**Decisions (locked 2026-06-19):** URLs = non-breaking dual resolver (BUG-002/003); profile settings = display-name editing only, username read-only (BUG-019). No open product calls remain — execute the full set.

Per CLAUDE.md: tests ship with every fix; gates (`pint`/`larastan`/`pest`) are the signal; commit as `Tommy Huynh <tommy@saturnhq.net>` with `-s`, no AI trailers, from the real machine — never the Cowork sandbox.
