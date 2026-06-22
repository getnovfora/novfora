# ACP v3-b — Per-Forum Moderator Assignment — Build Spec

> Handoff spec (ADR-0080 slice **v3-b**). Assign a **user or group** as a moderator of a specific forum
> with a capability bundle (preset, or custom via the v3-d role builder), expanded into `acl_entries` at
> **forum scope** through the one engine. This is a **projector slice** — it mirrors `ClubRoleProjector`
> and adds **zero new evaluation paths**. **Apex / Fable @ max** (G6 — touches `acl_entries` projection +
> the security fences). Code builds on a branch off `main`, gated + adversarially reviewed before commit,
> all git on the VPS.

## 1. Goal
Moderation is global-only today (the `moderators` system group holds the `moderator` preset at global
scope). v3-b adds **per-forum** moderators: an admin assigns a user or group to moderate forum X with a
capability set, written as forum-scoped `acl_entries` rows and resolved by the existing
`PermissionResolver`. Preset bundles for speed, plus a custom path that reuses the v3-d role builder.

## 2. Scope / Non-goals
**In scope:** a `moderator_assignments` source-of-truth table + a `ForumModeratorProjector` (mirrors
`app/Clubs/ClubRoleProjector.php`) that expands/retracts forum-scope `acl_entries`; three seeded preset
bundles (full / content / queue) + the custom path via `RoleExpander::assign(... Scope::forum())`; a
per-forum **Moderators** tab (`admin.forums.moderators`, linked from the structure tree as a 3rd button
beside Inspector + Permissions); a global single-pane overview under the **Moderation** section listing
all assignments by forum; all v3-c/v3-d fences reused; inspector-oracle tests at forum scope.

**Non-goals (do NOT do this pass):** no new evaluation path — read verdicts only through
`PermissionResolver` (G1); **no `acl_entries` schema change** (it already supports `holder_type='user'` +
`scope_type='forum'`); no per-user "Moderation tab" on the member-edit screen (spec §4 lists it — **defer,
note as follow-up**); do not touch the global `moderators` group or the `moderator` system preset; no
category scope (deferred per ADR-0080).

## 3. Locked constraints (CLAUDE.md + ADR-0080 guardrails)
- **G1** everything stores as / expands into `acl_entries` and resolves through the single resolver — no parallel moderation eval.
- **G2** forum scope (global / forum / club throughout). **G3** additive, reversible migration (`moderator_assignments`; `down()` drops it cleanly).
- **G4** `PermissionInspector` is the correctness oracle for every write-path test.
- **G9** the projector's query-builder `acl_entries` deletes skip model events → bump `AclVersion` explicitly once per op.
- **G10** `acl_entries` has **no provenance column** — a `(holder,key,scope)` row is shared. The projector must be the **sole** manager of a moderator's forum-scope capability rows, and use **key-scoped deletes only** (never wipe a forum's whole acl set).
- Reuse the fences — `RoleManager::assertWithinCeiling()` (ceiling + admin-tier), `ActorRank` (rank), the `ensureManager()` staff-2FA gate, the recovery-key guard — **do not reinvent**.
- Tests ship with the feature; migrate tested apply+rollback+re-apply; small conventional commits, `-s`, `Tommy Huynh`. Clean-room.

## 4. Files to touch
**New:**
- `database/migrations/<ts>_create_moderator_assignments_table.php` — `id, holder_type(user|group), holder_id, forum_id FK→forums, role_id FK→roles NULLABLE, bundle string NULLABLE, timestamps`. Reversible. (`role_id` null → seeded `bundle` name; non-null → a custom role.)
- `app/Models/ModeratorAssignment.php`
- `app/Permissions/ForumModeratorProjector.php` — `assign()` / `revoke()`; **mirror `app/Clubs/ClubRoleProjector.php`**.
- `database/seeders/ModeratorBundleSeeder.php` — `forum-mod-full` / `forum-mod-content` / `forum-mod-queue` as `is_preset` roles, **NOT** expanded onto any group at global scope (only the projector expands them, at forum scope).
- `app/Http/Controllers/Admin/ForumModeratorsController.php`
- `resources/views/components/admin/⚡forum-moderators.blade.php` (per-forum tab SFC)
- `resources/views/components/admin/⚡moderators.blade.php` (global single-pane SFC)
- `tests/Feature/Permissions/ForumModeratorAssignmentTest.php` (+ projector/SFC tests)

**Edit:**
- `routes/web.php` — `admin.forums.moderators` (mirror `admin.forums.permissions`) + the global pane route under `admin.moderation.*`.
- `resources/views/components/admin/⚡structure.blade.php` (≈ 217-221 / 334-341) — a 3rd per-forum button "Moderators".
- `app/Admin/AdminNavigation.php` — register the surfaces in the Forums + Moderation sections.
- `database/seeders/DatabaseSeeder.php` — call `ModeratorBundleSeeder`.

**Capability keys** (the Moderation cluster, from `PermissionCatalogSeeder`): `post.edit.any`, `post.delete.any`, `post.history.view`, `topic.moderate` (all `scope_kind=forum`), and `bans.manage` (`global` — include in the "full" bundle but it resolves at global, flag this in the bundle definition).

## 5. Sequence (each step ends green; cap gate output)
1. **Migration + model** (Sonnet) — `moderator_assignments`, additive + reversible; migrate apply+rollback+re-apply.
2. **Preset bundles** (Sonnet) — seed full/content/queue as `is_preset` roles; assert keys correct + that they're on no group at global scope.
3. **`ForumModeratorProjector`** (**Fable @ max** — projection + fences). `assign()`: ceiling-check every key against the actor at forum scope; rank guard; refuse admin-tier keys / `admin.access`; **key-scoped clear** of prior rows; expand via `RoleExpander::assign($roleOrBundle, $holderType, $holderId, Scope::forum($forumId))`; write the `moderator_assignments` row; **bump `AclVersion`**. `revoke()`: key-scoped delete + drop the row + bump `AclVersion`. Inspector-oracle tests (the 7 cases below). **Adversarial verify-then-refute review before commit.**
4. **Per-forum Moderators tab** (Sonnet for view boilerplate) — SFC + route + the structure-tree button. `ensureManager()` = `admin.access` + `permissions.manage` + staff-2FA; rank guard; preset/custom picker (custom routes to the existing `⚡roles`).
5. **Global single-pane** (Sonnet) — SFC + route under Moderation; list assignments by forum, add/remove.
6. **Full gates + the inspector oracle; child ADR + PROJECT-STATE/roadmap update.**

## 6. Verification / done criteria
Gates green: `./vendor/bin/pest` · `pint --test` · `phpstan` · migrate apply+rollback+re-apply. Inspector-oracle (G4) at forum scope:
1. a forum-scoped **user** grant resolves `user_allow` for `topic.moderate` on that forum;
2. the same user is **denied on a different forum** (scope isolation);
3. a **group** forum grant resolves `group_allow`;
4. a preset bundle expands **exactly** its keys at forum scope;
5. **revoke** deletes the rows + flips the verdict to denied (+ `AclVersion` bumped);
6. **rank guard** refuses assigning a moderator ranked ≥ the acting admin;
7. **ceiling** refuses granting a key the actor doesn't hold, and `admin.access` can't be granted as a mod capability.

The pre-v3 suite stays byte-identical for global moderation (no regression to the `moderators` group).

## 7. Commit
Branch `claude/acp-v3-b-moderators` off `main`. Small conventional commits per step, `-s`, authored `Tommy Huynh <tommy@saturnhq.net>`, no AI trailers. PR to `main` when green. Draft the child ADR (next free number after 0084) for v3-b and lift it into `DECISIONS.md`; update `PROJECT-STATE.md` + `ROADMAP.md` ("v3-b shipped, next = v3-a").

Read docs/product/acp-v3-b-moderators-kickoff.md and execute it.
