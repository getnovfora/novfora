# ACP v3-f — Temporary-access delegation (TTL) — Build Spec

> Handoff spec (ADR-0080 slice **v3-f**). Time-boxed delegation: a co-owner grants an individual a capability
> for a bounded window (≤ 30 days), **auto-expiring**, **ceiling-bounded** (the recipient never exceeds the
> delegator's current mask; **co-owners / Administration-tier keys are never delegable**). It rides the **v3-0
> `expires_at` seam** — the resolver filter + cache cap + prune cron already auto-expire any TTL row — so v3-f
> adds only the provenance record, the bounded write, and the Active Delegations UI. **Apex / Fable @ max**
> (acl_entries + the ceiling invariant is the apex test). Reuse the engine, no parallel eval. Branch off `main`,
> gated + adversarially reviewed, all git on the VPS.

## 1. Goal
Let a co-owner hand someone a specific capability temporarily — e.g. "give Jamie `topic.moderate` in General
Discussion for 7 days" — as a time-boxed `acl_entries` row the resolver auto-expires, listed and revocable in
an **Active Delegations** surface under Security.

## 2. Scope / Non-goals
**In scope:** a `delegations` provenance table; `DelegationService` (apex) — bounded grant + revoke; the Active
Delegations SFC (list + early-revoke) under the co-owner-only Security section; the 30-day cap +
co-owner-not-delegable + ceiling fences; tests.
**Non-goals:** no new eval path (G1); **no new cron and no resolver change** — the v3-0 seam already auto-expires
(resolver `expires_at` filter + cache-TTL cap + `novfora:acl:prune-expired`); no `acl_entries` schema change;
delegating to groups/roles is out of scope (per-user, single-key grants this pass).

## 3. Locked constraints (CLAUDE.md + ADR-0080)
G1/G2/G3/G4; **G6 Fable @ max**; **G9** bump `AclVersion` after the query-builder `acl_entries` delete on revoke;
**G10** — `acl_entries` has no provenance, so the `delegations` table is the source-of-truth that's projected
into one TTL `acl_entries` row (mirror the v3-b `moderator_assignments` / `ForumModeratorProjector` pattern).
Reuse fences: `RoleManager::assertWithinCeiling` (the ceiling), `ActorRank`, the co-owner gate
(`admin.security.access`), staff-2FA `ensureManager`, `MembershipCache::flushFor`. Tests + reversible migration;
small conventional commits; clean-room.

## 4. Files (from the v3-f map)
**New:** migration `…_create_delegations_table.php` — `delegator_id` FK→users, `recipient_id` FK→users,
`permission_key` varchar(150), `scope_type` varchar(16), `scope_id` bigint nullable, `expires_at` timestamp
**NOT NULL**, `revoked_at` timestamp nullable, `created_at`; indexes `(recipient_id, expires_at)` +
`(delegator_id, expires_at)`; reversible · `app/Models/Delegation.php` · `app/Admin/DelegationService.php`
(apex) · `resources/views/admin/security/delegations.blade.php` (**wrapper view WITH `@extends('layouts.app')`**)
· `resources/views/components/admin/security/⚡active-delegations.blade.php` (SFC) · `tests/Feature/Permissions/DelegationTest.php`.
**Edit:** `routes/web.php` (Security group: `security.delegations`) · `app/Admin/AdminNavigation.php` (Security
sub-page `['active_delegations', 'admin.security.delegations', 'clock']`) · `lang/en/admin.php`
(`admin.security.delegations.*`).

## 5. Sequence
1. **Migration + model** (Sonnet) — `delegations` table, additive/reversible; apply+rollback+re-apply.
2. **`DelegationService::grant`** (**Fable @ max**) — `assertActorIsCoOwner` (holds `admin.security.access`); `assertNotCoOwnerKey($key)` (explicitly reject `admin.security.access` and any `Administration`-group key); `assertWithinCeiling([$key => Allow], $delegator, $scope)` (delegator holds it **now** at the target scope); `$expiresAt = min($requested, now()->addDays(30))` (30-day cap); in one transaction: `Delegation::create` + write the time-boxed `acl_entries` row (`holder=user:recipient`, scope, ALLOW, `expires_at`) + audit + `MembershipCache::flushFor($recipient)`. **Adversarial review before commit.**
3. **`DelegationService::revoke`** (**Fable @ max**) — co-owner-gated; one transaction: set `revoked_at` + **key-scoped delete** of the mirrored `acl_entries` row + **`AclVersion::bump`** (G9) + cache flush. Inspector-oracle tests.
4. **Active Delegations SFC + route + nav** (Sonnet for boilerplate; gate mirrors `⚡co-owners`) — `mount()` and **every** action assert co-owner (`admin.security.access`) → 403 else (Livewire bypasses route middleware). List live, non-revoked delegations (delegator, recipient, key *label*, scope *name*, expires-in, created); a Revoke button → `DelegationService::revoke`. Surface the 30-day cap in the create form.
5. **Gates + child ADR (next # after 0086) + PROJECT-STATE/ROADMAP** (v3-f shipped, **next = v3-g**).

## 6. Apex correctness seams (the review must pin)
- **The ceiling invariant (the apex test):** a delegator cannot delegate a key they don't currently hold at the target scope; `admin.security.access` (and any Administration-tier key) is never delegable; the recipient never resolves above the ceiling. Adversarial tests: delegate-what-you-don't-hold → rejected, **zero rows written**; delegate `admin.security.access` → rejected; the 30-day cap clamps a longer request; revoke → recipient `can()` false **and** `AclVersion` bumped.
- **"Current mask" — the one design decision (record it in the child ADR):** the ceiling is checked at **grant time** (delegator holds it now); the delegation then stands for its TTL as a static time-boxed grant. **Open question to resolve:** should a delegator later *losing* the capability (demoted, or a bundle/co-owner revoke) **cascade-revoke** the delegations they granted for that key? **Recommended: yes** — wire `AdminCoOwnerService` / `AdminBundleService` demotion paths to revoke a demoted actor's outstanding delegations for keys they no longer hold, to honor "never exceeds the delegator's *current* mask." If deferred, document the gap explicitly. The adversarial review pins this.
- **Auto-expiry is the seam, not new code:** *verify* (don't rebuild) that the delegated row is filtered by the v3-0 resolver `expires_at` clause, self-expires from cache via the TTL cap, and is swept by `novfora:acl:prune-expired`, with the `delegations` row left as audit history. A test pins the cross-boundary `can()` flip with no prune run.

## 7. Verification / done
Gates green; migrate apply+rollback+re-apply; inspector-oracle across grant / revoke / expiry; the ceiling +
co-owner-non-delegable + 30-day-cap adversarial tests; prune integration. Child ADR (incl. the cascade
decision); PROJECT-STATE + ROADMAP updated.

## 8. Commit
Branch `claude/acp-v3-f` off `main`; small conventional commits per step; `-s`, `Tommy Huynh <tommy@saturnhq.net>`. PR to `main`.

Read docs/product/acp-v3-f-kickoff.md and execute it.
