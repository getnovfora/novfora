<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# Populate private plugin (E6a + E6b) — build plan (2026-07-02, awaiting owner approval)

> Prompt 0 of `BUILD-PROMPTS-2026-07-02.md`. Spec §0/§4 of `ADMIN-API-AND-POPULATE-SPEC-2026-07-02.md`
> is authoritative. Linear: NOV-140 (E6a, ◆ APEX), NOV-143 (E6b) — both moved to In Progress.
> **STOP point: no code until the owner approves this plan.** This file is untracked and never committed
> to the public repo; on approval it moves into the private `novfora-populate` repo.

## 0. Recon results the plan is built on (6-agent codebase sweep, file anchors verified)

**Seams that exist and carry the design (zero core edits):**
- **Module contract** (`app/Modules/ModuleManager.php`, ADR-0031/0104): modules at `modules/<vendor>/<slug>`
  with `module.json` (`provides` vocabulary: routes, listeners, filters, slots, widgets, permissions,
  settings, migrations, **commands, schedule**). Module migrations run on enable and are **fully reversed on
  remove via `migrate:reset`** — the clean-uninstall guarantee is native. Permissions register from the
  manifest; settings via `SettingsRegistry::register`; filters via `Hook::addFilter`; **UI slots via
  `SlotRegistry::addSlot('topic.post.aside', …)`** (the QA module's accepted-answer badge is the exact
  precedent for bot flair). `novfora:module:sign` (ed25519, detached `module.sig` over a canonical
  file digest) + `module_trust_keys` + ACP install-from-zip (`⚡module-install.blade.php`,
  `ModuleInstaller`/`ArchiveGuard`/`PackageSignature`) are all live.
- **Importer infra** (ADR-0034, `app/Import/ImportRunner.php`): driver-agnostic (`SourceDriver` interface,
  7 methods), keyset-resumable, idempotent via `import_maps` UNIQUE(source, kind, source_id) provenance —
  reusable as our purge index. Historic `created_at` preserved via `forceFill`+`saveQuietly`. Content
  rendered at import through `ContentRenderer` (markdown → CommonMark escape → `ContentSanitizer`).
- **Posting domain** (`app/Forum/PostService.php`): `createTopic(User, Forum, title, format, canonical,
  ?prefixId)` / `reply(User, Topic, format, canonical, ?parentPostId)`. Observers maintain every counter
  (`Post::syncAggregates`), fire mention + subscriber fan-out (capped: mentions 10, subscribers 2000,
  chunked), Scout-index approved posts only. `ContentModerator::review()` runs inline; `pending` posts
  defer notifications until mod approval — the HOLD path is real.
- **Cron discipline** (`routes/console.php`): one `schedule:run` baseline entry;
  `novfora:posts:publish-scheduled` (everyMinute, `withoutOverlapping(5)`, restore-skip) is the exact
  idiom for the drip tick; `DigestAssembler` is the canonical transactional-claim/mid-kill pattern.
- **Mail fence**: `SuppressionGate` (`email_suppressions`) is the single chokepoint for immediate AND
  digest mail — suppression rows per sim address make the fence data-driven, no hook needed.
- **Login**: Fortify with **no** `authenticateUsing` callback defined — the plugin can install one
  (replicating default credential check + refusing mapped sim users) with zero core edits.
- **ACP conventions**: admin group middleware = `auth, verified, EnsureSystemPanelAccess,
  RequireTwoFactorForStaff`; Livewire actions re-check `canDo('admin.access')` in `mount()` and every
  action; `x-ui.sparkline` (flat int series → inline SVG) is directly reusable for the pacing preview;
  `WithFileUploads` zip-upload and `response()->streamDownload` export precedents exist.

**Gaps recon found (each has a plan below):**
1. `ImportRunner` has **no counter-finalize pass** (`Post::syncAggregates` never called; Scout not
   triggered) — the plugin adds its own finalize step after backfill.
2. **Modules cannot extend `AdminNavigation`** (hardcoded) and there is no proven Livewire-SFC-from-module
   registration — Studio ships module routes + module views; nav entry is a link on the Plugins page
   description + direct URL (see §4 UI decision).
3. **No stats-exclusion hook**: `ForumStatsWidget`/`AnalyticsService` count users unconditionally — the
   *only anticipated core edit* (one generic `Hook::applyFilters` seam, §6).
4. `provides: schedule` is manifest-legal but core has no explicit seam; standard Laravel
   `Schedule::command()` from the module provider's `boot()` is expected to work (providers boot before
   `schedule:run` enumerates) — **verified first thing at build; fallback is a 3-line generic core hook.**
5. Services force `now()` on `created_at` — irrelevant by design: backfill goes through the importer
   (timestamps preserved), drip publishes at real time.

## 1. Deliverable shape

**New private repo `D:\novfora-populate`** (git init, `Tommy Huynh <tommy@saturnhq.net>`, DCO `-s`, no AI
trailers; NOT inside D:\Forum; never pushed anywhere public):

```
novfora-populate/
├─ plugin/                          # the installable module package (zip root)
│  ├─ module.json                   # slug novfora/populate, api_version ^1.1, provides: [routes, listeners,
│  │                                #   filters, slots, permissions, settings, migrations, commands, schedule]
│  ├─ src/                          # PopulateServiceProvider + engine (PSR-4 Modules\Novfora\Populate\)
│  ├─ database/migrations/          # populate_* tables (reversible; reversed on module remove)
│  ├─ resources/views/              # Studio panes (module-loaded views, x-ui.* reuse)
│  └─ lang/en/populate.php
├─ script/
│  ├─ generate_forum.py             # updated: --config genconfig.json, emits plan schema v1
│  └─ examples/ (genconfig.example.json, plan.example.json)
├─ tests/                           # plugin suite (Pest), run inside forum-dev with the module installed
├─ docs/ (plan-schema.md, genconfig-schema.md, operations.md)
└─ README.md + DECISIONS.md (plugin-local ADRs)
```

Packaging: `novfora:module:sign` over `plugin/` → `novfora-populate-<ver>.zip` + `module.sig`.
Keys: throwaway pair for the dogfood install test; the real owner keypair is generated once by the owner
(`--keygen`), secret never enters the repo.

## 2. E6a — engine (◆ APEX)

### 2.1 Plugin-owned schema (module migrations, all reversible)
- `populate_plans` — id, name, schema_version, status (draft|importing|dripping|paused|completed|purged),
  options JSON (hold_mode, bot_flair, daily_cap, stats_exclude), pacing JSON, counts cache, timestamps.
- `populate_users` — plan_id, user_id (the provenance map; **no core `users` column**), persona snapshot.
- `populate_events` — plan_id, type (topic|reply), payload JSON, publish_at, state
  (pending|claimed|published|failed), claimed_at, published_post_id/topic_id, attempts.
  Index (state, publish_at). The digest-discipline claim target.
- `populate_personas`, `populate_genconfigs` — Studio storage (E6b), versioned JSON payloads.

### 2.2 Plan format v1 (`novfora.populate.plan`, versioned superset of `forum_seed.json`)
`{schema, version:1, meta, users[], topics[], posts[], pacing?, options?}` — ForumGen's current output
imports unchanged after the script update; entries with timestamps ≤ import time = backfill; future/absent
timestamps = drip events (paced by the engine when absent). Schema doc + strict validator
(`docs/plan-schema.md`).

### 2.3 Import path
- **Bounded validation before anything touches the DB**: size cap (default 32 MiB), entity caps (defaults
  5 000 users / 20 000 topics / 200 000 posts — settings-tunable), streaming-safe decode, username/email
  format rules, **sim email domain enforced** (default `@sim.invalid` — RFC-reserved, unroutable),
  reject any group/staff assignment, reject non-markdown formats in v1.
- **Backfill through ADR-0034**: `PlanSourceDriver implements SourceDriver` over the validated plan;
  `ImportRunner` gives batching, resume, idempotency, historic timestamps, canonical→sanitize rendering,
  and `import_maps` provenance (source key `populate:<plan-id>`). Then the plugin's **finalize pass**
  (gap #1): `Post::syncAggregates` per touched topic, forum + user post_count reconcile, Scout index of
  approved posts. Counters are asserted true in tests, not assumed.
- Sim users created with: unusable password hash (random marker that can never verify), `status=active`,
  default member group only, locale/timezone from plan, `email_suppressions` row per address (mail fence),
  `populate_users` provenance row.

### 2.4 Drip runner
- `novfora:populate:tick` — module-registered command; scheduled from the provider
  (`everyMinute()->withoutOverlapping(5)` + restore-skip guard, mirroring `posts:publish-scheduled`).
  Seam verified at build start; fallback = tiny generic core hook (§6).
- **Digest-discipline claim**: transaction → select due `populate_events` (state=pending,
  publish_at ≤ now, plan not paused, global kill switch off, daily cap headroom) with `lockForUpdate`
  → mark claimed → commit → publish each through **`PostService::createTopic`/`reply`** → mark published
  with created ids. Mid-kill: stale claims self-heal after a timeout; republish guard = event row is the
  idempotency record (claimed-but-unpublished re-verifies via provenance before re-publishing). Bounded
  batch per tick.
- **Pacing engine** (pure PHP port of ForumGen's timeline logic + spec extensions): posts/day target,
  diurnal curve (night ≈50% floor), weekday weighting, 75/25 reply:new-topic default, 5-day thread-decay
  window over recently-active seeded threads, jitter — plus curve presets (ramp/steady/decay/S-curve),
  quiet periods, event spikes, per-forum weights. Deterministic given (genconfig, seed) — unit-testable;
  schedules `publish_at` for events without explicit times; re-pace allowed while unpublished.

### 2.5 Fences (all default-on)
| Fence | Mechanism (all zero-core-edit except stats) |
|---|---|
| Kill switch + pause/resume | `populate.paused` plugin setting + per-plan status; honored at claim time |
| Daily cap | per-plan counter vs cap at claim time |
| HOLD-into-modqueue | container-extend `ContentModerator` (provider `extend()`): force HOLD verdict for sim authors when plan is in HOLD mode → posts land `pending`, notifications defer to mod approval (core behavior) |
| Mail suppression | `email_suppressions` rows for every sim address (SuppressionGate is the proven single chokepoint) + `.invalid` domain; push impossible (sim users never log in → no subscriptions) |
| Bot flair | `SlotRegistry` `topic.post.aside` slot renders a "Community bot" badge for mapped sim authors (QA-module precedent). *Note: spec names the v3-g seam, but v3-g's `staffRole()` renders only for staff groups — the module slot is the correct display-only vehicle; flagged as a deviation-in-mechanism, not intent.* |
| Stats exclusion | requires the one generic core hook (§6) |
| No login | unusable hash + plugin-installed `Fortify::authenticateUsing` refusal for mapped sim users + suppressed reset mail |
| Never staff | validator rejects group grants; sim users only ever in the default member group; adversarial spec asserts `canDo('admin.access')` false and primary group never staff |
| Notification volume to real users | existing bounded fan-out caps (mention 10 / subscriber 2 000) — allowed by design, nothing new |

### 2.6 Purge (= the reversibility guarantee) and uninstall
Purge (co-owner capability + confirm token + audit): batched **model-level** `forceDelete` in dependency
order — posts → topics → sim users → then explicit sweeps the recon proved necessary: `ContentSubscription`
rows on purged content (orphan gap), notifications referencing purged content, `SpamAssessment`/mod-queue
rows for held posts, reactions on purged posts, `email_suppressions` rows, `import_maps` (`populate:*`),
`populate_*` rows. Model-level deletes keep counters + Scout self-maintaining via observers; a final
`syncAggregates` reconcile + counter assertion closes it. Uninstall: module `remove` reverses plugin
migrations; core schema untouched (test-proven).

## 3. E6a APEX review + verification
- Adversarial verify-then-refute on the E6a diff **before packaging** — Fable @ max attempted first,
  **Opus 4.8 fallback permitted** (standing env note: Fable has errored for spawned workflow agents here).
  Vectors: hostile plan JSON (entity floods, oversize, type confusion, HTML/SQL smuggling, group-grant
  smuggling), drip double-claim + mid-kill republish, purge completeness (counters, search ghosts,
  orphans), sim-account escalation (login paths incl. reset/social, staff capability).
- Gates in `forum-dev` (`docker exec`, foreground, `--parallel`, tail-capped): plugin suite green with
  module installed; **core suite green with the plugin absent** (trivially — core untouched — verified
  once); Pint + PHPStan over plugin src via the forum toolchain; module migrate → reset → re-migrate clean.
- **Dogfood install proof**: throwaway install (fresh forum-dev DB, `NOVFORA_INSTALL_MARKER` set per the
  standing memory), throwaway trust key in ACP → upload signed zip via U17 path → enable → import → purge
  → uninstall.

## 4. E6b — Studio (four panes)
- **Surface**: module routes under `/admin/plugins/populate/*` behind the exact admin stack
  (`auth, verified, EnsureSystemPanelAccess, RequireTwoFactorForStaff`) + a new module-manifest permission
  `novfora.populate.manage`; module-loaded views reusing `x-ui.*` (global anonymous components — usable
  from module views); **no new JS deps**. UI decision: try Livewire component registration from the module
  provider first; if Livewire 4 SFC registration isn't reachable from a module, the panes are plain
  Blade + POST forms + existing Alpine — every feature (incl. preview) works server-rendered. AdminNav
  can't list module pages (hardcoded, gap #2) — reachable from the Plugins page + direct URL; acceptable
  for a private plugin, noted for a future generic nav seam.
- **Persona Studio**: CRUD on `populate_personas` (all spec fields: weight, board affinities, tone prompt,
  length budget, reply style, posting-hours profile, activity, join-era, name style, avatar, quirks);
  seeded with ForumGen's 10 archetypes; persona-pack JSON import/export (same bounded-upload discipline).
- **Timeline & Pacing Designer**: authors the pacing block; **live preview = the pacing engine run
  server-side over the draft config** → projected posts/day via `x-ui.sparkline` + a sample 48-h schedule
  table. One engine, no duplicated math.
- **Content Controls**: per-forum seeds/themes, banned-topics, locale, richness, title style, per-persona
  overrides; population controls (user count, join distribution, name styles, sim domain; **primary group
  fixed to the member group — displayed, not editable**).
- **Run Console**: plan list + dashboard (published/pending, next events, timeline chart), pause/resume,
  kill switch, HOLD toggle, flair toggle, dry-run (validate + preview, no writes), purge (typed confirm).
- **Genconfig**: versioned `novfora.populate.genconfig` v1 authored by the Studio; export/import JSON
  (streamDownload / bounded upload). Manual round-trip with the script now; API round-trip lands with E1.

## 5. Script update (same repo)
`generate_forum.py --config genconfig.json`: personas, lengths, timeline, forums, population from config
(current constants become the built-in default genconfig); output = plan schema v1 (superset; current
consumers unaffected); secrets only via env (the committed Gemini key in the F:\ForumGen copy is dropped —
**owner should revoke that key**); `sim.hearth.test` domain replaced by the genconfig's sim domain
(default `sim.invalid`).

## 6. Core-repo touchpoints (the only novfora-repo branch, only if/where needed)
Small branch `claude/populate-generic-seams` off `main`, flagged in the morning report, carrying at most:
1. **Stats-exclusion filter hook** (needed): `Hook::applyFilters('stats.users.count.query', $query)` at the
   two count sites (`ForumStatsWidget`, `AnalyticsService` member totals) — generic (any module can filter
   member-count queries), no behavior change with no listeners. + tests + ADR (next-free — 0109 by
   DECISIONS.md tail; spec's 0117–0121 sketch assumed the roadmap reservations, confirm at lift).
2. **Module schedule seam** (only if the provider-boot `Schedule::command()` probe fails): a generic
   "modules register schedule entries" hook in `routes/console.php`.
Nothing else: mail, HOLD, flair, login-refusal, purge, migrations all ride existing seams.

## 7. Order of work (single plugin repo; branch per slice; gate-green commits)
1. Repo scaffold + module skeleton (manifest, provider, migrations) + **seam probes** (schedule-from-boot,
   Livewire-from-module) → decides §6.2 and §4's UI mechanism on day one.
2. E6a: validator + plan schema → PlanSourceDriver + importer finalize → sim-account fences → drip claim
   loop → pacing engine → purge. Adversarial specs alongside each (◆ surfaces at apex effort).
3. E6a apex review → fixes → re-gate.
4. E6b Studio panes over the engine + genconfig export/import + script `--config` update.
5. Compressed-clock end-to-end (import → 48 h simulated ticks via time-travel → purge → byte-equal
   counters → uninstall → core schema untouched).
6. Package + sign + U17 dogfood install proof; morning report + Linear moves + PROJECT-STATE update.

## 8. Owner decisions folded in / assumptions stated
- Plugin slug `novfora/populate`; vendor stays `novfora` (private distribution is a channel property, not
  a namespace property). Entity/size caps per §2.3 are proposed defaults (settings-tunable).
- v1 plans are markdown-only, no attachments/avatars-by-upload (avatar option = initials/Gravatar-off).
- Bot-flair mechanism = module slot (see §2.5 note) — spec intent (display-only badge) preserved.
- The one anticipated core edit is §6.1 (stats hook). If the owner prefers zero core edits this cycle,
  the stats-exclusion fence ships disabled with a documented dependency instead.
