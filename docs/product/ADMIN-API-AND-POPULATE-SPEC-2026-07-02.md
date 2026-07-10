<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# NovFora — Admin API + "Populate" (content seeding/drip) — Program Spec (2026-07-02)

> **What this is.** The plan for a true administrative REST API — programmatic management of settings,
> backups/restore, forum structure, users/groups, moderation, and maintenance — plus **Populate**, the
> integrated content seeding + drip engine that grows out of `F:\ForumGen`. Owner decisions captured
> 2026-07-02: generator is **both, phased** (seed-import + drip first, native LLM generation second, with
> drip pacing that behaves like a real forum); backups are **full (DB + uploads)**; the program lands as
> **v1.4 Phase 4E** (backend-only, can run parallel to the theming phases — low collision).
>
> Slots into `ROADMAP-V1.3-V1.5-2026-07-02.md`. Conventions: independent branch per slice, gated green,
> ◆ = apex adversarial review before merge, ultracode routing (don't pin rungs), every slice Baseline-green.

---

## 0. What exists (build on, don't rebuild)

- **API v1 (ADR-0033):** `/api/v1/*`, `ApiTokenService` + `AuthenticateApiToken` (hashed tokens), engine
  authorization inside controllers, `throttle:api`, install/upgrade maintenance gates ahead of auth.
  5 member-scoped endpoints. **The Admin API extends this spine — same token table, new scope model.**
- **Permission engine:** every admin surface already has capability keys (`admin.access`,
  `admin.<section>.access`, per-capability keys). The API must ride `canDo` — never a parallel authz path.
- **Importer pipeline (ADR-0034):** batched, resumable, counter-maintaining import infra — the seed
  importer reuses it rather than the raw-DB writes in ForumGen's `ImportForumSeedCommand.php`.
- **Cron discipline:** `withoutOverlapping` + short-mutex + transactional claim (the digest pattern) — the
  drip runner reuses it verbatim.
- **Audit log, upgrade/restore gates (RH-10/11), backup-first deploy habit** — all reused.
- **ForumGen (`F:\ForumGen`):** persona archetypes with weights/boards/token budgets, diurnal rhythm,
  reply-vs-new-topic ratio (75/25), 5-day thread-decay window, multi-provider LLM rotation with cooldowns,
  seed JSON of users/topics/posts with historic timestamps. **The timeline/persona logic is the spec for
  Populate's pacing engine; the Python script remains a supported external generator.**

---

## 1. Auth & authorization — scoped admin tokens ◆ APEX

The trust spine. One design rule: **a token can never do more than its owner.**

- **Scoped tokens on the existing table:** additive columns — `scopes` (JSON array), `expires_at`,
  `last_used_at`, optional `ip_allowlist`. Token secret hashed at rest (already), prefixed
  (`nvfa_` + random) for secret-scanning.
- **Effective ability = token scopes ∩ user `canDo`.** Every endpoint declares a required scope AND the
  underlying capability key; both must pass. A revoked/demoted user's tokens die with the mask (the
  resolver is authoritative — same posture as ADR-0080's expiry seam).
- **Scope taxonomy** mirrors the ACP sections: `admin:settings.read|write`, `admin:structure.read|write`,
  `admin:members.read|write`, `admin:moderation`, `admin:backups.read|create`, `admin:restore` (never
  bundled into a wildcard), `admin:maintenance`, `admin:populate`. No `admin:*` super-scope.
- **Minting:** only from a 2FA-verified panel session (staff-2FA step-up), gated by a new
  `admin.api_tokens.manage` capability; destructive scopes (`restore`, `populate` purge) additionally
  require the actor be a **co-owner**. Secret shown once. Rotation + revoke endpoints.
- **Every write audited** (existing audit log: actor = user, `via_token` provenance). Per-token rate
  limits (`throttle` keyed by token). Idempotency: all mutating endpoints accept an `Idempotency-Key`
  header (stored 24h, replay returns the original response) — automation-safe on flaky cron/CI callers.
- **Contract:** OpenAPI 3.1 document generated from route metadata, published at `/api/admin/v1/openapi.json`
  (token-gated) + shipped in `docs/api/`. Consistent error envelope `{error: {code, message, fields?}}`,
  cursor pagination everywhere.

◆ Review vectors: scope escalation, token/capability desync, 2FA bypass via token mint, replayed
idempotency keys, audit evasion. *ADR: program parent + token-scope child (confirm next-free ≥ 0117).*

## 2. Resource surface — `/api/admin/v1/*`

Build order = risk order (read → write → destructive). Everything routes through the **existing domain
services** (StructureService, UserBanService with the S5 owner-strand guard, GroupManager → MembershipCache
seam, moderation services) — the API is a thin authenticated shell, never a second code path.

| Group | Endpoints (summary) | Notes |
|---|---|---|
| **Settings** | `GET/PUT /settings/{group}` | Same validation as the ACP forms (reuse form requests); diff-audited |
| **Structure** | CRUD `/categories`, `/forums`; `POST /forums/{id}/move`, `/reorder`; delete-with-reparent | Tree ops mirror StructureService exactly; **ACL read-only in v1** (`GET /forums/{id}/permissions`) — permission *writes* stay panel-only until a dedicated apex pass |
| **Members** | list/search (PII fields scope-gated like A1), `POST /users`, ban/unban, group add/remove, trust set | Rides UserBanService (owner-strand guard), GroupManager (membership-cache seam), F2 trust services — all guards inherited |
| **Moderation** | queue list, approve/reject, reports list/resolve | HOLD-only semantics preserved |
| **Backups** | `POST /backups` (async job), `GET /backups`, `GET /backups/{id}/download`, `DELETE`, `PUT /backups/schedule` | See §3 |
| **Restore** | `POST /restore/uploads` (chunked) → `POST /restore/{id}/verify` → `/dry-run` → `/execute` → `GET /restore/{id}/status` | See §3, ◆◆ |
| **Maintenance** | health, version, cache clear, counter rebuild, queue depth, cron status, `POST /upgrade` (existing novfora:upgrade path) | Pairs with U16's ACP surface — same services |
| **Populate** | see §4 | |

Webhook events (B3 infra) grow admin topics: `backup.completed`, `restore.finished`, `populate.plan.completed`.

## 3. Backups & restore — full (DB + uploads) ◆◆ APEX

**Backup artifact:** one versioned archive — DB dump (tier-aware: mysqldump/pg_dump when available,
PHP-native fallback chunked writer on Baseline) + `storage/uploads` + a manifest (schema version, app
version, counts, per-file SHA-256, created_by). Built by a queued, **resumable** job (cron-drained on
Baseline — chunk tables, checkpoint progress; the mid-kill resume discipline). Retention policy + schedule
stored as settings; artifacts land outside webroot; `download` streams with range support.
**A DB dump contains every secret and every PM → `admin:backups.*` scopes are co-owner-mintable only, and
an optional at-rest encryption passphrase (libsodium secretstream) is offered at creation.**

**Restore is the most dangerous endpoint in the product.** Staged pipeline, no shortcuts:
1. **Upload** (chunked, size-capped, `ArchiveGuard`-style streamed extraction — never `extractTo`).
2. **Verify** — manifest checksums, schema-version compatibility (only same-or-older schema restorable,
   then the upgrade path runs forward), brand/install-id match warning.
3. **Dry-run** — report: tables/rows/files to replace, version delta, disk headroom.
4. **Execute** — flips the existing upgrade/maintenance gate (web+API 503 except restore-status), takes an
   automatic **pre-restore snapshot** (backup-first, always), restores into a staging schema/dir, atomic
   swap, migrations forward, counters verified, gate released. Any failure → automatic rollback to the
   snapshot + quarantined artifact + audited reason (the U17 rollback posture).

◆◆ Review vectors: zip/path traversal, partial-restore kill-timing (every stage resumable or reversible),
gate bypass during swap, secret exfiltration via download scope, dump injection on the PHP-native path.

## 3b. Self-upgrade — upload the release zip, the forum upgrades itself ◆◆ APEX

**What exists (build in front of, don't touch):** `UpgradeRunner` (RH-10/ADR-0021) already handles the
entire DB side once new code is on disk — cache-locked run, maintenance gate (`SchemaState::beginRun`),
**pre-upgrade backup (abort on failure)**, `migrate --force`, cache refresh, resume-by-idempotency on
mid-kill, failure hold with the "re-upload the previous zip" no-SSH recovery, RH-11 restore coordination.
Today the *code* still arrives by hand (panel/FTP). **This feature is the staged code-swap layer in front
of that proven runner** — after it, "upgrade" = upload a zip in the ACP (or one API call) and wait.

**Pipeline (mirrors restore §3 — shared `StagedArtifact` internals):**
1. **Upload** — ACP System → Updates, or `POST /upgrade/uploads` (chunked); `ArchiveGuard` streamed
   extraction, never `extractTo`.
2. **Verify** — **core-release ed25519 signature**: `build-release.sh` signs the artifact with the NovFora
   core release key (distinct from `module_trust_keys`; public key pinned in-app with a rotation seam);
   present-but-invalid ALWAYS rejected, unsigned rejected unless the loud dev override (U17 posture).
   Preflight: PHP version + extensions, disk headroom, writability, integrity manifest, version check —
   **downgrades allowed only as the explicit recovery path** (loud confirm; schema never rolls back
   automatically — that's restore's job).
3. **Stage** — extract to `releases/<version>` beside the live tree. Layout-aware: handles the RH-4
   Option A (symlinked `public/`), B (scaffold), and C (copy) installs; `.env`, `storage/`, `modules/`,
   themes, uploads live outside the release dir and are never touched.
4. **Swap** — under the existing maintenance gate: symlink flip where the host allows, else ordered
   manifest copy (the `build-release.sh` allowlist is the manifest; stale-file delete-list derived from the
   previous release's manifest). Previous release dir retained (N=2) for **one-click code rollback**.
5. **Hand off** — the existing `UpgradeRunner` path runs (immediately via the stepper, or next cron tick):
   backup → migrate → cache refresh (**including `route:clear`** — the standing stale-route-cache lesson) →
   gate release. Nothing new on the DB side.
6. **Health-check + finalize** — `/health` probe + asset-manifest check; failure at any step → swap back to
   the retained release + the existing stuck-hold surfacing + quarantined zip.

**Baseline execution model:** no daemons — a persisted `upgrade_runs` state machine advanced by
installer-style web steps (AJAX stepper in the ACP) *or* cron ticks; every step idempotent + resumable
(the mid-kill discipline). API: `POST /upgrade/uploads` → `/verify` → `/dry-run` → `/execute` →
`GET /status` → `POST /rollback`. Scope `admin:upgrade`, co-owner-mintable like `admin:restore`.

**Update channel (owner decision 2026-07-05): GitHub Releases is the primary channel.** The ACP System →
Updates surface checks the **GitHub repo's Releases API** (`getnovfora/novfora`; unauthenticated,
rate-limit-aware, cached check on a daily cron tick + a manual "Check now"):

- **One-click "Upgrade":** fetch the latest release's zip asset **+ its detached `.sig` asset** (both
  published by `build-release.sh` / the release workflow) → same verify → stage → swap → `UpgradeRunner`
  pipeline as an uploaded zip. GitHub is a *transport*, never a trust root — **the ed25519 signature is
  the only thing trusted**; a compromised GitHub account cannot ship an installable forgery.
- **Specific version:** a version picker listing GitHub tags (upgrade to any newer tag), **plus manual zip
  upload** for pinning an exact version, air-gapped hosts, or the downgrade recovery path.
- Hosts without outbound HTTP (rare) simply use manual upload — the pipeline is identical either way.
- Auto-apply policy setting: `off / security-only / all patch releases` — **product decision on the
  default** (recommend security-only, chosen explicitly at install time; majors always manual).
- The 4C Registry feed can mirror release announcements later; GitHub remains canonical.

◆◆ Review vectors: forged/stripped signature, malicious zip (traversal/bomb — ArchiveGuard), swap
kill-timing on every layout (A/B/C), gate bypass during swap, downgrade abuse, feed-driven supply chain
(shares the Registry threat model), rollback correctness (code back + schema forward is a *supported*
state — the runner's drift logic already handles it).

## 4. Populate — seed import + drip engine (the ForumGen integration)

**Use cases (owner-stated):** populate a dev instance, and generate ongoing activity on the live community
forum. Design consequences: pacing must feel like a real forum, and **everything must be reversible and
fenced** because it can run against production.

**Distribution (owner decision 2026-07-05): NOT in mainstream releases.** Populate ships as a **private
plugin** — separate private repo (`novfora-populate`), signed with the owner's ed25519 key, installed via
the U17 install-from-zip path only on instances the owner personally provides it to. It never appears in
the public Registry feed or the release zip.

**Timing (owner decision 2026-07-05): BUILT FIRST, against the current release.** Populate v1 (E6a engine
+ E6b Studio) is **decoupled from the Admin API** and builds against current `main` (v1.2.x): the plugin
registers its own ACP surface (Livewire SFC, `admin.access` + staff-2FA gated) with **manual plan/genconfig
upload-download** — no E1 dependency. The script↔forum **API round-trip** (`GET /populate/genconfig/{id}`,
`POST /populate/plans`) is a thin later addition once E1's scopes exist. Everything else in this spec keeps
its v1.4 Phase 4E slot.

Consequences of private distribution:

- **Core stays clean:** no Populate tables, columns, or settings in mainstream NovFora. The plugin owns its
  schema (`populate_plans`, `populate_users` provenance map, `populate_events`) via module migrations —
  no `is_simulated` column on core `users`; simulated-account identity lives in the plugin's map table.
- **Core provides only generic seams it already has (or that E1–E3 add):** the Admin API surface, the
  module hook/event system, module migrations, and module-registered cron tasks (the drip runner registers
  as a module scheduled task — infra U17/B1 already supports). If a small generic hook is missing (e.g., an
  outbound-mail suppression filter hook), it lands in core as a *generic* hook, never as Populate logic.
- **The `admin:populate` scope** is registered by the plugin (module-registered capabilities exist);
  absent the plugin, the scope and endpoints simply don't exist.
- The Python ForumGen script stays in the private repo alongside the plugin as the external generator.

### Phase A (this program, v1.4) — plan import + drip scheduler (as the private plugin)

- **Plan format:** versioned superset of ForumGen's `forum_seed.json` — `users[]`, `topics[]`, `posts[]`
  with timestamps, plus optional `pacing` (when future content carries no explicit times) and `options`.
  The Python script keeps working as-is; a small schema doc + validator ships in `docs/api/populate.md`.
- **`POST /populate/plans`** — upload + validate (bounded: entity caps, size caps, no raw SQL/HTML —
  content enters through the canonical→sanitize pipeline; usernames/emails validated; sim email domain
  enforced, e.g. `@sim.invalid`, so no real inbox can ever receive mail).
- **Backdated segment** imports through the ADR-0034 importer infra (batched, resumable, idempotent,
  counters maintained — replacing ForumGen's raw `DB::table()` writes and hand-rolled counter pass).
- **Future segment → `seed_events`** (additive table: plan_id, event type, payload ref, `publish_at`,
  claimed/published state). The **drip runner** (`novfora:populate:tick`, every cron beat) transactionally
  claims due events and publishes each through the real domain services (PostService/TopicService), so
  counters, search indexing, feeds, prefixes, and moderation all behave exactly as if a member posted.
- **Pacing engine** (ForumGen's timeline logic, ported): posts/day target with diurnal curve + weekday
  weighting, reply:new-topic ratio, thread-decay window (replies go to recently-active threads), per-forum
  weights, jitter. Given a plan without timestamps, the engine schedules `publish_at` values itself;
  re-pacing an unpublished plan is allowed.
- **Live-board fences (all default-on):**
  - Every seeded user carries `is_simulated=true` (additive column) + plan provenance; every seeded
    topic/post carries `seed_plan_id`. **`DELETE /populate/plans/{id}` purges everything the plan created**
    — the reversibility guarantee. Purge is co-owner-scope + confirmation token.
  - **Daily cap + kill switch** (`populate.paused` setting, honored at the next tick), pause/resume per plan.
  - **HOLD mode option:** drip into the moderation queue instead of auto-approve (recommended first run on
    the live board).
  - Outbound email/web-push suppressed for simulated users; digests/mention notifications from simulated
    posts to real members ARE allowed (that's the point of warm-up activity) but rate-capped by the
    existing bounded fan-out.
  - **Disclosure option:** per-plan choice to badge simulated accounts ("Community bot" flair via the
    v3-g display-only flair seam) — recommended ON for the live community; owner's call per instance.
  - Stats hygiene: simulated accounts excludable from member-count/Info-Center stats (setting).
- **ACP surface — the Populate Studio (owner request 2026-07-05: granular, feature-rich superset of the
  Python script's config).** Plugins → Populate, four panes:

  1. **Persona Studio** — CRUD persona archetypes (the script's matrix, editable): name · weight ·
     board/forum affinities · tone/system prompt · **length budget** (min/max words, distribution) ·
     reply style (agree/one-liner/detailed/contrarian/off-topic-drift) · posting-hours profile
     (night-owl/9-to-5/weekender) · activity level · join-date era · username/display-name style ·
     avatar option · quirks (lowercase, typo rate, emoji frequency, link-dropping). Ships seeded with
     ForumGen's 10 archetypes; **persona packs import/export** (JSON) for reuse across instances.
  2. **Timeline & Pacing Designer** — backfill window (start/end) + forward horizon · posts/day target
     with **curve presets** (ramp-up, steady, decay, S-curve growth) or custom points · diurnal curve
     editor (night-activity %) · weekday weighting · reply:new-topic ratio · thread-decay window ·
     jitter · per-forum weights/targeting · quiet periods (holidays) · **event spikes** (launch-day
     bursts) · daily caps. **Live preview:** projected posts-per-day sparkline + a sample 48h schedule
     before anything is committed.
  3. **Content Controls** — per-forum topic seeds/themes (starter prompt lists) · banned-topics list ·
     language/locale · markdown richness (lists/links/quotes frequency) · title style · per-persona
     length overrides. Population controls: user count · join-date distribution · name/username
     generator styles · primary group (never staff — enforced, not configurable) · sim email domain.
  4. **Run Console** — plan list + per-plan dashboard (published/pending counts, next events, timeline
     chart), pause/resume, kill switch, HOLD-mode toggle, bot-flair toggle, dry-run, **purge**.

  **Config → generator round-trip (Phase A):** the Studio authors a versioned **genconfig** —
  exportable as JSON for the Python script (`generate_forum.py --config genconfig.json`), or the script
  pulls it and pushes the finished plan back **through the Admin API**
  (`GET /populate/genconfig/{id}` → `POST /populate/plans`), so the loop is scriptable end-to-end.
  **Phase B (native generation) consumes the exact same genconfig** — the Studio investment carries
  over unchanged; only the generator moves in-app.

◆ Review vectors: untrusted plan JSON (bounded parse, no entity floods), drip-runner concurrency/idempotency
(double-claim, mid-kill republish), purge completeness (no orphaned counters/search ghosts), sim-user
escalation (can never hold staff capabilities, can never log in — unusable password hash + `is_simulated`
login refusal).

### Phase B (v1.5, alongside Nova Assist) — native generation (stays in the private plugin)

Persona matrix (ForumGen's archetypes) stored + editable in the ACP; generation runs server-side through the
**same BYO-key provider drivers as Nova Assist** (OpenAI/Anthropic/local Ollama, cost/time budgets, queued);
output lands as a Populate plan → same validator, same drip engine, same fences. Optional **reactive mode**
(simulated users reply to real members' threads) is a separate toggle behind the **content-egress opt-in**
(real posts leave the server only with the ADR-0069-style consent) — and defaults to HOLD mode. The Python
script is thereafter optional, not required.

## 5. Slices (v1.4 Phase 4E — independent branches, suggested order)

| Slice | Deliverable | Rung/flag |
|---|---|---|
| **E1** | Token scopes + auth spine + OpenAPI scaffold + audit/idempotency plumbing | ◆ APEX |
| **E2** | Read surface: settings/structure/members/moderation/health (GET only) | standard |
| **E3** | Write surface: settings PUT, structure CRUD, member/group/moderation ops via existing services | ◆ (inherits guards; review confirms no service bypass) |
| **E4** | Backups: artifact builder, list/download/delete, schedule + retention | ◆ |
| **E5** | Restore: staged upload→verify→dry-run→execute + auto-snapshot + rollback | ◆◆ (the program's apex centerpiece) |
| **E6a** | Populate plugin engine (**private repo, not in mainstream releases — BUILT FIRST, against current `main`, no E1 dependency**): plan import, populate_events, drip runner, pacing engine, fences, purge — all module-owned | ◆ |
| **E6b** | **Populate Studio** (same private plugin, built with E6a): Persona Studio, Timeline & Pacing Designer w/ live preview, Content Controls, Run Console, genconfig export/import (manual now; API round-trip added after E1) | standard (genconfig schema is the Phase-B contract) |
| **E7** | Self-upgrade: core-release signing in `build-release.sh` + `.sig` release assets, staged zip pipeline, layout-aware swap, ACP Updates surface + stepper, **GitHub Releases update channel + version picker**, manual-upload path, rollback | ◆◆ (shares E5's `StagedArtifact` internals — build after E5) |
| **E8** | Docs: OpenAPI publish, `docs/api/admin.md`, `docs/api/populate.md`, `docs/api/upgrade.md`, ForumGen schema note + example plan | Sonnet |

Exit gate (Phase 4E): full suite green Baseline + Enhanced; a scripted end-to-end proof — mint token →
reshape structure → change settings → create backup → restore it onto a fresh install → **self-upgrade
via a mocked GitHub Releases feed AND via manual signed-zip upload, across a simulated version bump →
one-click rollback → re-upgrade** → install the Populate plugin from its signed zip → import a ForumGen
plan → watch 48h of drip on a compressed clock (time-travel test) → purge plan → **uninstall the plugin**
→ board returns to pre-plan state byte-for-byte on counters, core schema untouched by Populate. The
upgrade proof runs on all three RH-4 layouts (A/B/C). Core migrations reversible (token columns,
`upgrade_runs`); Populate tables are module migrations in the private repo.

## 6. Out of scope (this program)

Permission-mask writes over the API (panel-only until its own apex pass) · member-facing API expansion ·
GraphQL (2.0 question) · Populate reactive mode + native generation (v1.5 Phase B) · multi-site
orchestration · delta/patch upgrade packages (full zip only — deltas are a 2.0 optimization question).

---

*Owner decisions 2026-07-02: both-phased generator with real-forum drip pacing (dev + live community
use), full DB+uploads backup, v1.4 Phase 4E placement, self-upgrade-from-zip included (E7).
Owner decisions 2026-07-05: **Populate is a private plugin** (separate private repo, owner-signed,
personally distributed — never in mainstream releases or the public Registry); **the update channel is
GitHub Releases** (one-click Upgrade from `getnovfora/novfora` releases; version picker over tags; manual
zip upload for pinning/air-gap/downgrade — signature verification identical on every path). Open product
decision: the auto-apply default (recommend security-only, chosen at install). ADR numbers: confirm
next-free at lift (roadmap reserved 0109–0116; suggest 0117 program parent, 0118 token scopes, 0119
backup/restore, 0120 Populate seams, 0121 self-upgrade + core-release signing + GitHub channel).*
