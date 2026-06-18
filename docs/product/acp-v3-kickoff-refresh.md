<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# ACP v3 — Refreshed Kickoff + ADR Draft (reconcile → plan)

> **Status:** Planning draft for owner review (prepared in Cowork; no code). **Supersedes the framing of**
> [`acp-v3-spec.md`](acp-v3-spec.md) (the 14-section design, compiled 2026-06-10) by re-grounding it against
> the **current** build — Phases 1–5 + RH-4 are done, the permission engine and inspector are built, Clubs
> added a club scope, and ADR-0025 resolved the spec's account-deletion open question. **Build nothing until
> this is approved and the per-slice ADRs are locked** (CLAUDE.md Stage-B rule: plan-before-code per phase).
>
> **Decision recorded:** owner chose the **whole ACP v3** scope. Deliverable cadence below is per-slice so it
> stays reviewable; the nav restructure is decoupled so it never blocks the permission features.

---

## 0. Why a refresh (not just "implement the spec")

The spec is sound but predates a lot of shipped work. Implementing it verbatim would re-build things that
exist and would miss the club scope entirely. This doc reconciles it, fixes the architectural guardrails to the
**current** engine, and re-orders the slices by real dependency.

### Reconciliation — spec section → current state

| # | Spec section | Current state | Net-new for v3 |
|---|---|---|---|
| 1 | ACP nav & layout (icon rail · section sidebar · per-section dashboards · global ACP search · +Add) | Flat route-per-page inside `<x-admin.shell>`; no icon rail/dashboards/ACP search | **NET-NEW** (v3-h) — decouple; biggest UX slice |
| 2 | Root / Founder admin + transfer protocol | Installer crowns a **capable first admin + lock** (FreshInstallSmokeTest); no formal Root role or transfer rails | **PARTIAL** — formal Root role + 4-rail transfer = new |
| 3 | Admin permission system (bundles · Admin Manager) | None (admin = `admin.access` via the engine, all-or-nothing) | **NET-NEW** — needs an ACP-section permission catalog |
| 4 | Moderator system (global group ✓ · per-forum scoped assignment · 3 surfaces · per-assignment capabilities) | Global `moderators` group exists; engine resolves a `forum` scope; **no assignment UI**, no per-assignment capability set | **PARTIAL** — assignment UI + `moderator_assignments` + capabilities. Precedent: `ClubRoleProjector` |
| 5 | Permission UI (card-per-group · plain-language Yes/No/Never · inheritance) | Engine fully supports it (`acl_entries` + resolver); **no editor UI** — admins shape perms via seeded presets | **NET-NEW** — *the headline "simple mode."* Add club scope; settle the category-scope question (§4 below) |
| 6 | Group system (system groups ✓ · custom groups · membership models · auto-promotion AND/OR) | Groups manager (ACP v2) + custom groups + TL auto-promotion by reputation (Stage A A3) | **PARTIAL** — request/approval + open-join models + a criteria builder |
| 7 | Custom role builder | None | **NET-NEW** — builds on `RoleExpander` |
| 8 | Staff flair (colour ✓ · title · badge · `/staff` roster) | Group colour shipped | **PARTIAL** — title/badge/roster |
| 9 | Delegation (task assignment · temporary access, ceiling-bound) | None | **NET-NEW** — apex (ceiling + expiry) |
| 10 | Post approval queue (inline · mod panel · ACP widget) | Moderation queue + `approved_state` exist | **PARTIAL** — surface it in the three places |
| 11 | Content lifecycle on account events | **DONE** — ADR-0025 (`[Deleted]` de-id, owner-confirmable cascade) | resolved |
| 12 | Unified audit log (role-scoped visibility) | Audit log exists (Admin → System → Audit) | **PARTIAL** — add the role-scoped visibility tiers |
| 13 | Open questions | Account-deletion ADR **resolved** (ADR-0025); others still open (§7) | — |
| 14 | Implementation notes (routing/tables/prereqs) | Predates the Fable rung; says Opus `xhigh` | **UPDATE** — apex is now **Fable @ max**; `audit_log` already exists; add club scope |

**Net:** the engine + inspector + audit + account-lifecycle + most "supporting" features already exist. v3's real
new surface is the **management UX** + **admin hierarchy** + **delegation** + **custom roles** + **nav restructure**.

---

## 1. The non-negotiable guardrails (read before designing any slice)

- **G1 — One engine, always.** Every new construct (custom roles, moderator assignments, admin bundles)
  **expands into `acl_entries`** and is read back through `PermissionResolver` — the exact `RoleExpander` /
  `ClubRoleProjector` pattern. **Never** add a parallel evaluation path. The resolver + `PermissionInspector`
  stay the single source of truth.
- **G2 — Club scope is first-class.** The spec predates Clubs. The card editor, moderator assignment, and
  custom roles must each support **global / forum / club** scope (Phase 4 added the `club` scope +
  `ClubRoleProjector` — reuse it).
- **G3 — Additive, reversible migrations.** New tables only; no destructive change to the `acl_entries` schema.
  `permissions:sync` stays additive (ADR-0036). Upgrades never need manual DB surgery.
- **G4 — The inspector is the test oracle.** Every write-path slice proves correctness by resolving through
  `PermissionInspector` (the same path the truth-table suite uses) — never against a re-implementation.
- **G5 — Security fences are apex.** Delegation can **never** exceed the delegator's ceiling; Root cannot be
  delegated (only transferred, all four rails in sequence); the inspector stays Root/Admin-Manager-gated; admin
  bundles gate ACP sections via real permission keys (`admin.<section>.access`), not view-only hiding.
- **G6 — Routing.** Any slice that writes `acl_entries` / touches the resolver / the delegation ceiling / the
  Root-transfer state machine = **Fable @ max** (apex). UI-only surfaces and flair = Sonnet. (CLAUDE.md routing;
  the spec's "Opus xhigh" predates the Fable rung.)
- **G7 — i18n from day one.** The full-string-sweep session is in flight; build every v3 view with `__()`
  (`admin.*`) keys from the start so it never becomes i18n residue. The i18n residue list's **"ACP admin"** surface
  **folds into v3** — don't sweep the old admin views separately; the new/restructured v3 views ship `admin.*`-keyed.
- **G8 — lang-group vs string-key case-collision (HARD constraint, learned ADR-0079).** On the case-insensitive
  bind-mount, a `lang/en/<group>.php` whose group name **case-collides** with any bare `__('<Word>')` string-key
  makes Laravel load the **group array**, then `htmlspecialchars(array)` → **500** (this bit the i18n session:
  `members.php` collided with `__('Members')` and was folded into `common.*`). For ACP v3: keep everything under a
  single **`admin.*`** namespace (+ shared `common.*`), and **never** add a group file whose name matches a bare
  string-key in use. Verify collision-safety before adding any new group.
- **G9 — query-builder writes to `acl_entries` must bump `AclVersion` by hand (HARD constraint, learned v3-0/v3-c).**
  A query-builder `delete()` / `update()` (and any raw/bulk write) **skips Eloquent model events**, so the
  cache-invalidation hook never fires and the resolver serves **stale** grants. Every non-Eloquent write or delete
  on `acl_entries` — the prune cron, the editor's "No" (delete) path, and the upcoming delegation prune /
  moderator + co-owner removals (v3-f / v3-b / v3-a) — must call the `AclVersion` bump **explicitly**.
- **G10 — `acl_entries` has NO provenance column (no `role_id`); a `(holder, key, scope)` cell is ONE shared row
  (HARD constraint, learned v3-d/ADR-0084).** Whatever sets a cell — a role baseline, the v3-c card editor, or a
  future moderator/bundle projector — writes the **same physical row**. So a given cell must be managed by **one**
  mechanism, not two: removing a role removes the shared row even if the card editor also "set" it. v3-b (mod
  capabilities) and v3-a (bundles) must design around this — use distinct holders/keys/scopes, or treat the row
  as deliberately shared; never assume a mechanism privately "owns" a cell.

---

## 2. Proposed data model (confirm at plan time)

| Table | Purpose | How it resolves |
|---|---|---|
| `custom_roles` (+ `custom_role_permissions`) | reusable three-state bundles | **expand via `RoleExpander`** into `acl_entries` on assign |
| `moderator_assignments` | user/group × forum/club × capability set | **project into `acl_entries`** (new projector, mirror `ClubRoleProjector`) |
| `admin_permissions` *(or reuse `acl_entries`)* | admin account → ACP-section access | **prefer `acl_entries`** with `admin.<section>.access` keys at global scope (one eval path) |
| `acl_entries.expires_at` (new **nullable** column) | enables TTL grants | the resolver filters `expires_at IS NULL OR > now()`; a cron sweep hard-deletes expired rows |
| `staff_delegations` | delegator → recipient, scope, **expiry**, note | **audit/revoke record**; the grant itself lives as time-limited `acl_entries` (DECIDED: TTL on `acl_entries`) |
| co-owner marker | top-level power (multiple) | a flag/tier on the administrators membership; **last-owner guard** keeps ≥ 1 — **no transfer state machine** (co-owners add/remove each other) |
| `audit_log` | already exists | **extend** with role-scoped visibility tiers (§ spec 12) |

**Decided design:** delegation is a **TTL on `acl_entries`** — additive `expires_at`, honoured by the resolver,
auto-expiring, ceiling-bounded (recipient never exceeds delegator). The `staff_delegations` row exists only for
audit + manual revoke. The **ceiling check** and the **expiry filter** are the apex invariants and get dedicated
adversarial + truth-table tests. The top-level admin tier is **multiple co-owners** (no Root/transfer machine),
protected by a last-owner guard.

---

## 3. Re-grounded slice plan (whole v3, dependency-ordered)

Keeps the spec's v3-a…v3-h labels but **re-orders by real dependency** and flags prerequisites + routing. Each
slice is its own gated commit set + its own ADR.

| Order | Slice | Prereq | Routing | Notes |
|---|---|---|---|---|
| **1** | **v3-0 Foundations** — approve this doc; lock the section taxonomy + guardrails; **land the additive `acl_entries.expires_at` + resolver expiry filter** so delegation rides the one engine | — | plan + **apex** (the expires_at seam) | The only foundational engine change; everything else is additive surface |
| **2** | **v3-c Permission UI** — card-per-group, plain-language Yes/No/Never, global+forum+club inheritance shown | engine only | **apex** (writes `acl_entries`) | *The headline.* Highest value, most self-contained |
| **3** | **v3-e Group system** — membership models (admin / request+approval / open-join), **AND/OR auto-promotion builder** (promotion-only) | v2 groups | mixed | Makes custom groups meaningful in the card editor; public Groups page default OFF |
| **4** | **v3-d Custom role builder** — clusters → role → expand | `RoleExpander` | **apex** | Feeds both mod capabilities and admin bundles |
| **5** | **v3-b Moderator assignment** — 3 surfaces + **preset capability bundles + custom** | v3-d (custom path) | **apex** | `moderator_assignments` → projector; bundles seeded, custom reuses v3-d |
| **6** | **v3-a Admin hierarchy** — **co-owners (multiple)** + Admin Manager + bundles | v3-d | **apex** | `admin.<section>.access` catalog + **last-owner guard** (no transfer machine) |
| **7** | **v3-f Delegation** — task/queue assignment + **temporary access (TTL on `acl_entries`)** | v3-0 (expires_at) + v3-a | **apex** | ceiling-bounded; 30-day cap shown; cron expiry sweep |
| **8** | **v3-g Staff flair + roster** — title/badge/`/staff` (colour ✓) | — | Sonnet | Independent; can slot anytime |
| **9** | **v3-h ACP nav restructure** — icon rail + section sidebar + per-section dashboards + ACP search | v3-0 (section taxonomy) | Sonnet/Opus | **In this cycle** (owner decision). Independent of 2–7; land the shell + section IA **early** so feature pages slot into their final home |

Slices 2–7 are the permission program; 8–9 are independent UX.

**Sequencing of v3-h (now in-cycle):** it is loosely coupled — the individual feature pages render inside
`<x-admin.shell>` regardless of the nav chrome. Land the **new admin shell + section taxonomy right after v3-0**
(the section taxonomy is **shared** with v3-a's `admin.<section>.access` bundles — define it **once** in v3-0),
then build the permission features (2–7) into the new sections and fill each section's dashboard widgets as that
feature lands. Keep feature pages **shell-agnostic** so a slip in v3-h never blocks 2–7. Validate ordering against
migration dependencies before locking each ADR (spec §14 caveat).

---

## 4. The category-scope question — DECIDED

> **DECIDED (owner, 2026-06-17): Option (A) — bulk-apply.** Keep `Global → Forum (+ Club)`; no engine change.
> The card editor offers an "apply to every forum in this category" bulk write. The discussion below is retained
> as rationale.

Spec §5 wants inheritance **Global → Category → Forum**. The engine today resolves **Global → Forum (+ Club)** —
there is **no category scope**; categories are forum-tree nodes used for grouping, not a permission scope.

Two options:
- **(A) Keep Global → Forum (+ Club).** No engine change; the card editor sets per-forum overrides. Simpler,
  lower apex risk. Categories remain organizational only.
- **(B) Introduce a category scope.** Matches the spec and phpBB's "copy permissions from category" ergonomics,
  but is an **engine + `ScopeChain` change** (apex) and touches `VisibleForumIds` + the truth-table suite.

**Recommendation:** start with **(A)**; offer "apply these settings to every forum in this category" as a
**bulk write** in the UI (loops per-forum `acl_entries`) to get the ergonomics without a new scope. Revisit (B)
only if real boards demand true category inheritance.

---

## 5. Decisions — ALL SETTLED (owner, 2026-06-17)

**Major calls:**

- **Top-level admin → multiple co-owners.** No single founder / no 4-rail transfer protocol. Several accounts hold
  Root-equivalent power and can administer admins **and each other**. A **last-owner guard** (mirror the club
  sole-owner guard, ADR-0047/0049) keeps the board with **≥ 1 co-owner** at all times; removing a co-owner needs a
  confirmation. The installer crowns the first user as the initial co-owner. The Permission Inspector + Security
  section are **co-owner-only**.
- **Temporary-access delegation → BUILD now, as a TTL on `acl_entries`.** A delegation expands into
  **time-limited `acl_entries`** (an `expires_at` the resolver honours), auto-expires, and is **ceiling-bounded**
  (recipient never exceeds delegator). Cap = **30 days, shown to the delegator**. A cron sweep hard-deletes
  expired rows. *(This adds an additive `acl_entries.expires_at` + a resolver filter — an apex read-path change;
  see §2 and the §3 v3-0 engine seam.)*
- **Group auto-promotion → full AND/OR builder.** Arbitrary combinations of the four criteria (post count /
  tenure / trust score / reputation), promotion-only (never spurious-demotes, like Stage A A3).
- **Per-forum moderator capabilities → preset bundles + custom.** Seeded bundles ('Full mod', 'Content mod',
  'Queue-only') for fast assignment, plus an **advanced custom** path that reuses the Custom Role Builder (v3-d).

**Carried from prior turns:**

- **Category scope → Option (A) bulk-apply.** Keep `Global → Forum (+ Club)`; card editor offers "apply to every
  forum in this category." No engine / `ScopeChain` change.
- **ACP nav restructure (v3-h) → IN this cycle**, sequenced as an independent track (see §3).

**Defaults (owner did not override — flagged for a final glance):**

- Delegation cap **30 days, surfaced to the delegator**.
- Public Groups page **built, default OFF**, per-group visibility + open-join supported.
- Permission Inspector + Security section **co-owner-only** (was "Root/Admin-Manager").
- `/staff` roster shows **only staff-designated groups** (default none until configured).

**Mechanical:** ADR numbering — the in-flight PWA + i18n session consumes **0078/0079**; reserve **0080** (parent)
+ **0081…** (slices); confirm next-free at write time.

---

## 6. Verification & gate discipline (every slice)

- Per-slice gate in `forum-dev`: `php artisan test --parallel` · `pint` · `phpstan` (L5) · `migrate` — green
  boundary per commit.
- Every `acl_entries` write-path slice (2, 4, 5, 6, 7) gets an **apex adversarial verify-then-refute** review and
  **inspector-oracle** tests (assert the resolved verdict + trace, never a hand-rolled check).
- Extend the permission **truth-table** suite for any new holder type / scope / capability.
- Additive reversible migrations only; prove a Baseline-tier upgrade applies clean.
- New views ship with `admin.*` i18n keys (G7).

---

## 7. ADR-0080 (DRAFT) — ACP v3: admin & permission management architecture

> Provisional number — confirm next-free at write time (0078/0079 are taken by the PWA + i18n session).
> This is the **parent** ADR; each slice lands its own child ADR (0081…).

**Status:** Proposed (draft for owner approval).

**Context.** NovFora has a phpBB-grade permission **engine** (ADR-0006: ALLOW/NO/NEVER, `RoleExpander`,
`PermissionResolver`, `PermissionSync`, scopes global/forum/club) and a read-only `PermissionInspector`, but the
admin-facing **management UX** is minimal — permissions are shaped through seeded presets and the v2 groups
manager. The ACP v3 spec (2026-06-10) designs the full management layer but predates Clubs, the inspector, and
ADR-0025, and proposes parallel tables that risk a second evaluation path.

**Decision.** Build ACP v3 as a multi-slice program **on top of the existing engine**, under these binding
guardrails: (G1) every new construct expands into `acl_entries` and resolves through the one resolver — no
parallel evaluation; (G2) global/forum/**club** scope throughout; (G3) additive reversible migrations; (G4) the
`PermissionInspector` is the correctness oracle for all write-path tests; (G5) apex security fences — delegation
bounded by the delegator's ceiling (TTL on `acl_entries`); the top-level tier is **multiple co-owners** with a
last-owner guard (no single Root, no transfer protocol); the inspector is co-owner-gated;
ACP sections gated by real `admin.<section>.access` keys; (G6) Fable @ max for any `acl_entries`/resolver/
delegation/root-transfer slice; (G7) i18n keys from day one. Inheritance starts **Global → Forum (+ Club)** with a
bulk "apply to category" action; a true category scope is deferred unless demanded. Settled choices:
**multiple co-owners** (last-owner guard, no transfer machine); **temporary-access delegation as a TTL on
`acl_entries`** (additive `expires_at`, ceiling-bounded, 30-day cap shown); **AND/OR auto-promotion**
(promotion-only); **moderator capabilities = preset bundles + custom** (custom reuses the role builder).

**Consequences.** The engine and inspector remain the single source of truth, changed only by **one additive
seam** — a nullable `acl_entries.expires_at` the resolver honours (truth-table-covered) so TTL delegation rides
the one engine; everything else is additive surface + projections. The novel resolution concerns are now
**decided**: the expiry filter and the delegation ceiling, both guarded by dedicated adversarial + truth-table
tests. The ACP nav restructure (v3-h) is **in this cycle** but sequenced as an independent track so
a slip in it never blocks the permission features; its section taxonomy is **shared** with the admin bundles and
is defined once in v3-0.

**Alternatives considered.** (a) Implement the spec verbatim with parallel `admin_permissions`/`moderator`
evaluation — rejected (violates G1, splits the security-critical path). (b) Introduce a category scope now —
deferred (engine/`ScopeChain` change; bulk-apply covers the ergonomics). (c) Ship as one monolith — rejected
(unreviewable; the spec itself suggests slices).

**Scope fences.** No multi-tenant admin model; no marketplace/payments admin; a **true category scope** is
deferred (bulk apply-to-category covers the ergonomics). The nav restructure (v3-h) **is** in this program (owner
decision 2026-06-17).

---

## 8. What happens next

1. Owner reviews this doc + answers §5's open decisions.
2. On sign-off, finalize **ADR-0080** (parent) and open **v3-0** → then a Code session builds **v3-c** (the
   card-per-group editor) first as the highest-value, most self-contained slice, apex-routed, gated.
3. Each subsequent slice gets its own plan-before-code gate + child ADR.
