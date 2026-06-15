<!-- SPDX-License-Identifier: Apache-2.0 -->
# Enhanced search — Meilisearch via Scout (Phase 4 · M4.1)

> ADR-0060. Builds on the baseline search (ADR-0010).

## How it works (for operators)

Search works out of the box on the **database** engine — no external service. If your community
outgrows it, point NovFora at a **Meilisearch** instance for typo-tolerant, faster, more relevant
search. NovFora **detects** the engine and uses it automatically; if Meilisearch ever becomes
unreachable, search **silently falls back** to the database — it never breaks.

Set it up in **Admin → Settings → Search**: pick the driver, enter the host + API key (stored
encrypted). The board **refuses to switch to Meilisearch unless the host responds**, so you can't
strand search on a dead engine. A **Reindex** button queues a rebuild (drained by the scheduler).

## How it works (for developers)

- `SearchService::search()` (the faceted page) routes to the Scout engine **only** when
  `ServiceTier::isEnhanced(Capability::Search)`; any engine error returns `null` from the engine
  path → degrades to `databaseSearch()`.
- **Privacy is apex-safe.** The visibility filter `forum_id IN [...visible]` is applied natively
  (`meiliFilter()`), AND every returned hit is **re-gated in PHP** against approval + the visible
  forum set + `Forum::clubContentVisibleTo()`. The index is never the sole privacy boundary — a
  stale/poisoned index cannot leak a private-club post (proven by test).
- Only keyword queries with no tag/type facet take the engine path (those facets stay on the DB
  engine to remain correct).
- `config/scout.php` declares the Meili `index-settings` (`filterableAttributes: [forum_id, user_id,
  created_at]` — `forum_id` is load-bearing for privacy).

## ⚠ SCAFFOLDED — NOT VALIDATED against a real Meilisearch

No Meilisearch instance exists in the build env; the engine path is proven only against a **faked Scout
engine**. **To validate / enable:**

1. Run a Meilisearch instance (Docker: `getmeili/meilisearch`).
2. Set `SCOUT_DRIVER=meilisearch`, `MEILISEARCH_HOST`, `MEILISEARCH_KEY` (or use Admin → Settings → Search).
3. `php artisan scout:sync-index-settings`
4. `php artisan scout:import 'App\Models\Post'`
5. Confirm relevance + that a private-club post never appears in results for a non-member.
