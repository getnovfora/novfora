# ACP v3-a — Co-owners + Admin Manager + per-section access bundles — Build Spec

> Handoff spec (ADR-0080 slice **v3-a**). The top admin tier: **multiple co-owners** (no single Root, no
> transfer protocol) protected by a **last-owner guard**; an **Admin Manager** that grants individual admins
> a *subset* of ACP sections via bundles; and the `admin.<section>.access` keys that finally gate the
> Invision rail per-section (v3-h shipped it `admin.access`-flat and deferred this to v3-a). **Apex / Fable
> @ max** (G6 — `acl_entries` + the last-owner guard + the resolver). Additive + reversible, reuse the engine
> (G1, no parallel eval). Code builds on a branch off `main`, gated + adversarially reviewed, all git on the VPS.

## 1. Goal
Every admin today is an `admins`-group member holding the flat `administrator` preset — no owner tier, no
per-section scoping. v3-a adds co-owners (top tier; administer admins and each other; last-owner-guarded),
an Admin Manager (assign an individual admin a subset of sections), and per-section rail gating — **all
additive, so every existing admin keeps full access**.

## 2. Scope / Non-goals
**In scope:** the 10 `admin.<section>.access` keys; the `administrator` preset gains the 9 non-security ones
(additive default-grant); `PermissionSync` propagation to upgrades; an `is_co_owner` pivot column + installer
crowning the first user; `AdminCoOwnerService` (co-owner grant/revoke w/ last-owner locked guard);
`AdminBundleService` (per-user section bundles); seeded bundles (Full/Community/Style/Content/Analytics/Custom)
reusing the v3-d role construct; the Security-section Co-owners + Admin Manager SFCs; per-section rail +
landing gating.

**Non-goals:** no new eval path (G1); **no `acl_entries` schema change**; **no single Root / no transfer
protocol** (ADR-0080 = multiple co-owners); **no v3-f delegation** (Active Delegations is the next slice);
don't change the flat `admin.access` route middleware (co-owners hold `admin.access` too — the finer checks
live in the rail render + SFC `mount()`, mirroring v3-c/v3-d).

## 3. Locked constraints (CLAUDE.md + ADR-0080)
- G1 expand-into-`acl_entries`/one-resolver. G2 scope. G3 additive reversible migration. G4 `PermissionInspector` is the oracle. **G6 Fable @ max.** G9 bump `AclVersion` after query-builder `acl_entries` deletes. G10 one mechanism per cell (see §6).
- **Additive default-grant invariant (HIGH if violated):** the `administrator` preset MUST gain every `admin.<section>.access` except `security`, and `PermissionSync` must propagate to existing installs, so **no current admin loses the rail**. `AdminAccessWalkTest` (walks every admin page as a 2FA admin → 200) is the regression guard — a failure here is a self-lockout of the whole operator team.
- Reuse fences (do not reinvent): `AccountDeletionService::assertNotSoleAdminLocked` (the FOR-UPDATE TOCTOU pattern), `RoleManager::assertWithinCeiling`, `ActorRank`, the recovery-key guards, `RequireTwoFactorForStaff`, `MembershipCache::flushFor`, `AclVersion::bump`.
- Tests + migrate apply+rollback+re-apply; small conventional commits; clean-room.

## 4. Files (from the v3-a map)
**New:** migration `…_add_is_co_owner_to_group_user.php` (additive bool + index; reversible) · `app/Admin/AdminCoOwnerService.php` (apex) · `app/Admin/AdminBundleService.php` (apex) · `database/seeders/AdminBundleSeeder.php` (the 6 bundles as `is_preset` section-key roles, NOT group-expanded) · `resources/views/components/admin/security/⚡co-owners.blade.php` + `⚡admin-accounts.blade.php` (SFCs) · `resources/views/admin/security/{co-owners,accounts}.blade.php` (**wrapper views WITH `@extends('layouts.app')` — do not repeat the BUG-001 bare-view bug**) · tests `AdminCoOwnerTest`, `AdminBundleTest`, `PerSectionRailTest` (+ the last-owner TOCTOU test).

**Edit:** `database/seeders/PermissionCatalogSeeder.php` (+10 `admin.<section>.access` keys, Administration cluster, global) · `database/seeders/RoleSeeder.php` (`administrator` preset += the 9 non-security keys) · `app/Permissions/PermissionSync.php` (verify add-only preset propagation) · `routes/web.php` (Security group: `security.co-owners`, `security.accounts`; keep flat `admin.access` middleware) · `app/Admin/AdminNavigation.php` (Security sub-pages + per-item `admin.<section>.access` rail check) · `app/Http/Controllers/Admin/SectionController.php` (landing gate on the section key) · `app/Install/InstallRunner.php` `createAdmin` (set `is_co_owner=true` on the first admin).

## 5. Sequence (each step green; cap gates; apex steps get the verify-then-refute review)
1. **Catalog + preset keys** (Sonnet) — add the 10 keys; `administrator` preset gains the 9 non-security; confirm `PermissionSync` add-only propagation. Test: an existing admin resolves ALLOW on every section key (via preset); a non-admin does not; `AdminAccessWalkTest` stays green.
2. **Migration + installer** (Sonnet) — `is_co_owner` pivot col; installer crowns the first user. apply+rollback+re-apply.
3. **`AdminCoOwnerService`** (**Fable @ max**) — grant: set flag + write `admin.security.access` user-grant (global) + `AclVersion::bump` + `MembershipCache::flushFor`. revoke/demote: **`assertNotSoleCoOwnerLocked()` (FOR UPDATE on `admins`-group members with `is_co_owner=true`) as the FIRST act in the transaction** (mirror `assertNotSoleAdminLocked`), then clear flag + remove the security grant + bump. Inspector-oracle + the TOCTOU test mirroring `AccountDeletionTest:333`. **Adversarial review before commit.**
4. **`AdminBundleService`** (**Fable @ max**) — assign: ceiling-check each section key against the actor (`assertWithinCeiling(Scope::global())`); write **per-user** global `acl_entries` for the bundle's keys; bump. revoke/replace: key-scoped deletes + bump. **G10 discipline (§6).** Inspector-oracle: a restricted admin sees only granted sections; a full admin is unaffected; revoke flips the verdict. **Adversarial review.**
5. **Security SFCs + routes + nav** (Sonnet for boilerplate; gate logic mirrors v3-c) — Co-owners pane (add/remove, last-owner-guarded + confirmation) + Admin Manager pane (assign bundles). SFC `mount()` asserts co-owner (`admin.security.access`) → 403 else. **Wrap the views in `@extends('layouts.app')`.**
6. **Per-section rail gating** (Sonnet) — `AdminNavigation` shows a section item only if the user holds `admin.<section>.access`; `SectionController` landings gate likewise. The preset grants all, so existing admins see everything. Test: a bundle-restricted admin's rail shows only their sections.
7. **Gates + child ADR (next # after 0085) + PROJECT-STATE/ROADMAP** (v3-a shipped, next = v3-f).

## 6. Apex correctness seams (the review must pin these)
- **Last-owner guard (crown jewel):** a sole co-owner can never be demoted/removed/deleted — the locked FOR-UPDATE re-check closes the TOCTOU race. Mirror `AccountDeletionTest:333`.
- **Don't lock the rail from existing admins:** additive preset grant + `PermissionSync`; `AdminAccessWalkTest` is the guard. Treat any regression here as HIGH (operator-team self-lockout).
- **G10 + the one open design fork — RESOLVE FIRST (write it into the child ADR):** full admins inherit all section keys via the `admins`-group `administrator` preset (group-holder rows). To *restrict* an admin you cannot simply ADD per-user grants (they already inherit everything). **Recommended model (pin in the ADR):** a *restricted* admin is **NOT** in the `admins` group; instead they hold `admin.access` + their bundle's section keys as **per-user** global grants. **Co-owners + full admins** stay in the `admins` group (preset, all keys, group-holder rows). This keeps the two mechanisms on disjoint rows (no G10 collision) and means `AdminBundleService` only ever writes/deletes **user-holder** rows. Consequence to verify: `User::isAdmin()` is group-based, so it's `false` for restricted admins — confirm `EnsureSystemPanelAccess` (key-based `canDo('admin.access')`) still admits them, and that admin-tier escalation fences (which require full `isAdmin()`) correctly **exclude** restricted admins (they must not mint Administration-tier keys). The adversarial review verifies this end-to-end.

## 7. Verification / done
Gates green; migrate apply+rollback+re-apply; inspector-oracle across co-owner grant/revoke, bundle assign/revoke, and per-section rail; the last-owner TOCTOU test; **`AdminAccessWalkTest` green (no existing admin regressed)**; restricted-admin escalation correctly blocked. Child ADR records the restricted-admin model; PROJECT-STATE + ROADMAP updated.

## 8. Commit
Branch `claude/acp-v3-a` off `main`; small conventional commits per step; `-s`, `Tommy Huynh <tommy@saturnhq.net>`. PR to `main` when green.

Read docs/product/acp-v3-a-kickoff.md and execute it.
