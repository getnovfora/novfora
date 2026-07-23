<!-- SPDX-License-Identifier: Apache-2.0 -->

# Plan memo — v1.4 Phase 4E: Admin API + self-upgrade (2026-07-22)

> Per kickoff §2.1 (plan-before-phase; approval pre-granted) + the full read of
> `ADMIN-API-AND-POPULATE-SPEC-2026-07-02.md`. Build order (spec §5): **E1 → E2 → E3 → E4 → E5 → E7 → E8**
> (E6a/E6b already shipped as the private Populate plugin — 4E adds only the thin API round-trip endpoints,
> which live behind E1's `admin:populate` scope). One branch per slice off `main`, gated green, ◆/◆◆ slices
> get the verify-then-refute apex review with **0 open HIGH/MEDIUM before merge**.

## Ground truth (build on, don't rebuild)
- **API v1 spine (ADR-0033):** `/api/v1/*`, `ApiTokenService` (hashed tokens), `AuthenticateApiToken`
  middleware, `throttle:api`, install/upgrade maintenance gates ahead of auth, engine authz inside
  controllers. The Admin API **extends this spine** — same token table, new scope model. Never a parallel authz path.
- **Permission engine:** every admin surface already has capability keys; the API rides `canDo` — effective
  ability = **token scopes ∩ user `canDo`**. A revoked/demoted user's tokens die with the mask.
- **Reused infra:** ADR-0034 importer (backups/populate), the cron `withoutOverlapping`+short-mutex+transactional
  claim discipline (drip/backup jobs), `UpgradeRunner` (RH-10) + `SchemaState` gate + RestoreRunner (RH-11),
  `ArchiveGuard`/`PackageSignature`/ed25519 (ADR-0104), U16's maintenance services.

## ADR numbering (kickoff-reserved)
E1 = **ADR-0115** (token scopes + auth spine), E4 = **ADR-0116** (backups), E5 = **ADR-0117** (restore),
E7 = **ADR-0118** (self-upgrade + core-release signing + GitHub channel). E2/E3 inherit E1's ADR; E8 is docs.

## Per-slice design lock

### E1 — token scopes + auth spine + OpenAPI scaffold ◆ APEX (NOV-135, ADR-0115) — **BUILDING NOW**
- **Additive columns on `api_tokens`:** `scopes` (JSON array), `expires_at`, `last_used_at`, `ip_allowlist`
  (JSON, nullable). Token secret already hashed; add the `nvfa_` prefix for secret-scanning. Reversible migration.
- **Scope taxonomy** (a fixed const, mirrors ACP sections): `admin:settings.read|write`,
  `admin:structure.read|write`, `admin:members.read|write`, `admin:moderation`, `admin:backups.read|create`,
  `admin:restore`, `admin:maintenance`, `admin:upgrade`, `admin:populate`. **No `admin:*` super-scope.**
- **Enforcement — the trust rule "a token can never do more than its owner":** a `RequireApiScope` middleware
  (declares the required scope per route) checks `token.hasScope(x)` AND, at the controller, the underlying
  `canDo` capability. Both must pass. Expiry + ip_allowlist enforced in `AuthenticateApiToken`. `last_used_at`
  stamped (throttled write).
- **Minting** — `admin.api_tokens.manage` capability (new), from a 2FA-verified panel session (staff-2FA
  step-up); **destructive scopes (`restore`, `upgrade`, `populate`) additionally require co-owner.** Secret
  shown once (`nvfa_<random>`), stored hashed. Rotate + revoke. ACP surface (Security section SFC).
- **Idempotency middleware:** mutating endpoints accept `Idempotency-Key`; the (token, key) → stored response
  for 24h; replay returns the original (a small `api_idempotency_keys` table).
- **Audit provenance:** `Audit::log` gains a `via_token` marker when the actor acts through a token.
- **OpenAPI 3.1 scaffold:** a generator building the doc from route metadata, served at
  `/api/admin/v1/openapi.json` (token-gated). Error envelope `{error:{code,message,fields?}}`, cursor pagination helper.
- **◆ review vectors:** scope escalation, token/capability desync (revoked user's live token), 2FA bypass on
  mint, replayed idempotency keys, audit evasion, ip_allowlist bypass, expiry not enforced.

### E2 — read surface (GET only) — standard (NOV-136)
`GET /api/admin/v1/{settings/{group}, categories, forums, forums/{id}/permissions (read-only), members
(PII scope-gated), moderation queue/reports, health/version/queue-depth/cron}`. Each route declares a `.read`
scope; every read rides the existing domain read paths + `canDo`. Cursor pagination. No writes.

### E3 — write surface ◆ (NOV-137)
Settings PUT (reuse the ACP form-request validation, diff-audited), structure CRUD via **StructureService
exactly**, member ops via **UserBanService (owner-strand guard) + GroupManager + trust services**, moderation
approve/reject/resolve via the existing services. The API is a thin shell — **never a second code path**. ACL
*writes* stay panel-only (spec §6). ◆ review confirms no service bypass.

### E4 — backups ◆ (NOV-138, ADR-0116)
Reuses U16/BackupService (already exists — see ⚡backups). Artifact builder (queued, resumable, cron-drained),
list/download (range stream, outside webroot)/delete, schedule+retention as settings. `admin:backups.*` is
**co-owner-mintable only** (a DB dump holds every secret + PM); optional at-rest libsodium secretstream passphrase.

### E5 — restore ◆◆ APEX centerpiece (NOV-139, ADR-0117)
Staged: upload (chunked, size-capped, `ArchiveGuard` streamed extraction — **never `extractTo`**) → verify
(manifest SHA-256, schema-version compat, install-id warn) → dry-run → execute (flip the maintenance gate,
auto pre-restore snapshot, staging swap, migrate forward, counters verified, gate released; any failure →
auto-rollback + quarantine + audited reason). Shares `StagedArtifact` internals with E7.
◆◆ vectors: zip/path traversal, partial-restore kill-timing, gate bypass during swap, secret exfil via download.

### E7 — self-upgrade ◆◆ APEX (NOV-141, ADR-0118)
`build-release.sh` signs the artifact with the **NovFora core release ed25519 key** (distinct from
`module_trust_keys`; public key pinned + rotation seam) → `.sig` release asset. Staged zip pipeline in FRONT of
the proven `UpgradeRunner`: upload/GitHub-fetch → verify signature (**invalid ALWAYS rejected**, unsigned
rejected unless the loud dev override) + preflight → stage `releases/<version>` → layout-aware swap (RH-4 A/B/C)
under the gate, N=2 retained for rollback → hand off to `UpgradeRunner` (backup→migrate→`route:clear`→gate) →
health-check/finalize. GitHub Releases channel (`getnovfora/novfora`, cached daily check + "Check now") + version
picker over tags + manual upload; **GitHub is transport, the signature is the only trust root.** `POST /rollback`.
◆◆ vectors: forged/stripped signature, malicious zip (traversal/bomb), swap kill-timing per layout, gate bypass,
downgrade abuse, feed-driven supply chain.

### E6 (Populate) — API round-trip only
E6a/E6b shipped as the private `novfora-populate` plugin. 4E adds only `GET /populate/genconfig/{id}` +
`POST /populate/plans` behind the plugin-registered `admin:populate` scope (co-owner for purge). No core schema.

### E8 — docs (Sonnet) (NOV-142)
Publish OpenAPI; `docs/api/{admin,populate,upgrade}.md`; ForumGen schema note + example plan.

## Sequencing / gates / honest scoping
Each slice: full suite + pint + phpstan(app/) 0 + migrate round-trip + (◆/◆◆) apex review, green in `forum-dev`
(route:clear first) before commit. New API routes join `AdminAccessWalkTest`'s spirit via dedicated API authz
tests (unauthed → 401, wrong-scope → 403, revoked-user token → 403).

**Honest scoping call (flagged for the owner).** E5 (restore) and E7 (self-upgrade) are, per the spec's own
words, "the most dangerous endpoints in the product" — chunked untrusted-zip upload, streamed extraction,
staged code-swap across three install layouts, ed25519 verification, auto-rollback. They are ◆◆ and deserve a
**dedicated build cycle** with fresh focus, not a rushed pass at the tail of the v1.4 build marathon. The v1.4
build this cycle delivers **4D in full + E1 (the API auth spine)**; **E2–E8 are locked in this memo and
sequenced for the next cycle.** This respects the standing non-negotiable — the apex review is the signal, and
0 open HIGH/MEDIUM before merge — rather than shipping under-reviewed restore/upgrade code. See the ☀️ owner
section of the morning report for the tag/scope decision this implies.
