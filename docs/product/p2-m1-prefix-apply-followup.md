<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# P2-M1 fast-follow — `prefix.apply` authorization gate — Claude Code kickoff

> **Why:** the Cowork P2-M1 review found that **applying a prefix on topic creation is not permission-gated** —
> any `topic.create` holder can attach any of a forum's prefixes, so a regular user can self-label a topic
> "Announcement" / "Official" / "Solved". (Prefix *management* — admin CRUD via `prefix.manage` — is correctly
> gated; only *application* is open.) The plan never specified a gate, so this is not a regression, but it
> should be a **deliberate, controllable** choice rather than an accidental open surface.
>
> **Scope of this fast-follow (minimal):** add a `prefix.apply` permission so prefix application is explicit and
> admin-tunable (per forum / per group), **defaulting to members so current UX is unchanged**. This makes
> "only staff may apply *any* prefix" achievable (revoke `prefix.apply` from members at a forum). It does **not**
> make *specific* prefixes staff-only — per-prefix group restriction (the XF model) is a larger,
> **optional M4** enhancement, noted at the end, not built here.
>
> **Fold this into the existing `claude/p2-m1-prefixes` branch BEFORE its PR merges** — it is a small amendment
> to the prefixes slice, not a new milestone or a separate PR.

---

```
Small fast-follow: add a prefix.apply authorization gate, folded into the claude/p2-m1-prefixes branch BEFORE
its PR merges (not a new branch/milestone). The gate is permission-engine work — reason it carefully even
though it's small.

START: check out claude/p2-m1-prefixes. Read the prefixes slice (the ⚡prefixes SFC, the prefixes migration,
the prefix selector in ⚡create-topic, and PostService::createTopic's prefix handling), plus
PermissionCatalogSeeder, RoleSeeder, and config/novfora.php. Commit identity per CLAUDE.md (Tommy Huynh
<tommy@saturnhq.net>, DCO -s, no AI attribution).

BUILD (one logical change):
  1) CATALOG: add a forum-scoped key `prefix.apply` to PermissionCatalogSeeder::catalog() with a clear
     description ("Attach an existing prefix to a topic; admin-tunable per forum/group").
  2) GRANT: add prefix.apply to the MEMBER preset in RoleSeeder (DEFAULT = any member may apply — this
     preserves current behaviour; the point is to make it EXPLICIT, admin-revocable, and per-forum-scopable,
     NOT to restrict members by default). Do NOT TL0-NEVER it — applying a curated prefix is not a spam vector;
     leave it admin-tunable (an admin can set it to NO at a forum to make application staff-only there).
  3) GATE: in ⚡create-topic's save() action, when a prefixId is supplied, server-side
     `abort_unless($user->canDo('prefix.apply', Scope::forum($forumId)), 403)` — at the action, not only at
     mount/#[Locked]. Keep the existing validation that the prefix belongs to the forum.
  4) TESTS: a member with prefix.apply attaches a valid forum prefix; a user WITHOUT prefix.apply (e.g. an
     admin set prefix.apply=NO at that forum, or a guest) is rejected/403 and the prefix is not attached; the
     forum-membership validation of the prefix still holds; creating a topic with NO prefix is unaffected.

DEFINITION OF DONE: PermissionMaskTest extended for prefix.apply (member ALLOW; the NO-at-forum and guest cases
deny); the create-topic test covers the gated-apply path; full suite green; PROJECT-STATE + DECISIONS.md note
the choice (explicit member-default gate; per-prefix group restriction deferred to M4). Pint / Larastan / audit
green. Fold into the prefixes branch as one small DCO commit.

SCOPE FENCE: just the prefix.apply key + the member grant + the create-topic action gate + tests. NO per-prefix
group/role restriction (that's the optional M4 enhancement), NO new schema, NO change to prefix.manage (admin
CRUD is already gated). If you find prefix application happens anywhere ELSE besides create-topic (e.g. an edit
path), gate that too and note it.
```

---

## After this (optional, M4)

If you want **specific** prefixes to be staff-only (e.g. only moderators may apply "Announcement" while anyone
may apply "Question"), that needs **per-prefix group restriction** — an `allowed_roles`/`allowed_groups` seam
on `prefixes` (or a `prefix_group` pivot), checked at application time against the engine. That is a larger,
deliberate enhancement for **P2-M4** (discovery/moderation depth), not this fast-follow. This gate sets up for
it: the application point is now a single authorized seam, so adding per-prefix granularity later is localized.
