<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# Phase 4 · M1 — Clubs (sub-communities)

> Design record for the Clubs milestone. ADRs: **0047** (data model + privacy), **0048** (club-scoped
> permissions), **0049** (membership flows + rank ceiling), **0050** (discussion space), **0051** (no-leak
> sweep), **0052** (creation policy). Built strictly clean-room on the existing engine.

## What a club is

A **club** is a named sub-community with its own discussion space and a roster. Three privacy levels plus a
listing flag:

| `privacy` | content visibility | join | listing (`is_listed`) |
|---|---|---|---|
| `public` | world-readable | open (anyone joins) | always listed |
| `closed` | members + staff | request → approve | listed (default) |
| `private` | members + staff | invite only (signed token) | listed **or** hidden (`is_listed=false`) |

A `private` + unlisted club is the **"private-hidden"** case: non-members never learn it exists.

## The APEX privacy decision (read this first)

The board is **public-by-default**: the `guests` group holds `forum.view = ALLOW` at **global** scope, and the
`member` preset inherits it, so **every** logged-in user resolves `forum.view = true` for any forum via global
inheritance. The three-state ACL therefore **cannot** express "members of this private club see it, other
logged-in users don't": `NO` is neutral (inherits), and `NEVER` is absolute and checked across **all** holders
before the scope walk — so a `members`-group `NEVER` at club scope would also hard-deny real club members (who
are themselves `members`).

So club content-hiding is enforced by **three** mechanisms, not by ACL inheritance alone:

1. **Capabilities flow through the engine** at a new **`club` scope** (ADR-0048). `Scope::club(id)` joins
   global/category/forum/thread; `ScopeChain` injects the club node into a club forum's chain. `ClubRoleProjector`
   mirrors the roster (`club_user.role/status`, the source of truth) into per-user club-scope `acl_entries`:
   owner → `club.manage` + the moderation set; moderator → the moderation set; member → none (relies on the
   global `member` preset for posting). A club moderator's `topic.moderate` resolves **only** within the club.
2. **Query-level content gate** for logged-in users: `Forum::clubContentVisibleTo()` + the extended
   `VisibleForumIds` (public-club OR active-member OR global-staff). This is the **authoritative** content-hiding
   gate, consulted by every exposure surface (ADR-0051).
3. **Anonymous defence-in-depth**: closed/private clubs seed `forum.view = NEVER` for the **guests** group at
   club scope, so sitemap / RSS / guest search are blocked through the `forum.view` checks they already do.

`ActorRank` still guards actor-vs-target, so a **club owner can never out-rank global staff** in the club.

## No-leak surfaces (ADR-0051)

Every surface a private-hidden club must not leak through: search (faceted + typeahead), activity feed,
RSS/Atom, sitemap, member profiles, REST API, notifications, tags, what's-new, attachments, bookmarks,
trending, webhooks. An adversarial review found + fixed two it first missed — the reaction-notification emit and
the stored-notification render — both now re-gate against the recipient's current club access. 14 explicit
no-leak tests, one per surface.

## Membership flows (ADR-0049)

`ClubMembershipService` is the single authority: join (public) · request→approve (closed) · invite (private,
48-char single-use expiring token) · leave · role change · removal · ownership transfer. Invariants: a club
always keeps ≥ 1 active owner; the global-staff rank ceiling; roster management is owner+admin only.

## Who can create a club (ADR-0052)

Setting-driven via `clubs.creation_policy` (Admin → Settings → Clubs): **any** verified member / **trust** level
≥ `clubs.creation_min_trust_level` (default **2**) / **staff** only. Staff may always create; unverified members
never can.

## How it works (for members)

Open **Clubs** in the nav. Public clubs are read-and-join for anyone; closed clubs you request to join; private
clubs you need an invite for. A club has its own discussion forum (reuses the normal topic/post UI) — private
club content is visible only to members. Owners manage members, roles, and invites from the club's **Members**
page.

## Known follow-ups (flagged for review)

- "admin-approved" creation is realised as **staff-only**; a request→approval queue for club creation is
  deferred (ADR-0052).
- A sole club owner deleting their account leaves the club without an owner (ADR-0047) — account-deletion should
  require ownership transfer first (fast-follow).
- Tag `usage_count` includes private-club usage (count-only, no titles); leaderboard counts include club
  activity (coarse aggregate, no club identity) — both documented residuals (ADR-0051).
