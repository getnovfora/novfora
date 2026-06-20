<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# Roadmap

Canonical, living roadmap for **NovFora**. Detailed deliverables and exit criteria per phase
are in [docs/product/roadmap.md](docs/product/roadmap.md); the MoSCoW feature split is in
[docs/product/feature-prioritization.md](docs/product/feature-prioritization.md). **Every phase ends runnable +
tested on the baseline tier** (PHP 8.3 + MySQL + cron). **Plan-before-code, with approval at each phase gate.**
Phases are scoped by deliverable and dependency, not calendar.

| Phase | Theme | Headline deliverables |
|---|---|---|
| **0** | **Discovery** *(this Stage A)* | Research + architecture + product docs; ADR log; governance/living files; MVP definition. **→ Phase 0 gate.** |
| **1** | **Core MVP** | Skeleton + **service-tier detection/driver abstraction**; **no-SSH web installer**; auth; forum CRUD; **permission-mask engine**; moderation queue; **WYSIWYG editor**; **anti-spam baseline**; email/in-app notifications; mobile-first theme + override layer; Scout DB search; SEO basics; **reversible migrations + backups**. *Runs on PHP 8.3 + MySQL + cron.* |
| **2** | **Community** | Reactions; profiles + custom fields; PMs; rich + digest notifications; reports; warnings/infractions (decay, auto-consequences, ack); trust-level promotion; activity feeds; **inline moderation** + bulk select; Markdown mode; oEmbed; drafts; edit history. |
| **3** | **Extensibility** *(in progress — owner-authorized overnight build)* | **Module/plugin API + hook/event/slot system** (semver'd) + compatibility check **✅ B1 (ADR-0031)**; **visual theming + layout configurator ✅ B2 (ADR-0032)**; **REST API + webhooks ✅ B3 (ADR-0033)**; **phpBB/MyBB/SMF importers ✅ B4 (ADR-0034 — phpBB built, MyBB/SMF scaffolded)**; **admin analytics ✅ B5 (ADR-0035)**. See `docs/architecture/phase3-extensibility/`. |
| **4** | **Advanced / competitive** *(M1–M6 built — owner-authorized overnight builds; M1–M3 on `claude/phase-4-features`, M4–M6 on `claude/phase-4-enhanced`)* | **XenForo importer ✅ (ADR-0041)**. **M1 Clubs ✅** (ADR-0047–0052). **M2 SSO ✅** (ADR-0053–0056; **SAML scaffold-only**). **M3 PWA + Web Push ✅** (ADR-0057–0059). **M4 Enhanced tier ✅** (ADR-0060 Meilisearch via Scout + DB fallback; ADR-0061 **Reverb realtime + apex channel-authz no-leak fence** + polling fallback; ADR-0062 opt-in presence). **M5 Paid memberships ✅** (ADR-0063 tiers + perk gating through the engine; ADR-0064 offline/**manual provider — the live-granting path**; ADR-0065 **Stripe hosted checkout, charging DISABLED** + hardened webhook; ADR-0066 money-fenced paid-clubs hook). **M6 Advanced anti-spam ✅** (ADR-0067 **HOLD-only spam intelligence** + FP guards; ADR-0068 review surface; ADR-0069 external-signal tuning + **content-privacy fence**). See `docs/architecture/phase-4/`. ⚠ **Scaffolded, NOT validated against live services:** OAuth/SAML/Web-Push, **Meilisearch, Reverb, live Stripe payments, SFS submission** (no real credentials/services in the build env — enable steps in PROJECT-STATE + each ADR). |
| **5** | **Hardening → GA** *(complete — owner-authorized GA run on `claude/phase-5-ga`)* | **P5.1 security ✅** 2nd adversarial verify-then-refute over the whole Phase 3/4 surface — 8 MEDIUM + 5 LOW/INFO fixed, 6 refuted, no HIGH (ADR-0072, extends ADR-0046). **P5.2 WCAG 2.1 AA ✅** automated gate grown 14→27 surfaces + 3 accessible-name fixes; manual residue recorded (ADR-0044). **P5.3 i18n ✅** framework + RTL + locale-switch (ADR-0043) completed with an `es` **proof locale** + the auth/error surfaces externalised + per-key `en` fallback test; remaining view sweep is documented community-contributable residue (ADR-0073). **P5.4 perf ✅** hot-path query-count regression gate (no steady-state N+1) + documented baseline + enhanced procedure/SLOs (ADR-0074, extends ADR-0045). **P5.5 release ✅** the `nevo→novfora` rename **completed + enforced by a CI brand gate**, version → **1.0.0**, CHANGELOG + release checklist (ADR-0075). **P5.6 fresh-install ✅** from-scratch redeploy proven (FreshInstallSmokeTest: empty DB → schema + seeded posture + capable admin + lock; build-release zip clean + cold boot 302→/install) (ADR-0076). ⚠ Carries forward the **VALIDATE-BEFORE-GO-LIVE** set (Meilisearch · Reverb · live Stripe · OAuth/SAML · Web Push · SFS · at-scale load) — see `PROJECT-STATE.md`. |

**Real-host fixes (RH-series, post-beta hardening):** **RH-4 — first-class subdirectory install ✅
(ADR-0070 + ADR-0071, 2026-06-16, branch `claude/rh4-subdir-install`)** — the forum index is the canonical home
**at the mount root** (`/community/` serves the board list; `/forums` 301s to it); a conservative request-time
base-path detector + installer subpath wiring (auto-fills the Site URL, writes `APP_URL`/`ASSET_URL`); one
canonical `build/` + `storage/` via **Option A** (symlinked `public/`, default) / **Option B**
(`novfora:subdir:scaffold` stub) / **Option C** (copy, last resort); a subdirectory case + root-layout (G4) +
rebuild-drift (G2) guards in the install matrix. Recipe + Hostinger walkthrough in
`docs/REAL-HOST-VALIDATION.md` §3b.

**UI/UX polish (post-GA, branch `claude/ui-ux-nav-login-infocenter`, 2026-06-17):** three independent fixes off
`main` — a **responsive header** (CSS-only; the wordmark no longer wraps at mid widths), a **login i18n** code fix
(framework `auth.failed`/`throttle` defaults restored in `lang/en/auth.php`; the live raw-token render is a host
**deploy gap** — redeploy with `lang/` + `optimize:clear`), and a **classic Info Center** on the board index
(statistics + opt-in who's-online, aggregate-only, **no migration** — **ADR-0077**).

**PWA + i18n polish (branch `claude/pwa-i18n-polish`, 2026-06-17):** **PWA is now subpath-aware (ADR-0078)** — the
manifest `start_url`/`scope`, icon srcs, the SW registration scope, and `Service-Worker-Allowed` all derive from the
mount base, so the app installs + the service worker registers under `/community/` as well as a root (a strict no-op
at a root); adds 192/512 + maskable PNG install icons. This **resolves the ADR-0070 PWA-under-a-subpath deferral**.
The **i18n view-string sweep — wave 1 (ADR-0079)** externalizes the **forum / members / profiles** domains into
`lang/en/*.php` keys (joining auth/common/errors/search), each a gated per-domain commit with a guard test, byte-for-byte
English unchanged. Residue (clubs / settings / notifications / tags / pm / ACP admin / Livewire components) is recorded
as community-contributable, same pattern (extends ADR-0043 + ADR-0073).

**ACP v3 — admin & permission management (branch `claude/acp-v3-foundations`, 2026-06-18, owner-approved program
ADR-0080):** a multi-slice program built on the existing permission engine. **v3-0 ✅ the one additive engine seam
(ADR-0080)** — nullable, indexed `acl_entries.expires_at` + a single **authoritative** resolver expiry filter (the
cached `can()` is capped to the earliest contributing TTL, so a lapsed grant is never honoured even if the cron
lags) + the `novfora:acl:prune-expired` cron, so temporary-access delegation (v3-f) rides the one engine. **v3-h ✅
the Invision-style IA (ADR-0081)** — an icon rail of 11 sections → per-section sidebars → per-section dashboards + a
global ACP search (pages / settings / members); old admin URLs **301** to their new section homes (route names kept
stable; the Permission Inspector moved System → Security); one `admin.*` i18n group; a keyboard-navigable rail.
**v3-c ✅ the headline card-per-group permission editor (ADR-0082)** — plain-language **Yes / No / Never** over
`acl_entries` at global / forum / club scope, with a category bulk-apply; gated by the manage-permissions capability
+ a rank guard, an admin-only fence on Administration-tier keys, and a self-lockout guard on the admins group's
recovery keys (the last two caught by the apex review). **v3-e ✅ the group system (ADR-0083, branch
`claude/acp-v3-e-groups`)** — per-group **membership models** (admin / request-with-approval-queue / open-join,
each self-service join anti-spam/trust-gated) + a general **AND/OR auto-promotion** engine (promotion-only,
idempotent, legacy-flat back-compat; hourly cron + queued criterion events) + a public Groups directory (per-group
`is_public`, off by default, never leaks a hidden group or roster) + a primary-group chooser (admin override locks
it). Its apex fence is the **membership-cache seam** (`MembershipCache`, G9's sibling): a pivot join/leave/promote
changes effective permissions WITHOUT an `acl_entries` write and fires no model events, so it explicitly refreshes
the user's group signature (re-keying the per-user cache) + flushes the resolver memo + `VisibleForumIds`; routed
`TrustLevelManager` + `GroupManager` through the same helper. **v3-d ✅ the custom role builder (ADR-0084, branch
`claude/acp-v3-d-roles`)** — a Groups → **Roles** surface to build `is_preset=false` roles as a Yes / No / Never
grid over the catalog (clustered by the `group` field); system presets stay read-only; a built role applies as a
custom group's baseline (`RoleExpander::assignToGroup`). Its apex fence is **convergent re-expansion**:
`reexpand()` now DELETES keys dropped from a role off every assigned holder (the blunt upsert only ever added), so
editing a role converges everywhere (added appear, removed disappear) and deleting it retracts its whole footprint
— with the query-builder deletes bumping `AclVersion` (G9). The same escalation fence + ceiling + self-lockout
guard as v3-c gate it: only a full admin may mint/assign/tear-down an Administration-tier key, no ALLOW may exceed
the actor's own ceiling, and the admins group can never be stripped of its recovery keys (the apex review hardened
the self-lockout onto the destructive delete/unassign paths). No migration — `is_preset` already distinguishes
custom from preset. **v3-b ✅ per-forum moderator assignment (ADR-0085, branch `claude/acp-v3-b-moderators`)** — a
`moderator_assignments` table + `ForumModeratorProjector` (mirrors `ClubRoleProjector`) expands a user/group + a
capability set (three seeded preset bundles `forum-mod-full`/`-content`/`-queue`, or a custom v3-d role) into
FORUM-scope `acl_entries` through the one engine; a per-forum **Moderators** tab (3rd structure-tree button) + a
global **Moderation → Moderators** pane. Its apex fences: **grant-only** (a mod role may never carry a NEVER — the
review's finding), admin-tier refusal, the **ceiling reused at forum scope** (`assertWithinCeiling` now
scope-parameterized), and the `ActorRank` rank guard; key-scoped deletes only (G10). Each apex slice (v3-0, v3-c,
v3-d, v3-b) + the v3-e seam had an adversarial verify-then-refute review before commit. **Next: v3-a** (admin
bundles), then v3-f / v3-g per ADR-0080. Branches are **local-only** (owner pushes); v3-b is off `main` (the v3-c/d/e
stack is unmerged — v3-b reuses the engine + the v3-d role model but is independent of those branches).

**Carried-in refinements:** Laravel 13 + Livewire 4; **PHP 8.3 floor** *(revises brief's 11/3 and the 8.2
floor — flagged at the Phase 0 gate)*; no-SSH installer; coarse-cron-tolerant queue; WYSIWYG↔Livewire spike as
the #1 risk; anti-spam first-class from Phase 1; a11y/i18n baked in throughout.

**v1.0.0 release gate (ADR-0024) — MET:** the first public release is branded **NovFora** end-to-end. The
Hearth/NevoBB→NovFora rename (ADR-0026/0073) is complete down to the artisan command prefix + the editor JS
island + dev/CI infra names. The Phase 5 exit criterion — `grep -ri nevo` returns only historical ADR
references in docs — **passes and is enforced in CI** (the `static` job's "Brand gate" step). Version is
**1.0.0**. Domains + GitHub org are registered by the owner. Approaching 1.0 the owner reinstalls fresh on a
**new webhost at the new domain** (proven by the P5.6 fresh-install path) — the current validation host is
interim and is not migrated.

**Out of scope for 1.0:** multi-tenant SaaS (data-model seam kept, not built), native mobile apps (PWA
instead), in-core chat bridges (modules), marketplace payments. The architecture precludes none of them.
