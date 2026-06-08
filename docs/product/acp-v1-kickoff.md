<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The Hearth Authors
-->
# ACP v1 — admin shell, dashboard, structure manager, settings — Claude Code kickoff

> The beta is live; the operator is not self-sufficient. There is no `/admin` landing, no admin nav (the
> owner had to guess URLs), **no way to create a forum from the UI** (boards exist only because the demo
> seeder made them), and every setting requires `.env`/config hand-edits on the host. ACP v1 fixes all of
> that. Coverage benchmark: [acp-feature-taxonomy.md](acp-feature-taxonomy.md) (clean-room research —
> design stays ours). Visual language: the existing theme tokens/components per
> [theme-design-system.md](theme-design-system.md).

---

```
Build Hearth's ACP v1: a settings infrastructure, an admin shell with dashboard, a forum structure
manager, six settings pages, and the existing System panels migrated into the shell. Branch + PR with
screenshots (this is a visual cycle). THINK HARD throughout — admin surface is security-sensitive.

STEP 0: read PROJECT-STATE.md, docs/product/acp-feature-taxonomy.md, docs/product/theme-design-system.md
(+ its Blade gotchas), the existing admin SFC patterns (⚡backups/⚡upgrade — ensureAdmin + 2FA self-guard),
and routes/web.php's admin/system group. Branch from main (must include RH-11). Commit identity per
CLAUDE.md (Tommy Huynh <tommy@saturnhq.net>, DCO -s, no AI attribution). Suite green before you start.

PART 0 — SETTINGS INFRASTRUCTURE (load-bearing; build + test first):
  • A `settings` table (key, value, type) + reversible migration; a typed Settings service with ONE
    cached read of the whole bag per request (scalars/arrays only in cache — RH-9 rule), write-through
    invalidation, seeded defaults, and documented precedence: DB setting → env() fallback → config default.
  • Every settings write is audit-logged (who, key, old→new; secrets masked).
  • Sensitive values (e.g. SMTP password) stored ENCRYPTED via the app key; never echoed back in forms
    (placeholder semantics), never logged. Larastan-clean typed accessors.

PART 1 — ADMIN SHELL + DASHBOARD (the mockup the owner approved):
  • `/admin` = dashboard. Persistent left nav, grouped: Dashboard · Settings (General, Registration,
    Email, Moderation, Anti-spam, Appearance) · Members (Users & groups → existing pages/links,
    Permissions, Custom fields) · Content (Forums & structure, Word filters if an admin surface exists) ·
    System (Service tier, Backups & restore, Upgrade, Audit log, Tasks). Moderation links out to the MCP
    pages (give those pages the same shell/nav treatment — restyle-wrap only, no behavior change).
  • Dashboard composition: pending-actions row (approval queue count, open reports count — links to MCP;
    schema/upgrade status chip) → stat cards (members, topics, posts) → health strip (database/cache/
    queue/schema/backup age, from the existing health internals) → recent audit entries (last ~8).
    Every number O(cache-read) or cheap count — respect the perf budgets.
  • ACP quick search: a client-side filter over a built index of admin pages + settings labels (jump to
    page/anchor). No server search engine — keep it cheap (taxonomy: SMF/Invision's most-praised ergonomic).
  • AUTHZ: every admin route/SFC gates on admin.access + 2FA (the established self-guard pattern);
    add a route-level test that walks EVERY /admin route as non-admin → denied.

PART 2 — FORUM STRUCTURE MANAGER (the owner's #1 ask; mockup approved):
  • Tree view: categories → boards → sub-boards (model already supports nesting). Create/edit (name,
    description, parent, position), reorder (position up/down is sufficient; drag optional), per-board
    link to the permission inspector scoped to that node.
  • DELETE SAFETY (binding rule): deleting a node with content requires choosing a destination board —
    a "move contents" flow reusing the existing topic-move service; categories require empty/relocated
    children first. Never silent destruction. Counters recompute; audit-logged.
  • Creating a board applies sane default permissions (document which) so it is immediately usable.

PART 3 — SETTINGS PAGES (forms on PART 0; each page = one focused Livewire SFC or controller form):
  1. General — site name + description (DB-backed, env fallback), site notice (shown site-wide when set),
     board offline toggle + message (guests see maintenance-style notice; admins pass).
  2. Registration — registration enabled on/off; email-verification requirement toggle ONLY if it maps to
     an existing mechanism; show current anti-spam gates read-only here. (Approval/invite modes = Phase 2;
     do not build.)
  3. Email — from name/address, mailer selection + SMTP fields (encrypted password), and a SEND TEST
     EMAIL button (queued send to a typed address; result surfaced). Reads current env as initial values;
     DB overrides take precedence per PART 0.
  4. Moderation defaults — new-user first-post hold count (0 = auto-post; also honor a
     HEARTH_NEW_USER_HOLD_POSTS env fallback — the owner's live preference), suspicious-score threshold,
     edit-time/flood limits if existing knobs map cleanly.
  5. Anti-spam — captcha provider (qa/turnstile + keys), SFS live-API toggle, blocklist info read-only.
  6. Appearance (site-level; distinct from per-user) — active theme select (themes dir scan), accent
     color (token override emitted as CSS variables, light+dark safe, AA-checked), forum width
     (boxed-narrow/standard/wide/full via the --layout-max-width token), default color mode + density
     for visitors, poster-info position (top/left/right; left = current default), board-list style
     (info-rich/minimal), wordmark text.
  • Topic view + board views read the appearance settings (poster position, width, board-list style) —
    presentation switches only, no markup-contract breaks; Dusk selectors preserved.

PART 4 — SYSTEM SURFACE COMPLETION:
  • Migrate service-tier, permissions, backups(+restore), upgrade, profile-fields pages into the shell
    (nav + breadcrumbs; behavior unchanged).
  • NEW: Audit log viewer — paginated, filterable (action prefix, actor, date), read-only.
  • NEW: Tasks page — read-only list of scheduled tasks (name, cadence, last-run where knowable from
    existing heartbeats/locks) — MyBB-style visibility into the cron machinery.

GATES (all binding): tokens only, AA both modes, mobile-usable admin (360px: nav collapses; tables
reflow) · full Pest + the new authz walk + settings/service/structure tests (incl. move-contents flow and
the encrypted-secret round-trip) · Pint/Larastan/audit · assets rebuilt + assets-fresh green · CSS budget
reported · Dusk journeys stay green; ADD a minimal admin Dusk journey (login → dashboard → create a
board → see it on the public index) · SCREENSHOTS in the PR: dashboard, structure manager, one settings
page, audit viewer — light/dark × desktop/mobile · bundle rebuild + verify; report size + sha256 ·
PROJECT-STATE + an ADR for the settings-precedence design. Conventional DCO commits per area.

SCOPE FENCE: NO analytics, NO theme/layout configurator, NO mass email, NO plugin manager, NO new
registration modes, NO member-edit forms beyond what exists (link to existing pages) — those are
Phase 2/3 per the taxonomy mapping. If a settings knob has no existing mechanism behind it, DO NOT
invent the mechanism — flag it.

ENVIRONMENT NOTE: if you build assets or run tools in Docker bind-mounted to /mnt/d, container-created
files can be left ACL-cursed on NTFS (documented in DEVELOPMENT.md troubleshooting). Prefer running with
the worktree on WSL-native disk, or chown/clean container artifacts before finishing.
```

---

## After this

The operator is self-sufficient: boards, settings, email, moderation defaults, appearance — all from the
panel. Next per the build list: the community-feel pack (info center, who's-online, staff colors), then
the simulator, then RH-4. Owner note: after merge + deploy, flip "new-user first-post hold" to 0 from the
new Moderation settings page (replaces the temporary config-file edit, which the deploy overwrites).
