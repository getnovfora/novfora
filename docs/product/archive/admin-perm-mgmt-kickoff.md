# Branch 1 — Admin & permission management — Build Spec

> Handoff spec (Batch 2026-06-21, Branch 1). Three related fixes in the same admin area, so **one
> branch**: (a) the "can't add admins" **discoverability** fix, (b) a group/role **clone** feature
> (the apex seam — it writes `acl_entries`), (c) member/group management **UX cleanup**. **ultracode —
> start Fable @ max for `clone()` + the `AclVersion` reasoning; downgrade to Sonnet for the link/UX
> scaffolding.** Branch `claude/admin-perm-mgmt` off `main`, gated, git on the VPS.

## 1. Goal
Make admin/group/role management discoverable and faster: an operator can find how to add a full admin,
**clone an existing group or role** instead of rebuilding it by hand, and navigate the groups area
without friction — with the clone provably copying permissions exactly (no silent mis-grant).

## 2. Scope / Non-goals
**In scope:** UI cross-links so "add a full admin" is discoverable from Security; `GroupManager::clone()`
+ `RoleManager::clone()` + their ACP buttons; group-list search/filter; persist the simple/advanced
permission-mode choice; fix the "Members / Groups" breadcrumb; an "Edit role →" shortcut.
**Non-goals:** **no permission-engine change** (`PermissionResolver`/`GroupPermissionEditor`/catalog
untouched — clone reuses the existing primitives); **do NOT rename the `admin.members.groups` route**
(it has many call-sites — out of scope, just fix the visible breadcrumb text); no new admin tier; no
change to the co-owner/backfill machinery (it works — the demo's backfill already crowned the existing
admin).

## 3. Background (from the code — so you don't re-derive it)
- **"Add a full admin" already works** — it's just hidden. A full admin = membership in the `admins`
  group, added via **Groups → Manage** (`/admin/groups/manage`, route `admin.members.groups`,
  `resources/views/components/admin/⚡groups.blade.php`, the Members panel on the admins-group row).
  The Security pages `⚡co-owners.blade.php` and `⚡admin-accounts.blade.php` *tell* the operator to "add
  an administrator via Groups first" but the text is **not a link**. That's the whole bug for (a).
- **Co-owner tier** (Security section, gated on `admin.security.access`) is separate and works; the
  backfill (`2026_06_20_000200`) crowns existing admins. Don't touch it.
- **Groups & ACL model:** `groups` columns include `name, slug, type (system|trust|custom), color,
  description, is_system, priority, auto_promotion (json), membership_model, is_public,
  show_on_staff_page, show_staff_icon, staff_title, tenant_id`. Permissions live **only** in
  `acl_entries` (`permission_key, holder_type ['user'|'group'], holder_id, scope_type
  ['global'|'category'|'forum'|'thread'|'club'], scope_id, value [ALLOW=1, NO=0, NEVER=-1], expires_at,
  tenant_id`), written via `GroupPermissionEditor` and/or expanded from `role_assignments` by
  `RoleExpander`. Group CRUD is `app/Admin/GroupManager.php` (`create()`, `setRole()`); roles are
  `app/Permissions/RoleManager.php` + `⚡roles.blade.php`.

## 4. The three pieces

### (a) Add-admin discoverability — Sonnet, no logic change
- `resources/views/components/admin/security/⚡admin-accounts.blade.php`: turn the existing "add them to
  the Administrators group via **Groups**" text into a real link → `route('admin.members.groups')`.
- `resources/views/components/admin/security/⚡co-owners.blade.php`: make the "Add an administrator via
  Groups first" message (incl. the empty state) a link to the same route.
- `resources/views/components/admin/⚡groups.blade.php`: on the **admins** group row, make the Members
  action obvious (a clear "Add / manage members" affordance, not just an icon).
- No `acl_entries` touch. A small feature test asserting the links render with the right `href` is
  enough.

### (b) Clone group / role — **apex (start Fable @ max)**
Add `GroupManager::clone(Group $source): Group` and `RoleManager::clone(Role $source): Role`, plus ACP
buttons. **The clone must reproduce the source's effective permissions exactly — copying too little
silently *removes* a grant, copying a NEVER as anything else silently *adds* one. This is the load-
bearing element.**

`GroupManager::clone()` — in **one DB transaction**:
1. **New `groups` row.** Copy `color, description, priority, type, auto_promotion, membership_model,
   is_public, show_on_staff_page, show_staff_icon, staff_title`. `name` = source name + " (copy)";
   generate a fresh unique `slug` (reuse the existing slug helper); `is_system = false`.
2. **Role assignment.** If the source has a `role_assignments` row, copy it for the new group and run
   `RoleExpander::assignToGroup($role, $newGroup, ...)` for each assigned scope — **do not hand-copy the
   role-derived `acl_entries`** (let the expander write them so `role_assignments` and `acl_entries`
   stay in sync).
3. **Direct (non-role) `acl_entries`.** Copy every `acl_entries` row with `holder_type='group',
   holder_id=source.id` that is a manual override (not produced by the role expansion), substituting
   `holder_id = newGroup.id`, **across every scope (global + per-forum + per-club)** and **preserving
   `value` exactly (ALLOW/NO/NEVER)**. Carry `expires_at` if present.
4. **`AclVersion` bump (G9).** If you bulk-`insert()` the rows via the query builder, call
   `app(AclVersion::class)->bump()` explicitly afterward (query-builder writes skip the model event). If
   you create them via Eloquent `AclEntry::create()`, the `booted()` event bumps automatically — pick one
   and be consistent.
5. **Cache + audit.** `GroupDirectory::forgetEnabled()` if the clone is `is_public`. Write **one** audit
   entry (`group.cloned`, source id → new id).
6. **Do NOT copy:** `group_user` membership, `is_co_owner`, `is_primary`/`is_primary_locked` — a clone
   starts with **zero members**.

`RoleManager::clone()` — copy the role row (name + " (copy)") and its `role_permissions` rows into a new
role that is **unassigned** (no `role_assignments`). The operator assigns it afterward. (Deliberately
*not* auto-assigning the clone to the source's groups — that would silently grant a second role to live
groups.)

ACP wiring:
- `⚡groups.blade.php`: a `clone(int $id)` action + a "Clone" button beside Edit/Delete, **disabled/
  hidden for `system` and `trust` groups** (only `custom` groups are cloneable).
- `⚡roles.blade.php`: a `cloneRole(int $id)` action + button.
- Reuse the existing manage-permissions gate + rank guard / escalation fence that the card editor uses —
  the actor must already be authorized to grant the keys being copied; do not let clone become an
  escalation path.

### (c) UX cleanup — Sonnet
- `⚡groups.blade.php`: add a **search/filter** input to the group list (client- or wire-filter by
  name); paginate or "show all" the member panel (it currently hard-caps at 50 with no overflow); add an
  "Edit role →" link in the group edit form when a role is selected (→ `route('admin.groups.roles')`).
- `resources/views/components/admin/perm-mode-switch.blade.php`: **persist** the simple/advanced choice
  (cookie or session) so it doesn't reset to simple on every visit.
- `resources/views/admin/groups.blade.php`: breadcrumb "Members / Groups" → "Groups".
- i18n for any new strings under `admin.*` (G8); reuse existing keys where they exist.

## 5. Sequence
1. (a) add-admin links + the admins-row affordance — quick, lands first.
2. (b) `RoleManager::clone()` (simpler — no scope fan-out) + its button + test.
3. (b) `GroupManager::clone()` (the apex core) + its button + test. **Top rung.**
4. (c) UX cleanup.
5. Gates; child ADR in `DECISIONS.md` (next free #) recording the **clone copy-semantics** (exactly what
   is and isn't duplicated, the NEVER-preservation rule, the no-membership rule, the system/trust
   exclusion); PROJECT-STATE line.

## 6. Correctness seams (the clone review pins — apex)
- **Exact copy:** a cloned custom group with a mix of ALLOW/NO/NEVER across **global + a forum scope**
  resolves to **identical** effective permissions as the source for a member placed in it — verified
  through the resolver/inspector, not by row-counting. NEVER stays NEVER.
- **No widening / no escalation:** clone reuses the rank guard + escalation fence; a clone can't grant a
  key the actor couldn't grant directly; cloning is blocked on `system`/`trust` groups.
- **`AclVersion` bumped** so the resolver cache reflects the new group immediately (G9) — test that a
  resolve right after clone sees the copied entries (no stale cache).
- **Membership empty:** the clone has no members, no co-owner flags.
- **One transaction, one audit entry**; a failure rolls the whole clone back (no half-created group with
  partial ACLs).

## 7. Verification / done
All gates green (`pest`/`pint`/`phpstan`); new tests `tests/Feature/Admin/GroupCloneTest.php` +
`RoleCloneTest.php` cover the seams above (incl. system/trust-clone blocked, NEVER preserved, membership
empty, AclVersion bumped, audit written); the add-admin links resolve and render; the UX changes work
and the advanced card editor + simple mode still behave unchanged. Child ADR records the clone semantics.
PR to `main` (do not merge) with the clone copy-rules called out for the adversarial review on the Cowork
side.

## 8. Commit
Branch `claude/admin-perm-mgmt` off `main`; small conventional commits per piece (links → role clone →
group clone → UX → ADR); `-s`, `Tommy Huynh <tommy@saturnhq.net>`; clean-room. PR to `main`.

Read docs/product/admin-perm-mgmt-kickoff.md and execute it.
