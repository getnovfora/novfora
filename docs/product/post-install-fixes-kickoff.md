<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# Post-install live-host fixes (RH-8 + RH-9) — Claude Code kickoff

> The live-host install **completed end-to-end** (RH-7 validated on `nevo.adorablespider.com` — wizard →
> demo community → topics render). The post-install smoke then surfaced two real bugs, both diagnosed from
> the live host's `laravel.log` + code reading. Evidence below is conclusive — implement, don't re-litigate.

---

```
Fix the two post-install live-host bugs (RH-8 root route, RH-9 cache poisoning), close their test gaps,
and rebuild the bundle. No new product features.

STEP 0: confirm the RH-7 branch (claude/lucid-archimedes-db7iE, commit b0953cc) is MERGED into main and you
are working on top of it — `git log` must show the RedirectIfNotInstalled allowlist fix and
tests/Feature/Install/InstallerEnforcedLivewireTest.php. If it is not merged, STOP and report. Then read
PROJECT-STATE.md and docs/product/real-host-findings.md. Confirm suite green, tree clean.

RH-8 — ROOT ROUTE IS THE LARAVEL SCAFFOLD WELCOME PAGE:
  routes/web.php still has `Route::get('/', fn () => view('welcome'))`. Post-install, the site root renders
  Laravel's marketing page; the forum lives only at /forums. Invisible until now: pre-install every request
  redirected to /install, and no test ever asserted the root route.
  FIX: make `/` the community home. Either serve ForumController@index at `/` (and 301 `/forums` → `/`, or
  keep /forums as the canonical and 301 `/` → route('forums.index')) — pick the lower-churn option given
  route('forums.index') is referenced across views; ONE canonical URL (no duplicate content). DELETE the
  scaffold resources/views/welcome.blade.php (clean-room hygiene — it is Laravel's stock page, not ours).
  TEST: `/` post-install returns the forum home (or a permanent redirect to it) and never the welcome view;
  `/` pre-install still redirects to /install (existing enforcement behavior unchanged).

RH-9 — SECURITY HARDENING + OBJECT CACHE = POISONED FRAGMENT CACHE (the /forums 500):
  Live evidence (laravel.log, 15 identical entries, authed AND anonymous):
    "Call to a member function isCategory() on string
     (View: resources/views/forum/index.blade.php) at storage/framework/views/<hash>.php:6"
  Timing on the live host: /forums works on a cache MISS, then 500s for every request in the next ~60s
  (the TTL), then works once, then 500s again — alternating. That correlation is the tell.

  ROOT CAUSE (exact chain — verified in this repo's code):
    • config/cache.php sets 'serializable_classes' => false (P1.5 anti-object-injection hardening — KEEP IT).
    • CacheManager passes that to the store; DatabaseStore::unserialize() sees `!== null` and calls
      unserialize($value, ['allowed_classes' => false]).
    • ForumController@index caches a LIVE ELOQUENT COLLECTION:
      Cache::remember('forum.index.tree', 60s, fn () => Forum::query()...->with('children')->get())
      — the ONE place in the app that caches model objects.
    • On a cache HIT, allowed_classes:false turns the stored Collection into __PHP_Incomplete_Class.
      Blade's @forelse iterates that object's raw properties — the first is __PHP_Incomplete_Class_Name,
      a STRING ("Illuminate\Database\Eloquent\Collection") → $node->isCategory() → the exact 500.
    • WHY EVERY TEST MISSED IT: the suite runs CACHE_STORE=array — the array store never serializes, so
      objects round-trip by reference and allowed_classes never applies. The bug needs a SERIALIZING store
      (database/file/redis), i.e. any real deployment.

  FIX (keep the hardening; fix the data):
    1. ForumController@index: stop caching Eloquent objects. Cache PRIMITIVES — a plain array tree
       (id, title, slug, type, position, the per-node fields forum-row needs: topic_count, post_count,
       last_posted_at as ISO string, parent_id) — and render from that (adapt the view/partial or rehydrate
       cheap value objects). OR drop the fragment cache entirely (8-row query, 60s TTL, and the code comment
       already says it is "NEVER load-bearing") — your call; justify in the commit. Do NOT allow-list model
       classes in serializable_classes — that re-opens the object-injection surface the hardening closed.
    2. REPO-WIDE SWEEP: audit every Cache::put/remember/rememberForever/increment value in app/ for
       non-scalar payloads (models, Collections, Carbon instances). Known suspect: the queue heartbeat
       (HealthController::QUEUE_HEARTBEAT, 'novfora:health:queue_drained_at') — live /health shows
       queue.ok: null, consistent with a Carbon object coming back as __PHP_Incomplete_Class and being
       discarded. Store epoch ints / ISO strings instead. Fix every object write found.
    3. Add a defensive note in config/cache.php above serializable_classes: cached values must be
       scalars/arrays — objects will not survive a serializing store under this hardening.

  TESTS (the missing class of coverage — cache-HIT through a SERIALIZING store):
    • A feature test that uses a serializing store (CACHE_STORE=file against a temp dir is simplest in CI;
      database-on-sqlite also fine): request /forums TWICE post-install-state; assert BOTH return 200 and
      the second (cache-hit) response contains the seeded category titles. This fails on main today and
      passes after the fix.
    • Same serializing-store treatment for whatever the sweep (FIX 2) finds — e.g. the health endpoint's
      queue heartbeat: prime → hit → queue.ok must not be null because of deserialization.
    • RH-8's root-route test as above.
    • A post-install public-route smoke test: with the app in installed state + demo seed, GET every public
      route (/, /forums, /forums/{id}, /topics/{id}, /search, /sitemap.xml, /robots.txt, /health, /users/{id},
      /login, /register) and assert no 5xx. Cheap, catches this whole class earlier.

DELIVER:
  • Full suite + new tests green; Pint/Larastan/composer-audit clean.
  • Rebuild scripts/build-release.sh + scripts/verify-release.sh (cold boot → 302 /install). Report the new
    novfora-release.zip size + sha256 and surface the artifact.
  • Docs: add RH-8 and RH-9 entries to docs/product/real-host-findings.md (status FIXED, with the root-cause
    chains above), mark RH-7 "FIXED + VALIDATED on the live host (full wizard → installed community)", and
    refresh the Next list (remaining: RH-4 subdirectory design-first, RH-5 assets+CI guard, Dusk enforce-ON
    harness split). Update PROJECT-STATE.md.
  • Commit (conventional, DCO, no AI attribution) and push.

SCOPE FENCE: RH-8 + RH-9 + their tests + bundle rebuild + docs only. Keep serializable_classes => false.
No new product features; no relitigating locked decisions.
```

---

## After this

The owner deploys the rebuilt bundle (or the changed files) to the live host — `/` becomes the community,
`/forums` stays stable under cache hits, and `/health`'s queue check reports truthfully once cron is running.
Remaining open real-host items: **RH-4** (subdirectory install, design-first), **RH-5** (stale committed
assets + CI freshness guard), and the **Dusk enforce-ON harness split** (follow-up from RH-7).
