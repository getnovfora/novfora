<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# ACP v3 · v3-0 Foundations (lock before any feature slice)

> **Status:** Planning draft for owner approval (Cowork; the only *code* here is the `acl_entries.expires_at`
> engine seam in §5, which Code lands as the first gated commit). Reads with
> [`acp-v3-kickoff-refresh.md`](acp-v3-kickoff-refresh.md) (the program) and the spec
> [`acp-v3-spec.md`](acp-v3-spec.md). **This note locks the two shared artifacts every later slice depends on:**
> the **section taxonomy** (§3, shared by the nav shell v3-h and the admin bundles v3-a) and the
> **`expires_at` engine seam** (§5, that delegation v3-f rides). Nothing in slices v3-a…v3-h starts until this is
> approved.
>
> **Numbering:** 0078/0079 are merged → **ADR-0080 = the parent (this v3-0)**; slice ADRs **0081…**.

---

## 1. The binding guardrails (the contract — full text in the kickoff §1)

G1 one engine — every construct **expands into `acl_entries`**, read back through `PermissionResolver`; no
parallel evaluation. · G2 **global / forum / club** scope everywhere. · G3 additive, reversible migrations. ·
G4 `PermissionInspector` is the **test oracle** for every write path. · G5 apex security fences (delegation
ceiling, last-owner guard, inspector co-owner-gated, ACP sections gated by real keys). · G6 **Fable @ max** for any
`acl_entries`/resolver/delegation slice. · G7 i18n `admin.*` from day one. · G8 **never** name a `lang/en/<group>.php`
that case-collides with a bare `__('Word')` string-key (learned ADR-0079 — it 500s on the case-insensitive mount).

---

## 2. Engine abstractions to BUILD ON (do not reinvent — all exist today)

| Use | Class | Note |
|---|---|---|
| Resolve a verdict | `App\Permissions\PermissionResolver` | ALLOW/NO(neutral)/NEVER; the only thing that reads `acl_entries` |
| Explain / test a verdict | `App\Permissions\PermissionInspector` | the **oracle** — assert verdict + trace, never a re-impl |
| Three-state value | `App\Permissions\PermissionValue` | ALLOW +1 / NO 0 / NEVER −1 |
| Scope chain | `App\Permissions\Scope` · `ScopeChain` | global → forum → **club** (Phase 4) |
| Roles → entries | `App\Permissions\RoleExpander` | idempotent `updateOrCreate`; **custom roles (v3-d) expand the same way** |
| Club role → entries | `App\Clubs\…\ClubRoleProjector` | **the template for `moderator_assignments` (v3-b)** |
| Cache version | `App\Permissions\AclVersion` | bump on any entry write/expiry so memo + caches refresh |
| Upgrade re-provision | `App\Permissions\PermissionSync` | **additive only**; how new `admin.*` keys reach existing installs |
| Sole-owner guard | the `isSoleAdmin` locked re-check (Stage A A5 / ADR-0025) | **the pattern for the co-owner last-owner guard (§6)** |

Catalog/seeds: `PermissionCatalogSeeder` · `RoleSeeder` · `GroupSeeder` (system groups `guests/members/moderators/
administrators/tl0…tl4`).

---

## 3. ACP section taxonomy — THE shared artifact (locks v3-h nav + v3-a bundles)

The Invision-style icon rail. **Each rail item = one section = one `admin.<section>.access` permission key** (§4)
= one landing dashboard (per-section widgets). Sub-pages are grouped clusters in the section sidebar.

| Rail section | Key | Sub-pages (clusters) | Access |
|---|---|---|---|
| **Overview** | *(implicit — any admin)* | the global home dashboard | any admin |
| **Forums** | `admin.forums.access` | Structure (tree) · Prefixes · Tags · *(per-forum editor: Settings / **Permissions** (card-per-group overrides) / **Moderators**)* | std |
| **Members** | `admin.members.access` | Member search/edit · Directory (visibility) · Profile fields · Badges · Tiers · Memberships (grants) | std |
| **Groups** | `admin.groups.access` | Groups + custom groups · Membership models + **AND/OR auto-promotion** · **Group permissions (card-per-group GLOBAL editor)** · **Custom Role Builder** · Group priority order | std |
| **Moderation** | `admin.moderation.access` | Report queue · Post-approval queue · Spam-intelligence · **Moderators (single-pane assignments)** · Word filters · Moderation settings | std |
| **Appearance** | `admin.appearance.access` | Appearance · Themes · Templates · Layout & widgets | std |
| **Plugins** | `admin.plugins.access` | Modules/plugins · Webhooks | std |
| **Analytics** | `admin.analytics.access` | Analytics dashboard | std |
| **Settings** | `admin.settings.access` | General · Registration · Email · Anti-spam · Clubs · SSO · Search · Payments | std |
| **System** | `admin.system.access` | Service tier · Backups · Upgrade · Email suppressions · Audit log · Scheduled tasks | std |
| **Security** | `admin.security.access` | **Co-owners** · Admin accounts + bundles (Admin Manager) · **Active Delegations** · **Permission Inspector** · Security/transfer audit | **co-owner only** |

**The headline "simple mode"** (card-per-group editor, v3-c) has **two homes on the same data:** GLOBAL defaults
under **Groups → Group permissions**, and per-forum overrides under **Forums → (forum) → Permissions** (+ the bulk
"apply to every forum in this category" action). Club-scope card editing lives on the club's own manage screen.

### Current ACP route → new section (the v3-h move map)

`admin.dashboard`→Overview · `admin.structure`/`prefixes`→Forums · `members.directory`/`profile-fields`/`badges`/
`tiers`/`memberships`→Members · `members.groups`→Groups · `spam-intelligence`→Moderation · `settings.appearance`/
`themes`/`templates` + `layout`→Appearance · `modules`/`webhooks`→Plugins · `analytics`→Analytics ·
`settings.{general,registration,email,antispam,clubs,sso,search,payments}`→Settings · `system.{tier,backups,
upgrade,suppressions,audit}`/`tasks`→System · `system.permissions` (inspector)→**Security**. Old routes **301**
to their new homes (keep names stable where possible; the nav is chrome, the pages move).

---

## 4. `admin.<section>.access` key catalog + bundles (feeds v3-a)

**Keys (one per rail section, global scope):** `admin.forums.access` · `admin.members.access` ·
`admin.groups.access` · `admin.moderation.access` · `admin.appearance.access` · `admin.plugins.access` ·
`admin.analytics.access` · `admin.settings.access` · `admin.system.access` · `admin.security.access`
(co-owner-only). The umbrella `admin.access` (exists today) remains the "can reach the ACP at all" gate; the
section keys gate each rail item. Seeded in `PermissionCatalogSeeder` and reach existing installs via
`PermissionSync` (additive) — land **with v3-a** so no key is orphaned before bundles consume it.

**Bundles (starting points, then per-key toggle):** Full = all except `security` · Community = forums/members/
groups/moderation · Style = appearance · Content = forums/moderation · Analytics = analytics(read) · Custom =
blank. Bundles are seeded `custom_roles`-style sets of section keys (reuse the v3-d builder).

---

## 5. Engine seam — `acl_entries.expires_at` (the ONE foundational code item; APEX)

Delegation (v3-f) is **a TTL on `acl_entries`** (owner decision). The minimal, additive enabler:

1. **Migration (additive, reversible):** add nullable `expires_at TIMESTAMP NULL` to `acl_entries`, **indexed**
   (composite with the existing holder/scope lookup + a plain index for the sweep). Existing rows = `NULL` =
   never-expire = **byte-identical behaviour**.
2. **Resolver filter (the apex edit):** every `acl_entries` read gains `AND (expires_at IS NULL OR expires_at >
   :now)`. This is the **only** change to the resolver's core read path. It must not alter any verdict for
   `NULL` rows. The **filter is authoritative** — even if the cron sweep lags, an expired grant is never honoured
   (defence-in-depth).
3. **Cron prune (hygiene, cron-tolerant):** `novfora:acl:prune-expired` — overlap-guarded, restore-skipped,
   hard-deletes `expires_at <= now()`, **bumps `AclVersion`** so memo/caches refresh. Baseline-safe (DB + cron).
4. **Writes bump `AclVersion`:** granting or expiring a row invalidates the resolver memo + visibility caches
   (existing mechanism).
5. **Tests (G4 — inspector as oracle):** extend the truth-table suite with an entry that is (a) active vs
   (b) past-expiry → assert the inspector verdict flips and the trace shows the expiry rule. Adversarial: an
   expired NEVER must not resurrect an ALLOW; a not-yet-expired delegated ALLOW must respect a standing NEVER.

**The delegation invariant (v3-f, recorded here so the seam is built for it):** a delegated grant writes
`acl_entries` rows with `expires_at` **and** passes a **ceiling check** — the recipient's resulting mask at the
delegated scope never exceeds the **delegator's current** mask (re-validated at write; Root/co-owner cannot be
delegated). This is the apex security test of v3-f.

---

## 6. Co-owner model + last-owner guard (feeds v3-a)

**Decision:** **multiple co-owners**, no single Root, no transfer protocol. Co-owners hold top-level power
(create/edit/revoke admins **and each other**) and are the only holders of `admin.security.access` + the
Permission Inspector. The installer crowns the **first** user as the initial co-owner.

- **Representation:** a co-owner marker on the account (a flag / a `co-owners` designation on the administrators
  membership) — the exact column is a v3-a detail, but it is **boolean top-tier**, not a new scope.
- **Last-owner guard (apex):** any demote / remove / account-deletion of a co-owner runs a **locked re-check
  inside the transaction** (reuse the `isSoleAdmin` TOCTOU pattern, Stage A A5 / ADR-0025) that refuses to drop
  the board below **≥ 1 co-owner**. Removing a co-owner requires an explicit confirmation.

---

## 7. i18n + collision rule (G7/G8)

Everything ACP ships under **`admin.*`** (+ shared **`common.*`**) from the first commit. **Before adding any
`lang/en/<group>.php`,** confirm no bare `__('<Group>')` string-key exists with that name (case-insensitive) — the
ADR-0079 trap. The existing "ACP admin" i18n residue **folds into v3** (these views are rebuilt/moved by v3-h);
don't sweep them separately.

---

## 8. v3-0 deliverable boundary + gate

- **CODE (apex, gated):** the §5 `expires_at` seam only — migration + resolver filter + `novfora:acl:prune-expired`
  + truth-table/inspector tests. Its own gated, DCO commit; **Fable @ max**; an apex adversarial review of the
  resolver edit. Gate: `php artisan test --parallel` · `pint` · `phpstan` L5 · `migrate` (apply **and** rollback).
- **PLAN (no code):** §3 taxonomy, §4 key catalog/bundles, §6 co-owner design, the route→section map — these are
  the locked inputs to v3-a and v3-h.
- **ADR-0080 (parent):** records the guardrails, the taxonomy, the `expires_at` decision + the delegation ceiling
  invariant, and the co-owner/last-owner-guard.

**Unblocks:** **v3-c** (card-per-group editor — needs only the engine, builds into Groups/Forums sections),
**v3-h** (nav shell + section IA — consumes §3), and **v3-a** (bundles — consume §4 + §6). Suggested first build:
land the §5 seam, then v3-h's shell + §3 IA, then v3-c into its new home.
