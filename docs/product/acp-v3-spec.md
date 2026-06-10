# ACP v3 — Full Design Spec

> **Status:** Handoff-ready for Opus 4.8 (xhigh) implementation planning  
> **Compiled:** 2026-06-10  
> **Scope:** Admin hierarchy, granular permissions, moderator assignment, custom roles, group system, delegation, staff flair  
> **Phase:** Phase 2 — plan before implementation, wait for approval before any code

---

## 1. ACP Navigation & Layout

### Pattern

Invision Community v4's ACP nav is the direct UX reference for structure (not code or assets):

```
[ Icon Rail ] → [ Section Sidebar ] → [ Section Dashboard / Content ]
```

- **Icon rail** (far left, ~10 icons): top-level module sections — System, Forums, Members, Groups, Moderation, Appearance, Plugins, Analytics, (root-only: Security/Audit)
- **Section sidebar**: expands when an icon is active; shows section sub-pages grouped into labeled clusters (e.g. OVERVIEW / SITE FEATURES / SETTINGS)
- **Section landing = customizable dashboard**: every top-level icon has its own dashboard as its default view, populated with widgets relevant to that section
- **Global search bar** at top: searches settings, users, forum names, ACP pages
- **Quick-action `+ Add` button**: context-aware (changes based on active section)

### Per-Section Customizable Dashboards

Each dashboard is independently configurable. Admin-level users can add, remove, and rearrange widgets within their role's permission boundary. Widgets not accessible to the current role are hidden automatically.

| Section | Example default widgets |
|---|---|
| **System** | Server health, recent error log entries, pending updates, admin notes |
| **Moderation** | Open reports (count + recent), post approval queue, bounce review count, delegated tasks |
| **Members** | New registrations (chart), pending applications, suspension queue |
| **Groups** | Auto-promotion activity, group join requests |
| **Forums** | Post volume chart, most active forums, pending prefix/tag requests |
| **Appearance** | Active theme, pending customizations, CSS error log |
| **Analytics** | Traffic overview (if integrated), post/reply counts, active user count |

Root Admin's System dashboard additionally surfaces: active delegations expiring within 7 days, permission inspector last-run results, bounce suppression list size.

---

## 2. Root / Founder Admin

### Initial Establishment

The install wizard crowns the first user who completes setup as Root Admin. This is automatic — no manual DB step required.

### Privileges

Root Admin is the only role that:
- Cannot be removed by any other admin (only transferred)
- Can create/delete other admin accounts
- Can modify the Admin Manager role
- Has access to the Security & Audit ACP section
- Can view and use the Permission Inspector
- Can approve or deny temporary access delegations at the Root level
- Can configure the maximum delegation duration cap

### Transfer Protocol (all four safety rails required, in sequence)

1. Root initiates transfer via ACP → Security → Transfer Root Admin
2. **Email confirmation** sent to Root's address; must click to proceed
3. **Recipient must accept** — they receive a notification and must click Accept in their own ACP session
4. **24-hour cooling-off period** — either party can cancel during this window; visible countdown in both parties' dashboards
5. After 24 hours: Root must **re-enter password** to confirm. Transfer completes and is logged.

Root Admin cannot be delegated — only formally transferred.

---

## 3. Admin Permission System

### Hierarchy

```
Root Admin
  └─ Admin Manager (delegated by Root)
       └─ Admins (full or restricted)
```

### Admin Permission Bundles

When creating or editing an admin account, start with a bundle as a baseline, then customise:

| Bundle | ACP access |
|---|---|
| **Full Admin** | Everything except Security/Audit (Root-only) |
| **Community Admin** | Forums, Members, Groups, Moderation |
| **Style Admin** | Appearance only |
| **Content Admin** | Forums + Moderation (no user management) |
| **Analytics Admin** | Analytics read-only |
| **Custom** | Blank — build from scratch |

Each ACP module/section is individually toggleable once a bundle is applied. Bundles are starting points, not locked containers.

### Admin Manager Role

- Granted by Root only
- Can create, edit, and revoke admin accounts (within their own permission ceiling — cannot grant permissions they don't have)
- Cannot touch Root Admin account
- Cannot modify the Admin Manager designation itself
- Actions appear in unified audit log

---

## 4. Moderator System

### Tier Architecture

Two distinct tiers, both existing simultaneously:

| Tier | How assigned | Scope |
|---|---|---|
| **Global Moderator** | Membership in `moderators` system group | All forums on the board |
| **Forum Moderator** | Per-forum ACL entry (scoped assignment) | Specific forum(s) only |

### Moderator Assignment Surfaces

Assignment can happen from either surface — both stay in sync (single source of truth):

1. **Forum editor** (ACP → Forums → edit a forum → Moderators tab): assign users or groups as forum-level moderators
2. **User profile** (ACP → Members → edit user → Moderation tab): assign that user as moderator for one or more forums
3. **Dedicated Staff → Moderators page** (single pane of glass): shows all moderator assignments across all forums in one table; add, edit, remove from here

### Custom Moderator Capabilities

Each per-forum assignment specifies exactly which actions are permitted. These are per-assignment, not per-group — the same user can have different capabilities in different forums.

Default capability groups (all individually toggleable):

**Content actions:** Delete posts · Edit posts · Move topics · Lock/unlock topics · Pin/unpin · Merge/split topics · Approve/reject pending posts

**User actions:** Warn users · Issue formal warning with note · Mute (post restriction) · Temp-ban from forum

**Queue actions:** Process post approval queue · Triage reports in this forum

**Visibility actions:** Move to trash · Soft-delete · Restore soft-deleted

Global Moderators have all capabilities by default but their defaults can also be customised at the group level.

### Permission Inspector

- Available to Root Admin and Admin Managers only
- Shows the resolved effective permissions for any user in any forum context
- Traces the chain: group memberships → ACL entries → NEVER overrides → final mask
- Read-only; does not modify anything
- Logged whenever used (who ran it, for which user, at what time)

---

## 5. Permission Interface (UI)

### Structure

Every group — system groups and custom groups alike — has its own permission configuration section. No group is excluded.

### Layout: Card per Group, Plain-Language Toggles Within

The permission UI uses a **card-per-group** approach at the forum level:

- Each group (Guests, Members, Trusted Members, Moderators, + all custom groups) gets a collapsible card
- Expand a card to reveal that group's permission rows
- Each row reads as a plain-language sentence: *"Members can create new topics"*
- Each row has a three-state toggle: **Yes** · **No** · **Never**
  - Yes = ALLOW (can be overridden by a higher-priority group's Yes)
  - No = unset (inherits from global/category default)
  - Never = hard block (cannot be overridden by any group membership)
- Cards are collapsed by default; the most-configured group expands first

### Global vs. Category vs. Forum Inheritance

Permissions flow: Global defaults → Category overrides → Forum overrides  
A forum-level Never cannot be overridden by group membership.  
Highest-priority group wins when multiple groups conflict at the same scope level (group priority is set in Groups → manage group priority order).

### ACP Permission Pages

- **ACP → Groups → [group name] → Permissions**: global permission defaults for that group
- **ACP → Forums → [forum] → Permissions**: per-forum overrides, cards per group

---

## 6. Group System

### System Groups (read-only, cannot be deleted)

`guests` · `members` · `moderators` · `administrators` · `tl0` through `tl4`

### Custom Groups

Admins can create unlimited custom groups. Each custom group configures:

**Membership model** (select one per group):

| Model | Behaviour |
|---|---|
| Admin-assigned only | Members added/removed manually via ACP or user profile |
| Request + approval queue | Users request to join; admin or designated approver reviews |
| Auto-promotion | System automatically promotes users who meet configured criteria |
| Open join | Users can join freely from the public Groups page (if enabled) |

**Auto-promotion criteria** (all four available; combine with AND/OR logic):
- Post count threshold
- Account tenure (days since registration)
- Trust Level engine score
- Reaction / reputation score

### Primary Group & Display

- Each user has exactly one primary group (determines rank badge, group colour, title shown under avatar)
- **Both user and admin can choose** the primary group from groups the user belongs to
- Admin override takes precedence if set; otherwise user's own selection is respected
- Staff can always see all group memberships regardless of primary display

### Public Groups Page

- Optional feature — off by default
- Admin toggles visibility per group (some groups can be public, others hidden)
- Public groups page shows group name, description, member count, and join button (for Open join groups)

---

## 7. Custom Role Builder

Admin creates custom roles from a blank slate. Permission keys are grouped into logical clusters for readability:

**Cluster examples:**
- Content: Post, Edit own, Edit others, Delete own, Delete others, Upload files, Embed media
- Topics: Create, Lock, Pin, Move, Merge, Split
- Moderation: Approve posts, Process reports, Issue warnings, Mute users
- Tags & Prefixes: Apply existing, Mint new (TL-gated)
- Reactions: Give reactions, Receive reactions
- Polls: Create, Vote, View results before voting ends

Built roles can be:
- Assigned as a custom group's permission baseline
- Applied as a per-forum moderator capability set
- Used as an admin permission bundle baseline

---

## 8. Staff Identity & Flair

All four flair options are available and independently toggleable per group:

1. **Group colour** — username rendered in group colour (already built in current codebase)
2. **Title / rank badge** — custom text displayed below avatar (e.g. "Senior Moderator", "Founder")
3. **Staff icon badge** — small icon/badge appended to username inline in posts and thread listings
4. **Staff roster page** — public-facing `/staff` page listing all users with staff-designated groups; groups control which appear on this page

Staff flair respects primary group selection — flair shown is from the user's active primary group.

---

## 9. Delegation System

Two axes, both implemented:

### Task Delegation (Moderation Queue Assignment)

- Reports, post-approval queue items, and bounce review items are assignable to a named staff member
- Assignee sees a filtered "Assigned to me" queue in their Moderation dashboard widget
- Unassigned items remain in the general pool visible to all eligible staff
- Assignment is logged (who assigned, to whom, when, which item)
- Items can be reassigned or unassigned

### Temporary Access Delegation

- Any role-holder (admin or moderator) can delegate their own access level to another user
- Delegation requires:
  - Specifying recipient
  - Setting a mandatory expiry date (min: 1 hour, max: configurable by Root Admin; suggested default cap 30 days)
  - Optional note (visible in audit log)
- Delegated access is additive — recipient gains delegator's permissions on top of their own for the duration
- Delegation shows as a banner on recipient's ACP sessions: *"You have delegated access from [name] until [date]"*
- Revokes automatically at expiry; can be manually revoked early by delegator or Root/Admin Manager
- All delegations are visible to Root Admin and Admin Managers in Security → Active Delegations
- **Root Admin cannot be delegated** — only formally transferred (see §2)

---

## 10. Post Approval Queue

Three surfaces, all backed by the same underlying queue:

1. **Inline in-forum** — when browsing a forum they moderate, a mod sees pending posts inline with a quick Approve / Reject action
2. **Moderator panel queue** — `/modcp/queue` style page showing all pending posts across the mod's assigned forums, filterable by forum
3. **ACP Moderation dashboard widget** — admin-level view of total pending count with link to full queue; can be filtered to scope

Queue items include: post content preview, author, forum, thread, submission time, and any existing mod notes.

---

## 11. Content Lifecycle on Account Events

### Ban or Delete

- Posts remain on the board (content is not destroyed)
- Username is replaced with `[Deleted]` on all posts
- Avatar replaced with default guest avatar
- Profile page redirects to 404 or shows minimal "Account removed" message
- Reactions and poll votes remain counted but are anonymised

### Cascade on full account deletion (owner-confirmable)

- Reactions, poll votes, and tags hard-delete with the owner
- Cascade is owner-confirmable — the user is shown what will be removed before confirming deletion
- This is the pre-req for M2 Half-B (PMs); the §6 ADR must lock this before PM implementation begins

---

## 12. Unified Audit Log

Single log table covering all mod and admin actions. Visibility is scoped by role:

| Viewer | Sees |
|---|---|
| Forum Moderator | Actions in their assigned forum(s) only |
| Global Moderator | All mod-tier actions across all forums |
| Admin | All mod actions + all admin actions within their permission scope |
| Admin Manager | All mod + admin actions |
| Root Admin | Everything, including security/delegation/transfer events |

Log entries include: actor, action type, target (post/user/forum), timestamp, scope, any associated note.

---

## 13. Open Questions / Deferred Items

- **§6 account-deletion ADR** — must be decided and merged before M2 Half-B (Multi-participant PMs) can start. Cascade behaviour above (§11) is the proposed resolution; needs formal ADR write-up and approval.
- **Delegation duration cap default** — Root-configurable; 30 days is the suggested default but needs a UX decision on whether the cap is surfaced to delegators.
- **Permission Inspector UI placement** — described as ACP → Security, but should confirm this is only reachable by Root + Admin Manager (not via standard admin nav).
- **Auto-promotion AND/OR logic** — user confirmed "all four criteria" with combination support; the exact AND/OR builder UI is a separate detail-design task.

---

## 14. Implementation Notes for Opus

### Routing

This is an **Opus 4.8 `xhigh` task**. The spec touches:
- `acl_entries` / `PermissionResolver` / ALLOW/NO/NEVER semantics (permission masks)
- New DB tables: `admin_permissions`, `moderator_assignments`, `custom_roles`, `staff_delegations`, `audit_log`
- Security boundaries: permission inspector must be gated; delegation must not exceed delegator's ceiling

### Prerequisites

1. §6 account-deletion ADR (blocks M2 Half-B but not ACP v3)
2. Read current `GroupManager`, `PermissionResolver`, `acl_entries` schema before designing new tables — build on existing abstractions, do not replace them

### Suggested Phase Breakdown for Opus Planning

| Slice | Contents |
|---|---|
| **v3-a** | Root Admin establishment (install wizard) + admin permission bundles + Admin Manager role |
| **v3-b** | Per-forum mod assignment UI (all three surfaces) + custom mod capabilities |
| **v3-c** | Permission UI redesign (card-per-group, plain-language toggles) |
| **v3-d** | Custom Role Builder |
| **v3-e** | Group system expansion (membership models, auto-promotion) |
| **v3-f** | Delegation system (task assignment + temporary access) |
| **v3-g** | Staff flair (title/badge/icon/roster) + permission inspector |
| **v3-h** | ACP nav restructure (icon rail + section sidebar + per-section dashboards) |

Slices are suggested — Opus should validate ordering against DB migration dependencies before locking the plan.

### ACP Nav Reference

Study Invision Community v4's ACP layout (publicly documented) for UX pattern only:
- Icon rail → Section sidebar → Per-section customizable dashboard
- Do not copy markup, CSS, JS, or assets — reimplement in Blade/Alpine/Tailwind
