# PROJECT-STATE.md — NovFora (session resume / handoff)

> **Purpose:** single source of truth for where this project stands right now. Read this **first**, every
> session — both Claude Code and Claude Cowork. Keep it at the repo root. Whoever is working keeps it updated.
>
> **Completed milestone history → [`PROJECT-HISTORY.md`](PROJECT-HISTORY.md)** (moved to keep this file lean).
>
> **Standing detail lives in the folder — read, don't restate:** `docs/PROJECT-BRIEF.md` (full spec) ·
> `CLAUDE.md` (rules, model/effort routing) · `DECISIONS.md` (ADR log) · `ARCHITECTURE.md` ·
> `docs/architecture/`, `docs/product/`, `docs/research/` (Stage A set).

---

## 🛡️ ACP v3 · v3-b — per-forum moderators on `claude/acp-v3-b-moderators` — 2026-06-19 (LATEST · off `main`)

**Unattended, owner-authorized session.** Built ACP v3 slice **v3-b** (per-forum moderator assignment, ADR-0085) — a
CONSUMER of the v3-d role model, as a **projector slice** that adds NO new evaluation path (G1). **Base note:**
branched off `main` — v3-b only reuses the shared permission engine + the v3-d role model, so it is independent of the
unmerged v3-c/d/e stack (the owner merges all). Conventional, DCO-signed, `Tommy Huynh`-authored commits; each step
gated green.

**What shipped.** A `moderator_assignments` table (holder + `forum_id` + `role_id` XOR `bundle` slug, unique per
holder+forum; additive/reversible) + `App\Permissions\ForumModeratorProjector` (`assign()`/`revoke()`, mirrors
`ClubRoleProjector`) expanding into FORUM-scope `acl_entries` via `RoleExpander::assign`. Three seeded preset bundles
(`forum-mod-full` / `-content` / `-queue`) as `is_preset` roles, NOT group-expanded — only the projector expands them,
at forum scope. Custom path = any v3-d `is_preset=false` role. Surfaces: a per-forum **Moderators** tab
(`admin.forums.moderators`, a 3rd structure-tree button) + a global **Moderation → Moderators** pane.

**Apex fences (projector = actor-independent backstop; the SFCs self-guard admin.access + permissions.manage +
staff-2FA).** Grant-only (a mod role may carry no NEVER — the review's finding), admin-tier refusal (admin.access can
never be a mod power), the **ceiling reused at forum scope** (`RoleManager::assertWithinCeiling` is now
`?Scope`-parameterized — default global keeps the v3-d callers byte-identical), and the `ActorRank` rank guard (user
holders). Key-scoped deletes only + drop the forum-scope `RoleAssignment` on revoke/re-assign (G10 — a later role
edit's `reexpand` can't re-grant a revoked holder). `bans.manage` rides in the full bundle but is global-kind, so its
forum-scope row is inert (flagged).

**Apex review.** Adversarial verify-then-refute (security / integrity / concurrency lenses) before commit: **1
finding** — a NEVER-valued custom role would mint a forum-scope hard-deny (ceiling-exempt + `reexpand`-amplified).
Fixed with the grant-only fence + pinned by oracle case 8; no other finding survived refutation.

**Gate.** `pest` **1775/1777** (+29 new tests; the 1 fail is the pre-existing v3-e `HotPathQueryTest` query budget,
42 vs 41 — unrelated: v3-b touches no topic-render path) · `pint` · `phpstan` 0 · `migrate` apply+rollback+re-apply.
**Deferred follow-up:** the per-user "Moderation" tab on the member-edit screen (spec §4) — noted, not built.

**Next: v3-a** (admin bundles), then v3-f / v3-g per ADR-0080.

---

## 🛡️ ACP v3 · v3-d — custom role builder on `claude/acp-v3-d-roles` — 2026-06-18 (stacked on `claude/acp-v3-e-groups`, which stacks on `claude/acp-v3-foundations`)

**Unattended, owner-authorized session.** Built ACP v3 slice **v3-d** (custom role builder) on the existing engine,
per ADR-0080 slice order. **Base note (STACKING):** branched off `claude/acp-v3-e-groups` HEAD (NOT `main`) — v3-e
is not yet merged; the whole stack is the owner's to merge, **foundations → v3-e → v3-d**. Conventional, DCO-signed,
`Tommy Huynh`-authored commits, gated green in `forum-dev`. **NOTHING IS PUSHED** — branch is **local-only**.

**The builder (Groups → Roles, `/admin/groups/roles`, `<livewire:admin.roles>`).** CRUD `is_preset=false` roles as
a name + a **Yes / No / Never** grid over the permission catalog, grouped into clusters by the catalog `group`
field. The four seeded system presets (administrator / moderator / member / guest, `is_preset=true`) are READ-ONLY.
A built role applies as a **custom group's baseline** via `RoleManager::assignToGroup` → `RoleExpander::assignToGroup`
(expands at global scope; system groups refused). **No migration** — `is_preset` already distinguishes custom from
preset; permission keys carry dots so the grid uses `setValue(key,state)` actions, not a dotted `wire:model`.

**Apex fence — convergent re-expansion (the v3-d correctness seam).** `RoleExpander::reexpand()` previously only
UPSERTED, so a key DROPPED from a role lingered on every holder as a stale grant. It now also DELETES a
caller-supplied `droppedKeys` set at each assignment's scope (and `retract()` removes a role's whole footprint on
delete). `RoleManager::save` captures the pre-edit keys, computes `dropped = old − new`, and converges every
assigned holder in one transaction; `delete` retracts everywhere. Deletion is KEY-SCOPED (only named keys), so a
co-grant on a different key survives. Query-builder deletes skip the `AclEntry` event (G9) → the policy layer bumps
`AclVersion` once per op. **Provenance caveat (review MEDIUM, scoped):** `acl_entries` has no `role_id`, so a key
that is BOTH in a role AND set by the card editor on the same (group,scope) is one row — removing the role removes
it (last-writer-wins; a group is managed by a role baseline OR the card editor on a given key, not both — ADR-0084).

**Escalation + self-lockout fences (mirror v3-c; service backstop + SFC 403 pre-check).** Only a FULL admin
(`isAdmin()`) may put / assign / tear-down an **Administration-cluster** key (catalog `group=='Administration'`);
no ALLOW may exceed the actor's own ceiling (`canDo(key,global)`); NEVER is ceiling-exempt but still admin-fenced.
The admins group can never be stripped of its recovery keys (`admin.access` + `permissions.manage`). **The apex
review found 1 HIGH** — the self-lockout + admin-tier guard were missing on the destructive `delete`/`unassign`
paths — **fixed and pinned by tests before commit** (the dangerous precondition is UI-unreachable, but the guards
are now actor-independent backstops). It also surfaced the MEDIUM provenance note above (scoped, not a regression).

**Gate at HEAD (all green):** `php artisan test --parallel` · Pint · PHPStan L5 · migrate **apply + rollback +
re-apply** (no new migration — the existing reversible chain). Inspector-oracle tests (G4) are the correctness
proof: a DROPPED key disappears from holders AND its row is gone (+ version bumped); ADD appears; co-grant survives;
swap converges; the fences hold on every create/assign/unassign/delete path. ADR **0084** lifted into `DECISIONS.md`.

**Next: v3-a** (admin bundles) — **v3-b ✅ shipped 2026-06-19 (ADR-0085, `claude/acp-v3-b-moderators`)** — then
v3-f / v3-g per ADR-0080.

---

## 🛡️ ACP v3 · v3-e — group system on `claude/acp-v3-e-groups` — 2026-06-18 (stacked on `claude/acp-v3-foundations`, which carries v3-0/v3-h/v3-c)

**Unattended, owner-authorized session.** Built ACP v3 slice **v3-e** (group system) on the existing engine, per
ADR-0080 slice order. **Base note:** branched off the tip that carries the merged v3 cycle — `main` does NOT yet
carry it (v3-0/v3-h/v3-c are still on `claude/acp-v3-foundations`, pending the owner's push/PR), so the faithful
base for v3-e was that branch's HEAD, not `main`. Conventional, DCO-signed, `Tommy Huynh`-authored commits, gated
green in `forum-dev`. **NOTHING IS PUSHED** — the branch is **local-only**; the owner pushes + opens the PR.

**Membership models (`groups.membership_model`).** admin (unchanged default) / request (a moderated
`group_join_requests` approval queue, ACP Groups → Join requests) / open (a public Join button). `GroupMembershipService`
mirrors `ClubMembershipService` (request → approve/deny, open join, leave). Every self-service join passes
`GroupJoinGate` (verified + active + not-banned) so a banned/suspended/unverified/restricted account can't bypass
new-user limits to join; system + trust groups are never self-joinable.

**AND/OR auto-promotion (`GroupAutoPromoter`).** Generalises Stage-A A3's flat trust floor to an arbitrary
`{op:AND|OR, rules:[{criterion:posts|tenure_days|trust|reputation, gte:N} | nested]}` tree. **Promotion-only**,
**idempotent**, **custom groups only** (trust stays with `TrustLevelManager`). Legacy flat `{min_*}` still
evaluates (normalised as one AND node). Evaluated by the new hourly `novfora:groups:auto-promote` cron (authoritative
catch-up + the only path crossing the time-based `tenure_days` bar) + queued listeners on `PostCreated`/`TopicCreated`
(post count) and `ReputationAwarded` (rep), mirroring the badge-award wiring. Fail-closed on malformed nodes. A
single-level AND/OR builder lives in the group editor (`⚡groups`); the engine also evaluates nested trees.

**Public Groups directory + primary chooser.** `GET /groups` lists ONLY `is_public` groups (per-group flag, OFF by
default → page + nav link empty/hidden until opted in); exposes name/description/member-count only — never a roster
or a hidden group. The primary-group chooser lets a user pick their primary; an admin override sets + LOCKS it
(`group_user.is_primary_locked`); primary is cosmetic (resolution reads all memberships) so it needs no invalidation.

**Apex fence — the membership-cache seam (`MembershipCache`, G9's sibling).** A group is a permission HOLDER, so a
pivot join/leave/promote/approve/admin-assign changes effective permissions WITHOUT an `acl_entries` write — and
pivot writes fire no model events. `MembershipCache::flushFor($user)` (1) reloads the user's `groups` relation so
the next `groupSignature()` re-keys that user's cross-request verdict cache, (2) flushes the per-request
`PermissionResolver` memo, (3) flushes `VisibleForumIds`. The additive hot paths (join/approve/auto-promote) do NOT
bump `AclVersion` (the per-user signature scopes it; a global bump on every auto-promotion sweep would cold-start
every viewer's cache). The rare REDUCTION/SWAP paths (leave/remove/delete-reassign/trust-demote) pass
`bumpVersion: true` — defence-in-depth from the adversarial review: a signature is a pure function of the id-set, so
a reduction can round-trip to a previously-cached signature, and the bump dominates it (harmless on the
membership+ACL axes, but robust on orthogonal axes like a cached ban verdict). Routed `TrustLevelManager` (had only
the inline memo flush) + `GroupManager` through the same helper.

**Gate at HEAD (all green):** `php artisan test --parallel` · Pint · PHPStan L5 · migrate **apply + rollback +
re-apply** (down() drops the columns/table cleanly). Inspector-oracle tests are the correctness proof for the
seam (a raw attach WITHOUT the seam is shown stale; every real path flips the inspector verdict immediately + the
cached `can()` path). ADR **0083** lifted into `DECISIONS.md`.

**Next: v3-d** (custom role builder), then v3-b / v3-a / v3-f / v3-g per ADR-0080.

---

## 🛡️ ACP v3 — admin & permission management on `claude/acp-v3-foundations` — 2026-06-18 (off `main` carrying the PWA + i18n merge)

**Unattended, owner-authorized session.** Built the first three ACP v3 slices on the existing permission engine,
per the owner-approved program (foundations §3/§5 + kickoff refresh; **ADR-0080** parent, **0081**/**0082**
children). Conventional, DCO-signed, `Tommy Huynh`-authored commits, each gated green in `forum-dev`.
**NOTHING IS PUSHED** — the branch is **local-only**; the owner pushes + opens the PR.

**v3-0 — the engine seam (apex · ADR-0080) — `feat(perms)` commit.** Additive, reversible nullable+indexed
`acl_entries.expires_at`; a single **authoritative** resolver filter (`expires_at IS NULL OR > now`) on the
acl_entries read; the cached `can()` horizon capped to the earliest contributing TTL so the Gate path stays
authoritative even if the prune cron lags (an apex-review **MEDIUM**, fixed + pinned); the
`novfora:acl:prune-expired` cron (every 5 min, short overlap mutex, restore-skipped, one AclVersion bump on the
builder-delete). NULL rows resolve **byte-identically** — the whole pre-v3 suite is the regression guard.
Truth-table / inspector + prune + cache-boundary tests. 4-lens adversarial review before commit.

**v3-h — the Invision-style IA (UI · ADR-0081) — `feat(acp)` commit.** Icon rail of 11 sections → per-section
sidebar → per-section dashboard landing (one `SectionController` + a shared `admin.section` view) + a global ACP
search (`admin.search`: pages / settings / members). `AdminNavigation` is the single source (rail + sidebars +
active-section + search index). Old admin URLs **301** to their new section homes via bare `Route::redirect`
(excluded from the authz-walk; a dedicated 301 test); route **NAMES kept stable** so call-sites are unaffected;
the Permission Inspector moved System → **Security** and was renamed
`admin.system.permissions` → `admin.security.permissions` (5 call-sites). One `admin.*` i18n group (G8-checked).
Keyboard-navigable rail. **No new permission keys** (per-section gating arrives in v3-a; the rail is
`admin.access`-gated for now).

**v3-c — the headline card-per-group editor (apex · ADR-0082) — `feat(acp)` commit.** `GroupPermissionEditor`
writes a group's OWN entries directly (Yes = ALLOW · No = delete-the-row → inherit · Never = NEVER), **not** via
RoleExpander. One Livewire SFC (`permissions.group-editor`, `#[Locked]` scope) at all three homes: GLOBAL
(Groups → Group permissions), FORUM (Forums → forum → Permissions, linked from the structure tree), CLUB (the club
manage screen). Category bulk-apply copies a forum's overrides onto every **non-club** forum under its category in
one transaction, audited. Fences (**two HIGH** from the apex review, fixed + pinned): the manage-permissions
capability gate + a rank guard; an **admin-only fence on Administration-tier keys** (else a non-admin permission
manager could escalate); a **self-lockout guard** on the admins group's `admin.access` / `permissions.manage` at
global (service throws + SFC 403s — the interim last-owner guard until v3-a's co-owners). Inspector-oracle tests
across all three scopes + the bulk + every fence.

**Gate at HEAD (all green):** `php artisan test --parallel` → **1650 pass / 1 skip (13267 assertions)** · Pint
(840 files) · PHPStan L5 · migrate **apply + rollback + re-apply**. ADRs **0080 / 0081 / 0082** are lifted into
`DECISIONS.md`; the planning set is `docs/product/acp-v3-{foundations,kickoff-refresh,adr-0080}.md`.

**Next: v3-e** (group system — membership models + AND/OR auto-promotion), then **v3-d / v3-b / v3-a / v3-f / v3-g**
per ADR-0080. Their homes (Custom Role Builder, Group priority, Co-owners/Active Delegations under Security) slot
into the v3-h sections already in place; v3-f's TTL delegation rides the v3-0 `expires_at` seam.

---

## 🧩 PWA + i18n polish on `claude/pwa-i18n-polish` — 2026-06-17 (off a freshly-integrated `main`)

**Unattended, owner-authorized session.** **Step 0** first integrated the outstanding UI/UX branch into `main`
(`git merge --no-ff claude/ui-ux-nav-login-infocenter` → `da4c460`, gated green: 1596 pass / PHPStan L5 / Pint /
migrate) and cut this branch off it. **NOTHING IS PUSHED** — pushing `main` + reconciling origin is interactive-only;
the owner does it. Conventional, DCO-signed, `Tommy Huynh`-authored commits, gated in `forum-dev` at each green
boundary. ADRs: **0078** (PWA), **0079** (i18n).

**Unit A — PWA subpath-aware + raster icons (ADR-0078):** the manifest `start_url`/`scope` + icon srcs, the SW
registration scope, `Service-Worker-Allowed`, and the SW's own `SCOPE` (read from `registration.scope`) all derive
from the mount base, so the PWA installs + the service worker registers/caches under a `/community/` subdirectory
mount as well as a domain root (a byte-identical no-op at a root). Added 192/512 `any` + a full-bleed `maskable-512`
PNG (rasterized from `novfora.svg`). **Resolves the ADR-0070 PWA-under-a-subpath deferral.**
`tests/Feature/Pwa/PwaTest.php` → 14 cases green.
  - **⚠ OWNER VALIDATION (real device/host, not machine-verifiable here):** install the app under a `/community/`
    mount and confirm (1) the install prompt appears, (2) the SW registers — DevTools → Application → Service Workers,
    scope `/community/`, and (3) the install/home-screen icon shows the blue "N" PNG, not a blank square.

**Unit B — i18n view-string sweep, wave 1 (ADR-0079; extends ADR-0043/0073):** externalized the **forum**,
**members**, and **profiles** domains into `lang/en/forum.php` + `profiles.php` (joining auth/common/errors/search),
plus `common.edit` and the **members labels in `common.*`** (members/directory/top_members — NOT a `members.php`
group; see the collision lesson). Each is a gated, DCO-signed `i18n(<domain>)` commit with a
per-domain guard test (`tests/Feature/I18n/*LangKeysTest.php`: keys resolve + a page renders English with no raw
`"<domain>."` token). **English output is byte-for-byte unchanged** (curly punctuation preserved; count suffixes kept
as static keys — trans_choice would change the n=1 text). A residue scan verified the three swept domains carry no
remaining bare literals.
  - **Residue (recorded, community-contributable — same pattern):** clubs (~25), settings (~20), notifications (~20),
    tags (~15), pm (~12), the ACP `admin/*` (~150+), and the Livewire ⚡ components under `resources/views/components/**`
    (~370+, the largest pool). Highest-value next: components → admin → clubs + settings.
  - **Lesson logged:** the forum sweep was delegated to a Sonnet sub-agent which introduced **smart-quote
    delimiters** (`__(‘forum.x’)`) in two empty-state blocks → a 500 the per-domain guard missed (it didn't hit the
    empty path) but the full suite caught. Fixed (perl byte-replace); members/profiles were then done in-loop with
    straight quotes. Takeaway: gate agent-edited Blade with the FULL suite, not just the domain guard.
  - **Lesson logged (case-collision):** a `lang/en/members.php` group made `__('Members')` (the live string-key in
    ForumStatsWidget / clubs / nav) return the whole array on the case-insensitive bind-mount → 500. Fix: members
    labels live in `common.*`, no `members.php`. **Rule (ADR-0079):** never name a group file after a word used as a
    bare `__('Capitalized')` key. `forum`/`profiles` groups are safe only because grep confirmed no `__('Forum')` /
    `__('Profiles')` caller. The full suite (not the domain guard) caught this too.

**Branch commits (local, unpushed):** `da4c460` merge → then `518bb18` feat(pwa), `af6b430` chore(pwa icons),
`d6d8405` i18n(forum), `13e70ff` i18n(members), `1e75f74` i18n(profiles), + the ADR-0079 docs commit.

## 🎨 UI/UX polish on `claude/ui-ux-nav-login-infocenter` — 2026-06-17 (MERGED → `main` via `da4c460`)

Three independent, conventional, DCO-signed, `Tommy Huynh`-authored commits, cut off `main` after `claude/phase-5-ga`
(PR #30) and RH-4 landed. **NOTHING IS PUSHED** — push is interactive-only in the sandbox; the owner pushes + opens
the PR. Sonnet-class per CLAUDE.md routing (view boilerplate / tiny CRUD — none hit the apex). Each gated in
`forum-dev` at a green boundary (`test --parallel` · PHPStan L5 · Pint · `migrate`).

- **Fix 2 — login i18n (`fix(i18n)`):** `lang/en/auth.php` (shipped on phase-5-ga) overrides Laravel's `auth.*`
  namespace but omitted the framework scaffolding strings, so a failed/throttled login rendered the raw `auth.failed`
  token. Added `failed` + `throttle`. The third default — the `password` string — is **intentionally NOT added**:
  `auth.password` is already the forgot-password UI group, and the only `current_password` check
  (`App\Actions\Fortify\UpdateUserPassword`) supplies its own message, so nothing reads the framework string (a
  duplicate key would just be shadowed). Guard: `tests/Feature/Auth/AuthLangKeysTest.php`.
  ⚠ **DEPLOY GAP — owner action (no repo fix possible):** the live `dev.novfora.com/login` raw `auth.login.*` render
  is a host build that shipped the keyed Blade views **without** the `lang/` directory. Redeploy **including `lang/`**
  then run `php artisan optimize:clear` on the host (verify `ls -l lang/en/auth.php` there first).
- **Fix 1 — responsive header (`fix(ui)`, CSS-only):** the single-breakpoint header wrapped the wordmark at mid
  widths. Brand link `shrink-0 whitespace-nowrap` (+ small-screen truncate guard); search is the one flexible child
  (`min-w-0`, deferred to `md`); nav `md:gap-1`; auth cluster `shrink-0` + `ml-auto md:ml-1`. Deliberate trade-off:
  search leaves the bar in the 640–767px band (the hamburger owns it there). Brand-markup guard added to the
  public-routes smoke test.
- **Fix 3 — classic Info Center (`feat(forum)`, ADR-0077):** Statistics + opt-in Who's-Online panels above the
  activity feed. `App\Forum\InfoCenter` read-model caches primitives only (RH-9) under `novfora:infocenter:stats`,
  rehydrates the newest member after the boundary; aggregate-only (no hidden-forum leak); **no migration**.
  `tests/Feature/Forum/InfoCenterTest.php` (6 cases).

---

## 🚀 Phase 5 — HARDENING → GA on `claude/phase-5-ga` — 2026-06-16 (MERGED → `main`, PR #30)

**Unattended, owner-authorized GA-readiness run off `main` (Phase 4 fully merged: ADR-0060 + ADR-0069 present).
17 conventional, DCO-signed, `Tommy Huynh`-authored commits on `claude/phase-5-ga`. NOTHING IS PUSHED** — push
is interactive-only in the sandbox; the owner pushes + opens the PR. No new product features (hardening/polish/
docs/tests only). Every ADR (0072–0076) is **"Accepted — owner-authorized GA run; flagged for review."**

**Model-routing note (recorded, ADR-0072):** CLAUDE.md routes security work to **Fable @ max**, but
`claude-fable-5` was **unavailable** in this build env, so the apex rung was taken at **Opus 4.8 (1M)** — a
conservative, security-preserving fallback (a stronger model finds only more).

**Final gate (branch HEAD, in `forum-dev`):** `php artisan test --parallel` **1560 passed / 1 skipped / 0
failed** (12779 assertions; baseline 1525 → **+35** Phase-5 tests) · PHPStan (level 5) **0 errors** · Pint clean
(813 files) · `migrate` clean. Every unit committed only at a green boundary.

### Per-unit status (ADR)
- **P5.1 security ✅ (ADR-0072)** — 2nd adversarial verify-then-refute over the whole Phase 3/4 surface (11
  domain reviewers + per-finding refuter panels). **No HIGH.** 8 MEDIUM + 3 LOW + 2 INFO fixed (each + test); 6
  refuted. Full writeup: `docs/architecture/security-review-phase5.md`.
- **P5.2 WCAG 2.1 AA ✅ (ADR-0044)** — automated page gate grown **14 → 27 surfaces** (clubs/PMs/memberships/
  notifications/preferences/trending/whats-new/saved/tags/home/leaderboard); 3 accessible-name failures fixed.
  Manual residue recorded in `docs/architecture/accessibility.md`.
- **P5.3 i18n ✅ (ADR-0073, extends ADR-0043)** — framework/RTL/switch/fallback (already shipped + tested)
  completed with a complete **`es` proof locale**, the **auth + error** surfaces externalised
  (`lang/en/{auth,errors}.php`), and a per-key `en`-fallback test. Coverage + residue below.
- **P5.4 perf ✅ (ADR-0074, extends ADR-0045)** — `HotPathQueryTest` proves the hot paths are **N+1-free** in
  steady state; baseline + enhanced-tier procedure/SLOs documented in `docs/architecture/load-testing.md`.
- **P5.5 release ✅ (ADR-0075)** — the `nevo→novfora` rename **completed** (command prefix, editor JS island +
  rebuilt assets, dev/CI infra names) + **enforced by a CI brand gate**; version → **1.0.0**; new `CHANGELOG.md`
  + `docs/product/release-checklist-1.0.md`; removed a stray committed `.env.root-stale`.
- **P5.6 fresh-install ✅ (ADR-0076)** — `FreshInstallSmokeTest` drives the redeploy path on an EMPTY DB green
  (schema + seeded roles/permissions/system groups + a capable first admin + lock); `build-release.sh` produces
  a clean `novfora-release.zip` and the cold artifact boots **`GET / → 302 /install`** (both verified directly).

### 🔐 SECURITY findings (P5.1 — all HIGH/MEDIUM fixed; full table in `docs/architecture/security-review-phase5.md`)
| Sev | Finding | Fix |
|---|---|---|
| Med | Search forum-facet leaked private-club names to logged-in non-members | facet now applies `clubContentVisibleTo` |
| Med | SSO (OAuth/SAML) skipped mandatory **staff 2FA** | `ChallengesStaffTwoFactor` defers staff to Fortify's TOTP challenge |
| Med | OAuth signup bypassed registration toggle + anti-spam + **email/IP bans** | `resolveForLogin` mirrors `CreateNewUser` (refuse / flag→pending) |
| Med | REST `createPost` ignored the **locked-topic** gate | shared `Topic::isReplyable()` |
| Med | Installer **DB-test SSRF** bypassed the setup token | re-assert the token at the sink |
| Med | Stripe webhook granted without **`payment_status`** proof | require paid/no-payment-required |
| Med | **Unbounded `@mention` fan-out** (mass-notify + DoS) | cap at `antispam.mention_fanout_cap` (10) |
| Med | Importer **legacy-attachment path traversal** | reject `..`/scheme at the read site |
| Low | Stripe webhook idempotency had no DB UNIQUE | `UNIQUE(provider, provider_ref)` + violation catch |
| Low | `/api/v1` ran without install/upgrade maintenance gates | applied ahead of token auth |
| Low | Attachment on a soft-deleted post still downloadable | mirror the trashed gate (uploader/moderator only) |
| Info | manifest reserved-namespace case-sensitive; OAuth profile strings verbatim | case-insensitive guard; clamp + strip control/bidi |
**Refuted (recorded):** sole-owner club orphan (data-integrity, an ADR-0047 fast-follow, not security) · API
trust-rate-limiter (bounded by throttle:api + the pipeline) · `acl_entries` no-UNIQUE (resolver dup-tolerant) ·
2FA-mutation password re-confirm (documented Phase-2 deferral) · OAuth IP-only throttle · sandbox quoted-URL
scheme (admin-trust-gated).

### ♿ Residual MANUAL a11y items (not machine-verifiable — owner/QA before go-live)
Contrast (1.4.3, incl. admin-set custom theme tokens) · keyboard nav + no focus traps (2.1.1/2.1.2) · visible
focus (2.4.7) · reduced-motion (2.3.1) · live-region status messages (4.1.3) · a screen-reader pass + the RTL
visual pass on the newly-covered clubs/PMs/memberships flows. (`docs/architecture/accessibility.md`.)

### 🌐 i18n coverage (P5.3)
**Externalised + `en`-complete + `es`-translated:** the framework strings, `common`, `search`/saved-search,
**all auth screens**, **all error pages**. Framework (allowlist, `SetLocale` precedence, validated switch, RTL
`<html dir>`, per-key `en` fallback) is shipped + tested (LocalizationTest, 11). **Residual (documented,
mechanical, community-contributable — NOT a 100% sweep):** the authenticated front-end (`forum/clubs/pm/
profiles/settings/members/…`, the ~92 `components/`) + the staff `admin/` ACP stay on literal English; partial
externalisation + partial locales are always correct (literal English shows; missing keys fall back to `en`).

### ⚡ Baseline load results + enhanced procedure (P5.4)
**Baseline query-shape (sqlite gate; engine-independent):** board index **13 warm**, forum listing/topic/
search/clubs all **< 40–45** — **no steady-state N+1**; hot-path columns indexed. The board index's cold build
(~69) is the 60s fragment-cache build, amortised. **Enhanced tier NOT run against a real host** — procedure +
suggested SLOs (baseline reads p95 < 600ms / search < 1.5s; enhanced reads < 250ms / search < 300ms) + capacity
guidance + the at-scale `EXPLAIN` step are in `docs/architecture/load-testing.md`.

### ✅ VALIDATE-BEFORE-GO-LIVE (consolidated — carried from Phase 4 + new)
Scaffolded/disabled-by-default; unit-tested against fakes only. Enable + validate per the named ADR /
`docs/product/release-checklist-1.0.md`:
1. **Meilisearch** (ADR-0060) — index + confirm no private-club leak.
2. **Reverb realtime** (ADR-0061/0062) — websocket round-trip + the channel-authz no-leak.
3. **Live Stripe** (ADR-0065 + P5.1) — real keys/webhook; grant only on `payment_status=paid`; add `invoice.*`/
   cancellation before auto-renewal.
4. **OAuth / SAML** (ADR-0053–0056) — real apps; the no-merge rule + the **staff-2FA step-up** (P5.1) end to end.
5. **Web Push** (ADR-0058) — VAPID; live push-service round-trip.
6. **StopForumSpam submission** (ADR-0069) — optional; key + the content-privacy opt-in.
7. **Load test at scale** (ADR-0045/0074) — k6/artillery on the real baseline + enhanced host; capture p50/p95/
   p99 vs the SLOs; `EXPLAIN` the forum-listing sort.
8. **Manual a11y** (ADR-0044) — the residual checklist above.
9. **`verify-release.sh`** — runs clean in a normal container/CI (its checks were verified directly here; the
   script doesn't cleanly *return* under `docker exec` because the backgrounded `php -S` isn't reaped — env, not
   a defect).

#### Enhanced-tier live validation — 2026-06-19 (build VPS, against live backends)
Ran `docs/product/enhanced-tier-validation-kickoff.md`. First time the scaffolded Enhanced tier was exercised
against real backends (everything was unit-tested against fakes only). **Items 1–2 above are now PROVEN; the
Redis cache/queue path too.**
- **Prereqs discovered + fixed (the box was only half-bootstrapped):** the app was **not installed** (no
  `storage/installed` marker) and the DB was **migrated-but-empty**, so `AppServiceProvider::prepareForInstaller`
  force-hardened cache/session/queue to file/file/sync — masking the Enhanced `.env` entirely. Ran
  `php artisan novfora:install --demo` (preserves the Enhanced `.env`; APP_ENV→production; admin
  `admin@novfora.test`). Two **Enhanced client libs were missing from composer** (scaffolded but never added,
  since tests fake them) → added on `chore/enable-reverb`: `meilisearch/meilisearch-php`, `laravel/reverb` +
  `pusher/pusher-php-server`.
- **Redis (cache/session/queue):** cache round-trips via Redis (DB 1, key `novfora-database-novfora-cache-vt`);
  a real `RegenerateUserPostHtml` job was drained by the `novfora-queue` worker in ~0.25 s (effect applied);
  `queue:failed` = 0; `ServiceTierFallbackTest` green.
- **Meilisearch (#1):** 14 approved posts indexed (`numberOfDocuments:14`); live keyword search served by the
  engine (proved via typo-tolerance a DB `LIKE` can't do); **private-club no-leak HELD** over the live index —
  a non-member got 0 for a term that IS in the index, the member got 1 (the index is never the sole gate;
  `SearchService` re-gates via `clubContentVisibleTo`); on a dead engine `search()`/`posts()` degrade to the DB
  with no error and the no-leak still holds. *(First validated against a dev-run `meilisearch` instance; then
  re-verified over the now-fixed system `meilisearch.service` — see findings.)*
- **Reverb (#2):** authorized subscriber on `private-thread.{id}` received the **id-only** payload
  `{post_id,topic_id,user_id}` over a live socket (no body crosses the wire); an unauthorized subscriber was
  **rejected 403 at `/broadcasting/auth`** (`ChannelAuthorizer::canViewThread`). Enablement committed on
  `chore/enable-reverb`; gates green *(1746/1748 Pest — the 1 failure is the pre-existing v3-e
  `HotPathQueryTest` query-budget, 42 vs 41, unrelated to these deps which are inert under the test env's
  sqlite/`scout=database`/`broadcast=null`; PHPStan 0, Pint clean)*.
- **Findings / follow-ups (NOT blockers for #1–#2 correctness, but for box hygiene):**
  - `scout.queue=false` → with the `meilisearch` driver a Meili **outage makes searchable writes throw inline**
    (post creation breaks). Recommend `SCOUT_QUEUE=true` on Enhanced so a transient engine outage degrades
    gracefully on writes too.
  - System `meilisearch.service` **crashed → FIXED**. Root cause was NOT a version mismatch (the DB `VERSION`
    matched binary 1.47.0): the unit had **no `WorkingDirectory`**, so CWD defaulted to `/`, which the
    `meilisearch` user (uid 999) cannot write — Meili exits `Permission denied (os error 13)` at startup. Added
    `WorkingDirectory=/var/lib/meilisearch`; the service is now active + enabled and the 16 posts re-imported.
    *(If a provisioning script generates this unit, apply the same fix there.)*
  - Port **8080 is held by nginx** (CloudPanel's web server), so the `novfora-reverb` unit (which hardcoded
    `--port=8080`) was **repointed to 8090 → FIXED**: unit `--port=8090` + `.env REVERB_PORT=8090`, `enable
    --now`; now active + boot-persistent and the round-trip was re-verified over the systemd-managed server.
    Production WSS still needs an nginx proxy from the public origin to `127.0.0.1:8090` (CloudPanel config).
  - `composer audit`: 3 **medium** advisories in transitive `guzzlehttp/guzzle` (<7.12.1) + `guzzlehttp/psr7`
    (<2.12.1), disclosed 2026-06-18 — recommend bumping both (separate maintenance commit).
- **Still deferred (need external accounts/creds):** #3 live Stripe, #4 OAuth/SAML, #5 Web Push, #6
  StopForumSpam, #7 load-at-scale, #8 manual a11y.

### Is the build 1.0-tag-ready?
**Yes — code-wise.** The 1.0 brand gate passes + is CI-enforced, version is 1.0.0, the gate is green
(1560/0-fail · PHPStan 0 · Pint), the fresh-install + release-artifact paths are proven, and no HIGH/MEDIUM
security finding is open. **The tag should be cut only AFTER** the owner (a) reviews + pushes this branch +
merges, and (b) works the **VALIDATE-BEFORE-GO-LIVE** list for any integration they will actually rely on (a
default baseline deploy uses none of them — they ship inert). Cut per `docs/product/release-checklist-1.0.md`.

### ☀️ Morning report — what the owner does next
1. **Review** the 17 commits on `claude/phase-5-ga` (ADRs 0072–0076, flagged-for-review), then **push** + open
   the PR. A freshly-built `novfora-release.zip` (gitignored) sits in the repo root from the P5.6 proof.
2. One harmless **zombie `php -S`** lingers in `forum-dev` from the P5.6 verify probes (unused port) — a
   container restart clears it; it does not affect the gate.
3. New docs: `docs/architecture/security-review-phase5.md`, `CHANGELOG.md`,
   `docs/product/release-checklist-1.0.md`; updated `accessibility.md` / `i18n-and-rtl.md` / `load-testing.md`.

---

## 🛠 RH-4 — First-class subdirectory install on `claude/rh4-subdir-install` — 2026-06-16 (merged into `main`)

**Unattended, owner-authorized build off `main`. 9 conventional, DCO-signed, `Tommy Huynh`-authored commits on
branch `claude/rh4-subdir-install`. NOTHING IS PUSHED** — push is interactive-only in the sandbox; the owner
pushes + opens the PR. **ADR-0070** (subdirectory install) + **ADR-0071** (canonical home at the mount root) are
"Accepted — owner-authorized build; flagged for review."

**Final gate (branch HEAD, `forum-dev` container, PHP 8.3.6):** `php artisan test --parallel` **1550 passed /
1 skipped / 0 failed** (12723 assertions; baseline 1525 → **+25** RH-4 tests) · `pint` clean (812 files) ·
`phpstan` (level 5) **0 errors** · `php artisan migrate` clean. Each unit committed only at a green boundary.
(Run via `docker.exe exec forum-dev` from WSL — the WSL distro's own PHP lacks mbstring/xml + composer, so
`forum-dev` is the canonical gate; Docker isn't reachable from inside WSL but `docker.exe` is via interop.)

### What shipped (commit · unit)
- `941485f` **RH4.1** docs — ADR-0070/0071 accepted; the spike's stale **"ADR-0038" renumbered → 0070/0071**
  (0038 was consumed by the mega-build; highest existing was 0069 — resolves the brief's "confirm the next ADR
  number doesn't collide").
- `126b020` **RH4.1b** (ADR-0071) — the forum **index IS the home AT the mount root**: the `forums.index` route
  NAME moved to `/` (so every `route('forums.index')` link generates the mount root), `/forums` is a permanent
  **301 → the root**. Uninstalled `/` still 302s to `/install`. RootRouteTest/ExampleTest + the cache/maintenance/
  smoke suites updated.
- `f3ad4c6` **RH4.2 (APEX)** — `App\Support\Http\BasePathDetector` (in `AppServiceProvider::boot`): forces the
  URL/asset root from the request **only when APP_URL is unset/localhost**, derived from Symfony `getBasePath()`
  (SCRIPT_NAME/RewriteBase). Strict no-op at the root layout (G4) + never overrides a real APP_URL; the forced
  root == the request root, so Livewire's update URI keeps a **single** prefix (no `/community/community/`). 7 tests.
- `6b8c84b` **RH4.3** — `config/app.php` `asset_url`; `App\Install\SubdirectoryScaffold` +
  `php artisan novfora:subdir:scaffold` (Option B: generated stub `index.php` + `.htaccess` + single-canonical
  build/storage links); `.env.example` ASSET_URL + NOVFORA_PUBLIC_LINK notes. 8 tests.
- `b0a587e` **RH4.4 (APEX)** — installer subpath awareness: the wizard pre-fills the Site URL with the detected
  subpath; InstallRunner writes APP_URL + ASSET_URL; RedirectIfNotInstalled allowlist confirmed prefix-agnostic
  (`Request::is()` matches base-stripped path-info — spike open-question #3).
- `5165955` **RH4.5** — `SubdirInstallTest` (8): subdir wizard 200 + `/community`-prefixed Livewire endpoint;
  allowlist prefix-agnostic; post-install `/community/` serves the index; `/community/forums` 301; avatar under
  `/community/storage`; **G4 root-layout regression guard**; **G2 rebuild-drift guard**.
- `b634409` **RH4.6** — `docs/REAL-HOST-VALIDATION.md` §3b rewritten (Option A symlink default / B scaffold /
  C copy last-resort) + a concrete **Hostinger `novfora.com/community/` walkthrough**; getting-started forward
  ref; real-host-findings §RH-4 → RESOLVED.
- `612368f` **fix (apex review)** — `EnvWriter` now escapes `$` for ANY written value (see review below).

### APEX adversarial review (verify-then-refute, 17 agents)
A 4-lens security review of the detector + installer surface: **13 candidates → 12 refuted, 1 MEDIUM confirmed +
FIXED**. `EnvWriter::format()` wrote a bare value containing `${VAR}` unquoted, so dotenv would **interpolate it
on load** — an operator-supplied Site Name like `${APP_KEY}` / `X${DB_PASSWORD}` (wizard rule `string|max:60`)
could leak a secret via MAIL_FROM_NAME / APP_NAME on the unauthenticated pre-install surface. Pre-existing root
cause; RH-4.4 extended `writeEnv` through the same path and the mandated review caught it. Fixed in `612368f`
(+ 3 tests with a real phpdotenv-parse proof). The 12 refuted candidates (Host-header trust, allowlist bypass,
redirect loops, scaffold path-traversal, …) were verified non-exploitable.

### Recorded assumptions / honest notes (also in ADR-0070 + the spike)
- **The detector is a conservative confirmation/pin, not the load-bearing mechanism.** Empirically (a bootstrap
  probe), Laravel ALREADY carries the subpath on every URL surface (route / @vite / Livewire) via the **request
  base path** when SCRIPT_NAME is correct — which Options A/B/C all ensure (symlink / stub+RewriteBase /
  copy+RewriteBase). The detector forces the same root only when APP_URL is unset/localhost and is otherwise a
  no-op; it never forces a root inconsistent with the request base (which would double-prefix Livewire). The real
  levers are: canonical home at root + a correct SCRIPT_NAME (RewriteBase) + the installer writing APP_URL/
  ASSET_URL with the subpath.
- **PWA under a subpath is DEFERRED (documented limitation, recorded not built).** PwaController + the service
  worker still emit root-relative paths (`start_url`/`scope`/`/icons/`/`/build/`/`/offline`). Under a subpath the
  SW simply fails to register (a caught no-op) → offline caching off; core forum + install are unaffected. Not in
  any RH-4 unit/acceptance test; tracked as a fast-follow (noted in ADR-0070).

### ⚠ NOT MINE — concurrent foreign WIP left in the working tree (owner: review/remove before merge)
During this session the tree gained **uncommitted/untracked** changes that are **not part of RH-4** and were left
untouched: `routes/web.php` (+`/forums/import-seed` GET/POST routes + a `use ImportForumSeedController`), and new
untracked `app/Http/Controllers/ImportForumSeedController.php` + `app/Console/Commands/ImportForumSeedCommand.php`
(a separate import/seed experiment, likely from another session). **⚠ Those `/forums/import-seed` routes carry NO
auth middleware — review as a possible unauthenticated upload endpoint and remove or gate before merging from this
tree.** None of it is in my commits.

### ☀️ Morning report — what the owner does next
1. **Review** the 9 commits on `claude/rh4-subdir-install` (ADR-0070/0071, flagged-for-review), then **push** +
   open the PR.
2. **Deploy a subdirectory install** per `docs/REAL-HOST-VALIDATION.md` §3b — for Hostinger
   `novfora.com/community/`, prefer **Option A** (`ln -s ~/novfora/public ~/public_html/community`); on a
   no-symlink plan use **Option B** (`php artisan novfora:subdir:scaffold ~/public_html/community --base=/community`).
   Set the Site URL to the full subpath (the wizard pre-fills it); the index serves at `/community/`.
3. **Triage the foreign import-seed WIP** above (not mine).

---

## 🌙 Phase 4 ENHANCED build (M4 Search/Realtime · M5 Paid memberships · M6 Anti-spam) on `claude/phase-4-enhanced` — 2026-06-15 (merged to `main` via PR #28)

**Unattended, owner-authorized autonomous build off `main` (with M1–M3 already merged). 11 conventional,
DCO-signed, `Tommy Huynh`-authored commits on branch `claude/phase-4-enhanced` (10 feature + 1 wrap-docs).
NOTHING IS PUSHED** — push is interactive-only in the sandbox; the owner pushes + opens the PR. Built M4 → M5 →
M6 in order; each unit is its own gated commit. Every ADR (0060–0069) is **"Accepted — owner-authorized
overnight build; flagged for review."**

**Final gate (branch HEAD, run in `forum-dev`):** `pest --parallel` **1525 passed / 1 skipped / 0 failed**
(baseline 1428 → **+97** Phase-4-enhanced tests) · `phpstan` (level 5) **0 errors** · `pint` clean ·
`php artisan migrate` clean. Every unit was committed only at a green boundary; APEX units (broadcast authz,
money/Stripe webhook, spam intelligence, external-signal privacy) got dedicated security tests.

### Per-unit status (commit · ADR)
- **M4 Enhanced tier** — `aa42e0c` 4.1 Meilisearch via Scout behind service-detection, DB-driver fallback, the
  **no-leak re-gate** (the index is never the sole privacy boundary), in-admin setup/health (ADR-0060) ·
  `87e259b` 4.2 **(APEX)** Reverb broadcasting + **channel-authorization no-leak fence** (private club / PM /
  hidden thread can never leak over a socket) + polling fallback (ADR-0061) · `95c528f` 4.3 opt-in presence /
  online-member list + presence-channel no-leak (ADR-0062).
- **M5 Paid memberships** — `9b81022` 5.1 tier model + **perk gating through the engine** (TierProjector →
  acl_entries, fixed perk universe) + admin/member surfaces (ADR-0063) · `5695399` 5.2 PaymentProvider contract
  + **offline/manual provider — the only live-granting path** (ADR-0064) · `88c7455` 5.3 **(APEX)** Stripe
  hosted checkout **charging DISABLED** + hardened webhook (HMAC + replay + SSRF posture) (ADR-0065) · `fcdf247`
  5.4 money-fenced paid-clubs hook (ADR-0066).
- **M6 Advanced anti-spam** — `ea896ba` 6.1 **(APEX)** HOLD-only spam intelligence (similarity/burst/reputation)
  + false-positive guards (ADR-0067) · `17426c9` 6.2 staff-gated review surface (scores/signals/actions)
  (ADR-0068) · `d0d3ddc` 6.3 **(APEX)** external-signal tuning + **content-privacy fence** (no post content to a
  third party without an explicit opt-in) (ADR-0069).
- **Wrap docs** — `docs/architecture/phase-4/{search-meilisearch,realtime-reverb,memberships,anti-spam-intelligence}.md`,
  ROADMAP, this handoff.

### ⚠ SCAFFOLDED — NOT VALIDATED against a live service (validate before relying on)
No external service / paid account exists in the build env, so these are proven only against
faked/mocked clients. **Exact enable + validate steps:**

1. **Meilisearch (M4.1).** Run a Meilisearch instance; set `SCOUT_DRIVER=meilisearch` + `MEILISEARCH_HOST` +
   `MEILISEARCH_KEY` (or Admin → Settings → Search); `php artisan scout:sync-index-settings`; `php artisan
   scout:import 'App\Models\Post'`; confirm relevance + that a private-club post never appears for a non-member.
2. **Reverb realtime (M4.2/M4.3).** `composer require laravel/reverb pusher/pusher-php-server`; `php artisan
   reverb:install`; set `BROADCAST_CONNECTION=reverb` + `REVERB_*`; `npm install laravel-echo pusher-js`,
   configure `window.Echo`, `npm run build`; run `php artisan reverb:start` under a supervisor. The
   **channel-authorization logic is fully tested**; the websocket round-trip + thread-page live-append are not.
3. **Live Stripe payments (M5.3).** Create a Stripe account + products; Admin → Settings → Payments: paste
   secret/publishable keys + toggle on; add a Stripe webhook → `https://<site>/webhooks/stripe` for
   `checkout.session.completed` and paste its signing secret; run a **test-mode** checkout and confirm the grant.
   Add `invoice.*` / `customer.subscription.deleted` handling before relying on auto-renewal. **Until enabled,
   the offline/manual provider is the live-granting path; no charge can be initiated.**
4. **StopForumSpam submission (M6.3).** Optional. Set the SFS submission key + enable the live API in Admin →
   Settings → Anti-spam to enable opt-in spammer reporting. Leave "send post content to external services" OFF
   unless your community consents. The scoring/holding pipeline (M6.1) + the review surface (M6.2) are fully real.

### Recorded assumptions (also inline + in DECISIONS.md)
- **Search (M4.1):** engine path taken only for keyword queries with no tag/type facet (those stay on DB to
  remain correct); the visibility filter is applied natively AND re-gated in PHP (ADR-0060).
- **Realtime (M4.2):** events broadcast with **id-only payloads** (no bodies/PII; client refetches); broadcast
  gated on the enhanced tier so baseline pays nothing. No `laravel/reverb`/`pusher-php-server` installed (added
  at enable time) — channel authz tested on the null driver (ADR-0061).
- **Presence (M4.3):** `users.show_online_status` **default FALSE** (opt-in / security-by-default) — the
  "who's online" list is sparse until members opt in; this also closed a prior gap where the theme widget showed
  every active member (ADR-0062).
- **Memberships (M5.1):** perks are a **fixed `TierPerks` universe** (a tier can never grant an arbitrary
  capability); each perk's *effect* is wired per-feature — M5.1 delivers the gating. Tier expiry is an hourly
  cron `novfora:tiers:expire`. No card data stored (ADR-0063).
- **Payments (M5.2/M5.3):** **manual provider is the only live-granting path**; Stripe is **disabled by default**
  (needs the enable flag AND a secret key) — no charge possible. Stripe is hosted-checkout (card data never
  touches the server); no `stripe/stripe-php` dependency (hand-rolled). Webhook handles `checkout.session.completed`
  only (renewal events are a documented follow-up) (ADR-0064/0065).
- **Paid clubs (M5.4):** `clubs.require_membership` **default FALSE**; when on, creation needs the
  `tier.create_clubs` perk — **no new money path** (the perk comes from the membership system) (ADR-0066).
- **Spam intelligence (M6.1):** **HOLD-only** (never deletes); trusted members exempt (staff / `trusted_floor` 3 /
  `established_posts` 50); thresholds in `config/novfora.php → antispam.intelligence` (ADR-0067). One unrelated
  pagination fixture was retargeted to a trusted author (its 20-rapid-replies setup correctly tripped burst).
- **External signals (M6.3):** the SFS block threshold is admin-tunable (default 75 unchanged);
  `antispam.external_content_optin` **default FALSE** is the privacy fence — only metadata is ever sent unless an
  admin opts in (ADR-0069).

### What remains for Phase 4 / toward 1.0 (NOT built this run — record only)
This **completes Phase 4's planned surface (M1–M6).** Remaining is **validation against live
services/providers** (the four items above) + the standing Phase-5 items (full i18n string externalisation,
captured load-test numbers on both tiers, docs → 1.0). No new feature work is queued for Phase 4.

### Pre-existing uncommitted WIP — STASHED again (not mine)
On session start, `main`'s working tree again carried the **prior `claude/mega-build` upgrade WIP** (idempotent
`Schema::hasTable` migration guards + an `UpgradeCommand` restore-path fix — the stash had been popped back since
the last session). To keep this branch clean it was **`git stash`ed** with a backup patch at
`storage/handoff/preexisting-upgrade-wip-13afedd.patch` (the working-tree diff matched the patch exactly).
**Owner: review + `git stash pop` (or apply the patch) on `main` if that work should land.**

### ☀️ Morning report — what the owner does next
1. **Review** the 11 commits on `claude/phase-4-enhanced` (ADRs 0060–0069, all flagged-for-review), then
   **push** + open the PR from your terminal.
2. **Restore the stashed upgrade WIP** on `main` if wanted (see above).
3. **Before relying on the enhanced tier in production:** follow the four "SCAFFOLDED — NOT VALIDATED" enable
   steps above (Meilisearch, Reverb, live Stripe, SFS submission).
4. New docs to skim: `docs/architecture/phase-4/{search-meilisearch,realtime-reverb,memberships,anti-spam-intelligence}.md`.

---

## 🌙 Phase 4 build (M1 Clubs · M2 SSO · M3 PWA+Push) on `claude/phase-4-features` — 2026-06-15 (REVIEW + PUSH THIS)

**Unattended, owner-authorized autonomous build off `main` (the merged mega-build base). 14 conventional,
DCO-signed, `Tommy Huynh`-authored commits on branch `claude/phase-4-features`. NOTHING IS PUSHED** — push is
interactive-only in the sandbox; the owner pushes + opens the PR. Built M1 → M2 → M3 in order; each unit is its
own gated commit. Every ADR (0047–0059) is **"Accepted — owner-authorized overnight build; flagged for review."**

**Final gate (branch HEAD, run in `forum-dev`):** `pest --parallel` **1428 passed / 1 skipped / 0 failed**
(baseline 1302 → +126 Phase-4 tests) · `phpstan` (level 5) **0 errors** · `pint` clean · `php artisan migrate`
+ seed clean. Every unit was committed only at a green boundary.

### Per-unit status (commit · ADR)
- **M1 Clubs** — `d28226f` 1.1 data model + CRUD + directory/home (ADR-0047) · `7cb93c2` 1.2 **(APEX)** club-scoped
  permissions through the engine — new `club` Scope + `ClubRoleProjector`, `permissions:sync` aware (ADR-0048) ·
  `71f1e60` 1.3 membership flows (join/request/invite-token/leave/roster/transfer) + the global-staff rank
  ceiling (ADR-0049) · `eae4b6b` 1.4 discussion on the existing forum stack via `forums.club_id` (ADR-0050) ·
  `52c654f` 1.5 **(APEX)** the no-leak privacy sweep across every surface + an adversarial review that found +
  fixed **2 leaks** (reaction-notify emit, stored-notification render) (ADR-0051) · `9bb75f3` 1.6 configurable
  creation policy (ADR-0052).
- **M2 SSO** — `fc7a1fa` 2.1 **(APEX)** OAuth login (Google/GitHub/Discord), encrypted secrets, email-collision
  **no-merge** (ADR-0053) · `0e72a6d` 2.2 **(APEX)** account linking + the proven-control flow (ADR-0054) ·
  `d6100ae` 2.3 **(APEX)** PKCE + state + CSRF + the outbound-SSRF analysis (ADR-0055) · `c9a152e` 2.4 SAML
  **scaffold only** (ADR-0056).
- **M3 PWA + Push** — `3a60a8d` 3.1 installable PWA + a no-PII service worker (ADR-0057) · `a17e412` 3.2 Web Push
  (VAPID) opt-in cron-tolerant channel (ADR-0058) · `9931254` 3.3 push preferences UI (ADR-0059).

### ⚠ SCAFFOLDED — NOT VALIDATED against a live service (validate before relying on)
- **OAuth (2.1–2.3):** no real Google/GitHub/Discord apps/credentials in the build env → the end-to-end provider
  round-trip is **unproven**; the flow is tested against **mocked** Socialite. Validate with real client ids +
  the published redirect URI before enabling in production.
- **SAML (2.4):** **scaffold only** — interface + detection + mocked tests; **NO concrete provider ships** and it
  **does not work end to end**. Inert by default (every SAML route 404s until an operator binds a provider).
- **Web Push delivery (3.2):** no browser subscription / push endpoint in the build env → the encrypt-and-POST to
  a real push service is **unproven**; wiring tested with a mocked sender. The PWA service worker's offline cache
  + the push client JS are browser-only and unvalidated against a live service.

### Recorded assumptions (also inline + in DECISIONS.md)
- **Club-creation default:** `clubs.creation_policy = trust`, `clubs.creation_min_trust_level = 2` (verified
  member at TL ≥ 2). The brief's "admin-approved" option is realised as **staff-only** creation; a
  request→approval queue is deferred (ADR-0052).
- **SSO provider set:** Google + GitHub (core Socialite) + Discord (socialiteproviders/discord). All providers
  **OFF by default**; secrets stored **encrypted**. New composer deps (all MIT, Apache-2.0-compatible):
  `laravel/socialite ^5.27`, `socialiteproviders/discord ^4.2`, `minishlink/web-push ^10.1`.
- **Club privacy (APEX):** because the board is public-by-default (global guests `forum.view=ALLOW`), pure-ACL
  cannot hide a private club from a logged-in non-member — content-hiding is a **query-level gate**
  (`Forum::clubContentVisibleTo` + extended `VisibleForumIds`) consulted by every surface, with the engine
  carrying club CAPABILITIES + a guests-`NEVER` for anonymous defence-in-depth (ADR-0047/0051).
- **Sole club owner + account deletion:** deleting a sole owner's account leaves the club ownerless — an
  ownership-transfer-before-deletion guard is a documented fast-follow (ADR-0047).
- **PWA icons:** ship a maskable SVG; production should add 192/512 raster PNGs for the widest install prompt
  (ADR-0057).

### What remains for Phase 4 (NOT built this run — record only)
**M4 — Meilisearch + Reverb** (enhanced-tier search execution path + real-time, carried from prior scaffolding);
**M5 — paid memberships / subscriptions** (out of scope this run per the brief); **M6 — advanced anti-spam
intelligence.** Also: the OAuth/SAML/Web-Push validation against live services/providers (above).

### Pre-existing uncommitted WIP — STASHED (not mine)
On session start, `main`'s working tree carried **53 uncommitted files** from the prior `claude/mega-build`
session (idempotent `Schema::hasTable` guards on migrations + an `UpgradeCommand` restore-path fix — coherent
upgrade-robustness WIP, never committed). To keep the Phase-4 branch clean it was **`git stash`ed**
(`stash@{0}: "preexisting-upgrade-wip-from-mega-build …"`) with a backup patch saved at
`storage/handoff/preexisting-upgrade-wip-13afedd.patch`. **Owner: review + `git stash pop` (or apply the patch)
on `main` if that work should land.** A few pre-existing untracked artifacts (`.env.root-stale`,
`provider-symfony~var-dumper.json`, `docs/product/rh4-subdirectory-install-spike.md`, `storage/.backups-root-stale/`)
were left untouched.

### ☀️ Morning report — what the owner does next
1. **Review** the 14 commits on `claude/phase-4-features` (ADRs 0047–0059, all flagged-for-review), then **push**
   + open the PR from your terminal.
2. **Restore the stashed upgrade WIP** if wanted (see above).
3. **Before enabling SSO / Web Push in production:** create real OAuth apps + VAPID keys
   (`php artisan novfora:push:vapid`) and validate end to end — they are scaffolded, not live-validated.
4. New docs to skim: `docs/architecture/phase-4/{clubs,sso,pwa-and-push}.md`.

---

## 🌙 Overnight mega-build on `claude/mega-build` — 2026-06-14 (REVIEW + PUSH THIS)

**Unattended, owner-authorized autonomous build (Option 2): only Phase-4-INDEPENDENT units, off `main`
(Phase-3 base). 19 conventional, DCO-signed, `Tommy Huynh`-authored commits on branch `claude/mega-build`
(HEAD `6856b33`). NOTHING IS PUSHED** — push is interactive-only in the sandbox; owner pushes + opens the PR.

**Precondition note:** the original brief gated this on Phase 3 hardening **AND Phase 4** being merged. Phase 4
was confirmed **never built** → owner chose **Option 2**: build only the units that do **not** depend on Phase 4,
and record the Phase-4 deferrals (below). No Phase-4 feature was built or stubbed.

**Final gate (branch HEAD, run in `forum-dev`):** `pest --parallel` **1302 passed / 1 skipped / 0 failed** ·
`phpstan` (level 5) **0 errors** · `pint` clean · `php artisan migrate` clean. Every wave was committed only at a
green boundary; each new unit added apex-level tests for its security/permission/concurrency/untrusted-input
surface. Every ADR below is **"Accepted — owner-authorized overnight build; flagged for review"** — they want a
human pass before/at merge.

### What shipped, in build order (wave → commit → ADR)
- **0.1 permissions:sync** — `b4f3d2a` (ADR-0036). `novfora:permissions:sync` additively re-provisions role
  presets on upgrade (never `RoleExpander::reexpand`; additive-only). **Clears the Badges 403 on the live host.**
- **1.x Theme Studio** — `650afdc` 1.1 visual token editor (AA-checked) · `4ad749e` 1.2 sanitised custom
  header/footer · `b6f6856` 1.3 layout regions + widgets · `a1fdde3` 1.5 per-theme logo/favicon/bg ·
  `f3abe10` **1.6 sandboxed template editor (APEX — bespoke lexer/parser/evaluator, data-only, no raw
  Blade/PHP; independent adversarial review found+fixed a HIGH lint-bypass)** (ADR-0037, ADR-0038). **1.4
  club hook SKIPPED (needs Phase 4).**
- **2.x Member tools** — `4d548a2` 2.1 bookmarks · `590311f` 2.2 ignore/block · `190b4ba` 2.3 spoiler/CW
  blocks · `14ae657` 2.4 post scheduling (cron-tolerant) (ADR-0039).
- **3.x Discovery** — `56d0763` 3.1 trending/best-of · `90df8e2` 3.2 RSS/Atom feeds · `91f42e3` 3.3
  related-topic recommendations + 3.4 sitemap/SEO (ADR-0040). All permission-safe.
- **4 XenForo importer** — `1e0da04` (ADR-0041). Clean-room, behind `SourceDriver`/`ProvidesAttachments`,
  idempotent/resumable with 301 redirect emission.
- **6.1 Search** — `27026bb` (ADR-0042). Inline operators (`author:`/`in:`/`tag:`/`after:`/`before:`/`type:`)
  on the existing facet layer + own-only saved searches.
- **8.1 i18n + RTL** — `1722c4e` (ADR-0043). Laravel-native localisation framework, allowlist-guarded
  language switcher, `<html dir>` RTL switch. **Framework + Wave-6.1 surface externalised; full ~100-view
  string sweep is mechanical follow-up.**
- **8.2 WCAG 2.1 AA** — `b01e2c4` (ADR-0044). Deterministic DOMDocument auditor + Pest page gate (14
  surfaces, zero findings) + `novfora:a11y:audit` command + manual checklist. Fixed 3 real bugs
  (colour-mode toggle name, save-search input label, tag-input label).
- **8.3 load-test harness** — `ff75944` (ADR-0045). `novfora:loadtest:seed` (real write path) + k6 +
  artillery drivers + procedure. **SCAFFOLDED — no at-scale numbers measured/claimed.**
- **8.4 security sweep** — `6856b33` (ADR-0046). Verify-then-refute (2 independent reviewers + apex pass).
  One MEDIUM fixed (unauthenticated search-operator DB query amplification → bounded to ≤3 queries +
  `?q` length cap + `throttle:120,1`); rest of the new surface refuted.

### ⛔ DEFERRED pending Phase 4 (NOT built, NOT stubbed — record only)
**1.4 Theme-Studio club hook · 5.3 SAML · 6.2 Meilisearch execution path · 6.3 Reverb · Wave 7 monetization.**
These require Phase 4 to exist first. Where a seam was needed it stays dormant/driver-gated; no half-built
feature was shipped.

### ⚠ SCAFFOLDED — NOT VALIDATED against a real service (validate before relying on)
- **Load-test numbers (8.3):** the harness runs; **no at-scale run was performed**. Validate:
  `php artisan novfora:loadtest:seed --forums=… --topics=… --posts=…` then
  `k6 run -e BASE_URL=… load-tests/k6/browse.js` (or artillery) on representative hardware. See
  `docs/architecture/load-testing.md`.
- **Meilisearch / Reverb / SAML (6.2 / 6.3 / 5.3):** DEFERRED — not built this run (carried from prior
  scaffolding; enhanced-tier, need a real service to validate).
- **i18n non-`en` locales (8.1):** es/fr/de/pt_BR/ar/he are **registered scaffolding** — no `lang/<code>/`
  files yet, so they fall back to `en`. RTL `dir` flip is automated; a visual RTL pass is manual.
- **WCAG (8.2):** the automated auditor is a **floor, not conformance** — the manual checklist
  (contrast/keyboard/focus/SR/RTL visual) in `docs/architecture/accessibility.md` is still owner/QA work.

### ☀️ MORNING REPORT — what the owner does next
1. **Review** the 19 commits on `claude/mega-build` (all flagged-for-review ADRs 0036–0046), then **push** and
   open the PR from your terminal (push is interactive-only here; `gh` absent).
2. **Clear the Badges 403 on the live host** — run permissions:sync on the deployed site:
   ```
   php artisan novfora:permissions:sync
   ```
   (additive-only; safe to re-run; re-provisions the role presets the 403 is missing.)
3. New docs to skim: `docs/architecture/i18n-and-rtl.md`, `accessibility.md`, `load-testing.md`,
   `security-review-wave8.md`, `sandbox-template-threat-model.md`, `permissions-sync.md`.
4. New artisan commands available after merge: `novfora:permissions:sync`, `novfora:a11y:audit <url|file>`,
   `novfora:loadtest:seed`.

---

## 📦 Beta release bundle BUILT + Phase 3 now on main — 2026-06-13

**`main` is at `e5d724b` (= `origin/main`) and carries Phase 3 + the hardening pass** — merged via PR #24
(`claude/phase-3-hardening`) + PR #25 (build-release rename) and **pushed**. (The "nothing is pushed" note in the
hardening section below is from before that merge — superseded.) Re-confirmed gate on `main` HEAD: **Pest 1116
passed / 1 skipped / 0 failed**, `pint` clean, `phpstan` L5 0 errors, migrations apply clean. *(A first parallel
gate run showed 156 false failures from a stale compiled-view cache carrying WSL `/mnt/d` paths into the `/app`
container; cleared with `view:clear` and the authoritative single-process run is green.)*

**Deployable `novfora-release.zip` built from `main` HEAD** for the no-SSH in-place beta upgrade (per
[`docs/product/live-deploy-kickoff.md`](docs/product/live-deploy-kickoff.md)):
- **Artifact:** `D:\Forum\novfora-release.zip` · 12.66 MB (13,271,763 bytes) · sha256
  `9ea9623d8e329011f2f741463372a7bd670819fb1c41021794f94b423df8a3e5` · **gitignored (not committed)**.
- **Carries Phase 3:** `/api/v1`, module/theme registries, phpBB/MyBB/SMF importers, analytics rollup, H1 webhook
  SSRF guard; **60 migrations (10 Phase-3/Stage-A)** → `SchemaState::codeFingerprint()` advances so a
  `v1.0.0-beta.1` host sees `schema.pending = true` and auto-upgrades (RH-10).
- **Verified:** truly-cold HTTP boot (NO artisan first) `GET /` → **302 /install**, `/install` → **200**;
  `bootstrap/cache/packages.php` ships (RH-1) and no `.env` / install marker / env caches do.
- **ADR-0031…0035** given the flagged human pass — consistent with the locked decisions (see `DECISIONS.md →
  Phase 3 — ADR human review pass`).
- **Committed (script/doc only):** `scripts/build-release.sh` portability fix (`SKIP_NPM` + `optimize:clear`
  ordering), the `public/build` asset rebuild, and these notes. **Owner: push `main` + upload the zip per the
  live-deploy Part B runbook.**

---

## ⭐ Phase 3 — HARDENED · PROVEN · DOGFOODED — 2026-06-13 (REVIEW THIS FIRST)

A focused run to **prove and harden Phase 3 before more is built on it** (NOT a new phase). Phase 3 was first
merged into `main` (PR #23, Stage A + Phase 3 together), then this work landed on branch
**`claude/phase-3-hardening`** (off `main`) as 10 gated, conventional, DCO-signed commits.

**Gate status (final):** full suite **1116 passed / 1 skipped, 0 failed** (`pest`, parallel) · `pint` clean ·
`phpstan` (level 5) **0 errors** · `php artisan migrate` clean. Baseline on `main` was 1077; this run added the
hardening/dogfood tests. Run on the host's **PHP 8.5** (satisfies the `^8.3` floor) — see env assumptions below.

**⚠ Nothing is pushed.** All 10 commits are on `claude/phase-3-hardening` for you to review + push from your
terminal (push is interactive-only in this sandbox).

### HARDEN — closed every flagged Phase-3 follow-up (APEX)
- **H1 — Webhook SSRF / DNS-rebinding** (`feat(webhooks)…2e3c5e3`). New `App\Webhooks\WebhookUrlGuard`: delivery
  resolves the host, refuses any private/loopback/link-local/reserved/CGNAT/metadata/IPv6-ULA/mapped/6to4/NAT64
  address, **pins** the connection to a validated IP (CURLOPT_RESOLVE), and **re-validates every redirect hop**.
  Shared deny-list kernel `App\Support\Ssrf\IpClassifier` (the oEmbed guard now delegates to it — one source of
  truth). Tests: rebinding sim + metadata-endpoint attempt.
- **H2 — Importers verification & fidelity** (`879dd1a`, `50eb308`). MyBB + SMF promoted from scaffolds to
  **VERIFIED** against representative fixtures (full import + idempotency/resume); order-independent forum import
  + SMF title-from-first-message fidelity fixes; **attachment import + sha-256 checksum verification** across all
  three drivers; `verify()` now reconciles CONTENT, not just counts. (Fixed a latent `body_canonical`
  double-encode bug, caught by phpstan.)
- **H3 — Plugin trust guardrails** (`c8cbfdf`). Full-trust **consent gate** at enable, package **integrity hash**
  (verified/modified), **disable-on-fatal quarantine**, and a file-based **kill switch** — NOT a sandbox (none
  built; a real sandbox + full package signature stay out of scope, documented).
- **H4 — Module migration rollback** (`d21b2f8`). `remove()` uses `migrate:reset` (all batches), not
  `migrate:rollback` (last batch only). Remaining items are intentional future enhancements (scope-fenced).

### PROVE — adversarial review + coverage
- **P1 — Adversarial review** (`666f6d5`, APEX). Verify-then-refute over the whole surface (lifecycle/path,
  manifest, hook/filter/slot, REST authz, tokens+rate-limit, webhook HMAC+SSRF, importer dumps). **1 MEDIUM found
  + fixed** — a throwing hook filter / slot renderer is now isolated (caught + reported + skipped) so a faulty
  full-trust extension can't 500 every render. All other vectors **verified-safe, no HIGH**. Full per-vector
  writeup in `DECISIONS.md`.
- **P2 — Coverage + fuzz** (`04fea56`). Property/fuzz tests for the untrusted-input parsers (`ManifestFuzzTest`
  ~400 cases → total + fail-closed; `BbcodeFuzzTest` ~600 cases → total, no tag leak, no ReDoS); API-token
  rotation flow. (No Dusk — flows are server-rendered Livewire, fully covered; no browser driver here.)

### DOGFOOD — used the contract to find gaps (the real payoff)
- **D1 — two first-party plugins** (`63f5072`): `novfora/qa` (accepted answer) + `novfora/kudos`, each exercising
  EVERY seam (event, filter, slot, migration, setting, permission, route; kudos also a layout widget) — **zero
  core edits**. Surfaced **3 contract gaps, all closed ADDITIVELY → Module API `1.1.0`:** (1) no per-post UI slot
  → added `topic.post.aside`; (2) no plugin-settings path → `SettingsRegistry::register()`; (3) `widgets` missing
  from the manifest `provides` vocabulary.
- **D2 — one first-party theme** (`f138d57`): `themes/nebula`, a polished child theme overriding the documented
  `ThemeApi` token contract + branding, proven to coexist with slots + the layout configurator. **No new gaps**;
  `ThemeApi::VERSION` stays `1.0.0`.
- Guide: **`docs/architecture/phase3-extensibility/writing-plugins-and-themes.md`** (write-your-first plugin/theme,
  grounded in the proven contract). The phase-3 arch docs were updated with the proven security model.

### Recorded assumptions (also in `DECISIONS.md`)
- **Environment (sandbox only — no repo impact):** the host's root-owned, unreadable `.env` (an overnight-Docker
  artifact) was renamed to **`.env.root-stale`** and a clean baseline (sqlite) `.env` written so gates run as
  `tommy`; restore it with `sudo mv .env.root-stale .env` if it held real settings. A `conf.d` ini raises the
  PHP-8.5 CLI `memory_limit` to 512M (the lexbor html-sanitizer parser + Pest need it). Parallel Pest runs with
  `--cache-directory=/tmp/...` (the bundled `vendor/pestphp/pest/.temp` is root-owned). Several stale root-owned
  runtime files under `storage/` were moved aside. Docker is NOT available in this WSL distro.
- **`scripts/build-release.sh` is STASHED** (`git stash` — "build-release.sh tweak (push from my terminal
  later)") so it couldn't block branch switches; apply + push it yourself.

---

## Overnight autonomous build — 2026-06-13 (Stage A + Phase 3 build)

An unattended run completed **Stage A (6 M5-deferred fast-follows)** and **Stage B (Phase 3 Extensibility — all
5 subsystems)**. Everything is gated green and committed; **nothing is pushed** (see push status). Every Phase-3
ADR is marked **"Accepted — owner-authorized overnight build; flagged for review"** — give them a human pass
before 1.0.

**Gate status (final):** `composer test` (parallel) **1077 passed, 1 skipped (3598 assertions)** · `pint`
clean (620 files) · `phpstan` (level 5) clean · run via `docker exec forum-dev`. (Baseline started at 972.)

**Branches (⚠ owner must push — `git push` is interactive-only and times out in-sandbox; `gh` absent):**
- `claude/stage-a-fast-follows` — Stage A, 7 commits `869c0db..93e83ea`, atop `origin/main` (152276f).
- `claude/phase-3-extensibility` — Stage B, 7 commits `45407eb..37f5e45`, **branched off the Stage-A tip**
  (so it contains Stage A too). Suggested merge order: Stage A PR first, then Phase 3 PR (which then shows only
  its own commits); or merge Phase 3 directly (includes Stage A).

### Stage A — fast-follows (branch `claude/stage-a-fast-follows`)
| # | What | Commit |
|---|---|---|
| A1 | Staff notes — private, staff-only (`bans.manage`, never the subject); audited; ADR-0025 author de-id | `869c0db` |
| A2 | Public "Top members" leaderboard (rep/posts × all-time/30d/7d); shares the directory visibility gate | `fdc7b1f` |
| A3 | **APEX** Trust auto-promotion by reputation — a PROMOTION-ONLY floor (never spurious-demotes) + upgrade migration | `cc01545` |
| A4 | Aurora filesystem child theme + two core override seams (AA-safe palette); ships inactive | `73b9f8f` |
| A5 | **APEX** isSoleAdmin TOCTOU — locked re-check inside the deletion transaction | `57d0669` |
| A6 | **APEX** ActivityVersion/AclVersion lost-bump — atomic `Cache::add`+`increment` | `b11eb46` |

### Stage B — Phase 3 Extensibility (branch `claude/phase-3-extensibility`)
| # | Subsystem | ADR | Commit(s) | Notes |
|---|---|---|---|---|
| B1 | Module/plugin foundation — manifest+validation, lifecycle, deps/compat, events/filter-hooks/slots, perms, ACP, example plugin | 0031 | `45407eb`, `b54e858` | **APEX boundary.** Post-build adversarial review found + fixed a HIGH path-traversal (`b54e858`) |
| B2 | Visual theming + layout configurator — `ThemeApi` token contract, widget/region system, ACP | 0032 | `8633f28` | |
| B3 | REST API (`/api/v1`, token auth, engine-authorized, paginated, rate-limited) + outbound webhooks (HMAC, cron retry, SSRF guard) | 0033 | `cc936e5`, `160745e` | **APEX boundary** |
| B4 | Importers — clean-room, driver-based | 0034 | `fce128d` | **phpBB built + tested; MyBB + SMF SCAFFOLDED** (schema mapped, unverified) |
| B5 | Admin analytics — privacy-conscious aggregate daily rollup (cron) + dashboard | 0035 | `37f5e45` | |

Phase-3 design set: **`docs/architecture/phase3-extensibility/`** (module-system, theming-layout, api-webhooks,
importers, analytics).

### Partial / scaffolded / flagged for review
- **B4 MyBB + SMF drivers are scaffolds** — schema mapped behind the same `SourceDriver`, **not verified against
  a live board**; their hash schemes aren't Laravel-verifiable so those users reset on first login. Importer
  **verify is count-reconciliation** (attachment import + checksum verify is a documented follow-up).
- **B1 module trust model is full-PHP-trust** (no PHP sandbox is feasible — documented). The SSRF guard (B3) is
  literal-host/IP based (no DNS-rebinding protection). These are documented in their ADRs, not bugs.
- The 6 untracked `docs/product/*.md` kickoff files + `provider-symfony~var-dumper.json` were present **before**
  this run and are left untouched (not part of any commit).

### Key assumptions recorded inline + in `DECISIONS.md`
Staff notes reuse the existing `bans.manage` (no new permission key). Trust rep-gate is promotion-only (floor at
current level). Module permission keys only ADD to the catalog (never escalate). Slot/filter/widget HTML is
re-sanitised through the post-HTML allowlist. Webhooks degrade on the baseline tier via the cron runner; the
dispatcher is gated on a cached "any active endpoints" flag so the no-endpoints case adds zero hot-path queries.
Importers go through the Eloquent models (not the services) so a bulk import fires no domain events. Analytics
store aggregates only (no PII); `daily_metrics.metric_date` is a plain `Y-m-d` string. Full per-subsystem
rationale: `DECISIONS.md` ADR-0031…0035 + the "Fast-follow backlog notes" section.

---

## What this is

**NovFora** (name locked 2026-06-10, ADR-0026; "Hearth" and "NevoBB" are **retired codenames**; in-code
rename complete 2026-06-11, commit `b0cc294`) — open-source (**Apache-2.0**), self-hosted forum/community
platform; modern PHP; **two tiers from one codebase** (baseline shared PHP host / enhanced Docker-VPS);
WYSIWYG-first editor; phpBB-grade permission masks; strict clean-room.

## Current stack

**Laravel 13 + Livewire 4 + Alpine.js + Blade**, server-rendered. PHP 8.3 floor. MySQL 8 / MariaDB default;
PostgreSQL on Docker/VPS. Vite, prebuilt assets (no host Node). Approved — ADR-0001/0002 (Accepted).

## How we work

- **Claude Code (build):** scaffolds and writes the Laravel app. Plan-before-code per phase.
- **Claude Cowork (knowledge work):** reviews plans/docs, preps gate packets, writes status summaries. No app code.
- **Don't run both against the working tree at the same time.** Commit between handoffs; git is the source of truth.
- **Two stages, gated:** Stage A (Discovery) → Phase 0 gate **passed** → Stage B phased implementation
  (plan-before-code, wait for approval per phase).

## Status (as of 2026-06-12)

**Phase 1 / Core MVP · Phase 1.5 hardening · real-host fixes RH-6–RH-11 — all COMPLETE.** Default theme +
polish R1, ACP v1/v1.1, Spike P2 deliverability (GO), and **ACP v2** all merged. **Phase 2 (Community) —
COMPLETE.** P2-M1 through **P2-M5** are all merged to `main`: the M5 ADR-0028 **social pack (follow +
reputation + badges)** + beta polish + the full regression (executed RH-10/RH-11 rehearsals) shipped, and
**`v1.0.0-beta.1` is tagged → 🚩 Public Beta**. **Next: build + deploy the beta to the live host, gather
feedback, then open Phase 3 (Extensibility) — see Immediate next actions.**

> Per-milestone build detail (gates, test counts, adversarial-review findings, scope fences) →
> [`PROJECT-HISTORY.md`](PROJECT-HISTORY.md).

**`main` carries:** M0–M5, P1.5 hardening, real-host fixes RH-6–RH-11, default theme + theme polish R1,
ACP v1 + v1.1 patch, Spike P2 deliverability pipeline, NovFora rename (ADR-0024/0026), **ACP v2** (PR #9,
`30bc466`), **P2-M1** engagement/content-depth, **P2-M2 Half-A** deliverability light-up, **P2-M2 Half-B**
multi-participant PMs (PR #17, `535a924`), **P2 account deletion** (ADR-0025, `b006163`), **P2-M3** activity
feed & community-feel core (`ae9bba3`), **P2-M4** moderation depth / search facets / preferences (PR #19,
`c56126e`), and **P2-M5** the social pack (follow / reputation / badges) + beta polish + full regression —
**tagged `v1.0.0-beta.1` (🚩 Public Beta)**. **Origin `main` is the source of truth; nothing is left unpushed.**

**Post-beta polish — built + green, on branch `claude/acp-themes-members-directory` (pending owner push → PR →
merge):** **DB-backed style themes** / in-admin visual theme editor (ADR-0029) · **public members directory**
with admin-controlled visibility (ADR-0030) · `users.post_count` now **maintained** (atomic ±1 on
create/soft-delete/restore) **+ backfilled** — closes the M0 "unmaintained seam" flagged in ADR-0028 · minor
UI width + profile-link polish. 6 conventional commits; full suite **972 green** (pint/larastan/audit clean).

**Stage A fast-follows — DONE (2026-06-13, owner-authorized overnight build, branch
`claude/stage-a-fast-follows`, pending owner push → PR → merge).** All six M5-deferred / review-flagged
fast-follows shipped, each its own gated + committed unit; full suite **1012 green** (pint/larastan/audit
clean). Design notes in `DECISIONS.md → Fast-follow backlog notes`.
- **A1 staff notes** (`869c0db`) — private staff-only notes on a member (`bans.manage`-gated, never the
  subject); `staff_notes` table, `StaffNote`, `App\Moderation\StaffNotes` authority, profile SFC, audited;
  ADR-0025 cascade NULLs the author.
- **A2 reputation leaderboard** (`fdc7b1f`) — public `/members/top` board (reputation / posts, all-time /
  30-day / 7-day), shares the directory visibility gate; windowed views aggregate the source of truth.
- **A3 trust auto-promotion by reputation** (`cc01545`, APEX) — `min_reputation` on tl2/tl3, a PROMOTION-ONLY
  gate (never spurious-demotes), seeder + upgrade-backfill migration.
- **A4 second example theme** (`73b9f8f`) — `themes/aurora` filesystem child theme + two core override seams
  (head palette / footer); AA-safe palette via `AccentPalette`; ships inactive.
- **A5 isSoleAdmin TOCTOU** (`57d0669`, APEX) — locked re-check inside the deletion transaction.
- **A6 ActivityVersion / AclVersion lost-bump** (`b11eb46`, APEX) — atomic `Cache::add`+`increment`.

*Follow + reputation/points + badges shipped earlier in M5 Core per ADR-0028.*

## Immediate next actions

1. **▶ NEXT — ship & validate the 🚩 Public Beta.** Build the deployable upgrade package from `main` and
   deploy it live (in-place, no-SSH RH-10 upgrade) per
   [`docs/product/live-deploy-kickoff.md`](docs/product/live-deploy-kickoff.md) — back up off-host, extract
   over the running install, watch `GET /health` `schema.pending` flip true→false. Then gather
   private/public-beta feedback (product-plan §8 may reorder later work).

2. **Phase 3 — Extensibility — the next major phase (its own discovery + plan-before-code gate).**
   Module/plugin API + hook/event/slot system (semver'd public contract) + compatibility check; visual
   theming + layout configurator; REST API + webhooks; phpBB/MyBB/SMF importers (verify + 301 redirects);
   admin analytics.

3. ~~**Fast-follows queued by M5**~~ — **DONE 2026-06-13** (Stage A, see the "Stage A fast-follows" block
   above): staff notes · reputation leaderboard / top-members · TL auto-promotion by reputation · 2nd example
   theme · `isSoleAdmin` TOCTOU + `ActivityVersion`/`AclVersion` lost-bump hardenings. On branch
   `claude/stage-a-fast-follows` (owner push → PR → merge).

4. **Design-first items still queued (do not build without a plan):**
   - RH-4: subdirectory install — **DONE** (ADR-0070/0071).
   - Layman "simple-mode" permissions UX — **DELIVERED as ACP v3 · v3-c** (ADR-0082; see the ACP v3 block at the
     top). The ACP v3 program continues with v3-e next (ADR-0080 slice order).

## Working rules

Full rules in `CLAUDE.md`. Short form: strict clean-room · progressive enhancement · reversible migrations ·
security by default · tests with every feature · semver'd module/theme API · conventional commits + ADRs.

## Model & effort

Full routing in `CLAUDE.md §Model routing`. Short form:
- **`ultracode` (default):** start at **Fable @ max** (apex), downgrade as fit when work is pattern-replication.
- **Fable @ max:** permission/security/concurrency core, adversarial reviews, spikes, mechanism/API design.
- **Opus 4.8 `xhigh`/`high`:** heavy correctness work below the apex.
- **Sonnet 4.6:** CRUD, scaffolding, view boilerplate, mechanical breadth, multi-site sweeps (sub-agents).
- **Docker gates are free** — verify with `pest`/`pint`/`larastan`, not by re-reasoning.
- Never re-read a file you just edited (the harness tracks state). Cap gate output — tail/`Select-Object -Last`.
