<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The Hearth Authors
-->
# ACP v2 — member-group manager + staff/group colors — Claude Code kickoff

> First-beta operator feedback flagged two real gaps: **no UI to manage member groups / group permissions**
> (mod, global-mod, admin, custom) and **no staff/group name colors**. Group management is *table stakes*
> per the [ACP taxonomy](acp-feature-taxonomy.md) and is operationally needed once real users arrive (e.g.
> promoting a beta tester to moderator). This cycle delivers both. **Not** beta-gated — it addresses
> confirmed operator gaps, not speculative engagement features. The **layman-friendly permissions "simple
> mode"** is a SEPARATE design-first cycle (see §After) — not in scope here; this cycle assigns permissions
> through the EXISTING engine/inspector.

---

```
Build the ACP member-group manager + staff/group name colors, in the existing admin shell. Branch + PR
with screenshots. THINK HARD — this is permission-adjacent, security-sensitive. Run in a PHP-capable env
if available (so Pest/Pint/Larastan self-verify before push — ends the CI-first-failure cycle).

STEP 0: read PROJECT-STATE.md, the permission-mask engine (ADR-0006: ALLOW/NO/NEVER, NO=neutral), the
Group/Role/RoleAssignment/RolePermission models + GroupSeeder/RoleSeeder, the existing admin
⚡permission-inspector SFC + the admin shell/nav (ACP v1), and the ACP authz-walk + admin-render mirror
tests. Branch from main (post-spike-merge). Commit identity per CLAUDE.md (Tommy Huynh
<tommy@saturnhq.net>, DCO -s, no AI attribution). Suite green before starting.

PART 1 — MEMBER-GROUP MANAGER (Admin → Members → Groups, in the shell):
  • List all groups (seeded staff groups + trust-level groups + custom), each showing member count, type
    (staff / trust-level / custom), and its role/colour.
  • Create / edit / delete CUSTOM groups (name, description, colour, the role/permission preset it maps to).
    Seeded system groups (Guests, trust-levels, the staff roles) are editable for colour/label but their
    structural identity is protected — do not allow deleting or re-typing a system group (it would break
    the permission seeds / trust engine). Document which groups are system-protected.
  • DELETE SAFETY (binding, mirrors the structure-manager pattern): deleting a group with members requires
    reassigning them first (or it refuses) — never orphan a user's group membership. Recompute anything
    denormalized; audit-log the change.
  • MEMBERSHIP: add/remove users to/from a group (search users; bulk-add acceptable). A user's trust-level
    group stays engine-managed (don't let manual membership fight the trust recompute — document the
    boundary: manual membership is for custom/staff groups; trust groups are auto-assigned).
  • GROUP PERMISSIONS: assign a group's permissions THROUGH THE EXISTING engine (reuse/link the permission
    inspector, scoped to the group) — NO second permission system, NO new mask semantics. Granting someone
    "moderator" = adding them to the moderator group / assigning the moderator role via the engine. The
    NEVER-can't-be-lifted-by-ALLOW rule and global-vs-forum scope are unchanged.
  • Every group/membership/permission change is audit-logged and gated on admin.access + 2FA (the
    established self-guard); add these pages to the admin-render mirror + the authz-walk.

PART 2 — STAFF / GROUP NAME COLOURS (the community-feel "Part B" item):
  • A colour per group (set in PART 1). Render the user's name in their group's colour wherever names
    appear: posts (poster sidebar/top-bar), topic/board last-post, member references, online lists, the
    user menu. A user in multiple coloured groups resolves to a documented priority (e.g. highest-rank /
    explicit display-group) — define and test the resolution rule.
  • Colours are TOKENS / AA-checked in light AND dark (don't hardcode hex that fails a mode); a group with
    no colour renders as the normal --ink. No layout/markup-contract break; Dusk selectors preserved.

GATES (binding): tokens only + AA both modes + mobile-usable (360px) · full Pest + new tests (group CRUD,
the delete-safety reassignment flow, membership add/remove, group-permission assignment routes through the
engine and authorizes correctly, the colour-resolution rule, the system-group protection) · the ACP
authz-walk + admin-render mirror EXTENDED to the new pages · Pint/Larastan/audit · assets-fresh green ·
CSS budget reported · Dusk + screenshots (groups list, group edit, a coloured post) light/dark × desktop/
mobile · bundle rebuild + verify (size + sha256) · PROJECT-STATE updated. Small conventional DCO commits.

SCOPE FENCE: group manager + group colours + their tests only. NO layman "simple-mode" permissions
redesign (separate design-first cycle), NO new permission semantics, NO ranks/titles, NO mass-membership
import, NO Phase-2 engagement features. If a group/permission action has no existing engine mechanism,
DO NOT invent one — flag it.
```

---

## After this

- **Layman-friendly permissions (design-first cycle):** a "simple mode" layered over the mask engine —
  per-board "who can see / post / reply" dropdowns + reusable permission *roles* (the phpBB concept users
  praise), with "advanced" opening the full tri-state inspector. This deserves an **ADR + a design pass
  before code** (a confusing or subtly-insecure permissions UX is worse than none) — its own cycle, not
  rushed into this one.
- **Remaining community-feel pack** (info-center/forum stats, view-count incrementing) + the Phase-2
  engagement core stay gated on private-beta feedback (plan §8).
