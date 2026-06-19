# Enhanced-Tier Validation — Meilisearch · Redis · Reverb (on the VPS)

> Handoff spec. Prove the Enhanced-tier services now running on the build VPS actually serve the app
> against **live backends** — the roadmap's VALIDATE-BEFORE-GO-LIVE items 1–2 (search, realtime) plus the
> Redis queue/cache path. Everything here is scaffolded + unit-tested but has **never run against a real
> backend** (all tests use faked clients). The goal is to **prove it works or surface what doesn't** —
> report results at each checkpoint; do not force green. Run on the VPS as `dev`. Build-only box, so
> restarts are safe. Most steps are non-sudo; the Reverb service enable needs sudo (`sudo -v` first).

## Pre-flight
- Park any in-progress UI work first: on `fix/ui-audit`, commit or stash and push, so this doesn't entangle it.
- Base validation off the trunk: `git checkout main && git pull --ff-only`.
- Services up: `systemctl is-active redis-server meilisearch novfora-queue` → all `active`.
- `.env` already points Enhanced (bootstrap set these): `grep -E '^(SCOUT_DRIVER|MEILISEARCH_HOST|MEILISEARCH_KEY|CACHE_STORE|QUEUE_CONNECTION|SESSION_DRIVER|REDIS_HOST)=' .env`.

## Locked constraints (CLAUDE.md)
Progressive enhancement is the invariant under test — **the baseline (DB search / DB-or-cron queue / polling) must keep working**; Enhanced is an overlay that degrades gracefully. Don't change the resolver or the channel authorizer. Reverb enablement ships with gates green; small conventional commit, `-s`, authored `Tommy Huynh <tommy@saturnhq.net>`. Do all git on the VPS (real machine).

---

## 1. Redis — cache / session / queue (runtime only, no commit)
Prove the app actually uses Redis and the `novfora-queue` systemd worker drains a real job.
1. **Cache connectivity:** `php artisan tinker` → `Cache::put('vt','ok',60); Cache::get('vt');` returns `ok`; confirm it landed in Redis: `redis-cli --scan --pattern '*vt*'` shows a key. **Verify:** value round-trips via Redis.
2. **Queue round-trip:** tail the worker (`journalctl -u novfora-queue -f`), then dispatch a real job (e.g. tinker: `App\Jobs\RegenerateUserPostHtml::dispatch(\App\Models\User::first())` or trigger a notification). **Verify:** the worker logs it processing; `php artisan queue:failed` is empty; the job's effect occurred. (Queue is Redis now, so jobs live in Redis, not the `jobs` table.)
3. **Fallback intact:** `./vendor/bin/pest tests/Feature/Tier/ServiceTierFallbackTest.php` stays green (no-throw on a dead Redis).
**Report:** cache via Redis (y/n), a job drained by the worker (y/n), `queue:failed` count.

## 2. Meilisearch — index + search + no-leak (runtime only, no commit)
Prove Meili indexes Posts, serves keyword search, and never leaks private-club content.
1. **Index:** `php artisan scout:sync-index-settings` then `php artisan scout:import 'App\Models\Post'`. **Verify:** import reports a count; `MEILI_KEY=$(grep '^MEILISEARCH_KEY=' .env|cut -d= -f2-); curl -s -H "Authorization: Bearer $MEILI_KEY" http://127.0.0.1:7700/indexes/posts/stats` shows `numberOfDocuments > 0`.
2. **Engine path live (not DB):** search a known term via the search page (`/search?q=…`) and typeahead (`/search/suggest?q=…`). **Verify:** results return; confirm the engine path is taken (term-only query, `ServiceTier::isEnhanced(Search)` true).
3. **No-leak — the load-bearing check:** put a unique term in a **private-club** post. As a **non-member**, search it → must **NOT** appear. As a member → appears. (`SearchService` re-gates every engine hit; this confirms it over the live index.)
4. **Graceful fallback:** stop Meili briefly (`sudo systemctl stop meilisearch`), repeat a search → must still return via DB (no error); restart (`sudo systemctl start meilisearch`).
**Report:** docs indexed, live keyword search served by Meili, no-leak result (non-member sees nothing), fallback held.

## 3. Reverb — realtime (install + wire + round-trip; PRODUCES A COMMIT)
`laravel/reverb` is not installed (the `reverb` broadcast connection is inert). This slice enables it. Do it on its own branch: `git checkout -b chore/enable-reverb`.
1. **Install (pre-approved Enhanced-tier dep, ADR-0061):** `composer require laravel/reverb pusher/pusher-php-server`; `php artisan reverb:install`.
2. **Env:** set `BROADCAST_CONNECTION=reverb`; generate `REVERB_APP_ID` / `REVERB_APP_KEY` / `REVERB_APP_SECRET`; `REVERB_HOST=127.0.0.1`, `REVERB_PORT=8080`, `REVERB_SCHEME=http`; mirror into the `VITE_REVERB_*` vars `reverb:install` expects.
3. **Run the server:** the bootstrap staged a disabled unit — `sudo systemctl enable --now novfora-reverb`; confirm `systemctl is-active novfora-reverb` and `ss -tlnp | grep 8080` (listening on 127.0.0.1).
4. **Round-trip (server-side proof, no browser needed):** with a CLI subscriber (a small `pusher-js` node script pointed at the local Reverb, or tinker broadcasting `App\Events\PostCreated` while tailing the reverb log): an **authorized** `private-thread.{id}` subscriber receives the id-only payload; an **unauthorized** subscriber to a private-club thread is **rejected at the `/broadcasting/auth` endpoint** (the `ChannelAuthorizer` no-leak fence — proven server-side by `ChannelAuthorizationTest`; confirm it holds over the live socket).
5. **Frontend Echo (optional this pass):** `npm install laravel-echo pusher-js`, wire `window.Echo` from `VITE_REVERB_*`, `npm run build`; a real browser tab on a topic gets the live reply event. If you defer this, say so — the server-side proof is the gate.
6. **Gates (must stay green with Reverb installed):** `./vendor/bin/pest && ./vendor/bin/pint --test && ./vendor/bin/phpstan`. (Tests force `BROADCAST_CONNECTION=null` via phpunit.xml, so they're unaffected.)
7. **Commit on `chore/enable-reverb`:** `composer.json`/`composer.lock`, any `config/broadcasting.php` / `package.json` / built-asset changes from the install. `-s`, `Tommy Huynh`, conventional message. Push; open a PR to `main`.
**Report:** reverb active + listening, authorized round-trip delivered, unauthorized subscribe rejected, gates green, commit hash.

---

## Deferred — needs external accounts (separate go-live checklist, NOT this pass)
OAuth/SAML providers, **live Stripe** (charging disabled), Web Push (VAPID keys), StopForumSpam API, and at-scale load. These are the rest of VALIDATE-BEFORE-GO-LIVE (`PROJECT-STATE.md`) — leave them until their external credentials are wired.

## Done when
- **Redis:** cache round-trips via Redis and a real queued job is drained by the `novfora-queue` worker.
- **Meilisearch:** Posts indexed; live keyword search is served by Meili; the private-club no-leak holds for a non-member; DB fallback still returns on a dead engine.
- **Reverb:** server live and listening; an authorized broadcast is delivered over the socket and an unauthorized channel subscribe is rejected; enablement committed on `chore/enable-reverb` with all gates green.
- A short results note appended to the **VALIDATE-BEFORE-GO-LIVE** section of `PROJECT-STATE.md` (what's now proven vs still deferred), committed.

Read docs/product/enhanced-tier-validation-kickoff.md and execute it.
