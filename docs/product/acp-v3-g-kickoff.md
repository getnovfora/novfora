# ACP v3-g — Staff flair + roster — Build Spec

> Handoff spec (ADR-0080 slice **v3-g** — the FINAL ACP v3 slice). A live, group-derived **staff flair**
> (Co-owner / Administrator / Moderator / Forum moderator) shown across the UI, plus a public **"The Team"
> roster**. **Display-only — no `acl_entries` touch, no permission write** — so it's Sonnet-forward (no apex
> seam; the only reasoning is the staff-role resolver). Additive + reversible. Branch off `main`, gated, git on the VPS.

## 1. Goal
Surface who's staff at a glance (a small role marker on posts / profiles / member lists) and a curated
public Team page — **completing the ACP v3 program**.

## 2. Scope / Non-goals
**In scope:** a `User::staffRole()` resolver (role → label); a `<x-ui.staff-flair>` component slotted into the
post author block, profile hero, members-directory card, and the online list; a `/staff` "The Team" roster
(Livewire SFC, grouped by role, gated by a setting); additive `groups` flair columns + two settings; an ACP
toggle SFC.
**Non-goals:** no `acl_entries`/permission change (display only — no `AclVersion` bump); don't reuse the
earned-badge system (flair is live/group-derived, not awarded); no per-user custom titles beyond the group
name (an optional `staff_title` column only if trivial).

## 3. Locked constraints
**Display-only — never writes `acl_entries`.** Additive, reversible migrations. Reuse `User::isAdmin()` /
`isStaff()` + the co-owner check (`AdminCoOwnerService::isCoOwner`) + `moderator_assignments` (v3-b, now on
`main`) for the Forum-moderator label. i18n `forum.*`/`admin.*` (G8). Tests with the feature; small
conventional commits, `-s`, `Tommy Huynh <tommy@saturnhq.net>`; clean-room. Branch `claude/acp-v3-g` off `main`.

## 4. Files (from the v3-g map)
**New:** migration `…_add_staff_flair_to_groups.php` (`show_on_staff_page` bool default false — **seed `true` on
`admins` + `moderators`**; `show_staff_icon` bool default false; optional `staff_title` nullable; reversible) ·
two settings `members.staff_roster_enabled` (bool, false) + `members.staff_flair_show_badge` (bool, true) in
`SettingsRegistry` · `resources/views/components/ui/staff-flair.blade.php` ·
`resources/views/components/community/⚡staff-roster.blade.php` (SFC) + `resources/views/members/staff.blade.php`
(wrapper, **`@extends('layouts.app')`**) · `resources/views/components/admin/settings/⚡staff-flair.blade.php`
(ACP toggle) · tests `tests/Feature/Staff/StaffFlairTest.php`, `StaffRosterTest.php`.
**Edit:** `app/Models/User.php` (`staffRole(): ?string` — Co-owner / Administrator / Moderator / Forum
moderator; memoized; the co-owner check is the only extra DB touch) · `resources/views/forum/topic.blade.php`
(≈149 — replace the inline role ternary with `<x-ui.staff-flair :user="$author" />`) · `profiles/show.blade.php`
(hero) · `⚡members-directory.blade.php` (card) · optionally `⚡online-members.blade.php` · `lang/en/forum.php`
(`role_co_owner`, `role_administrator`; `role_moderator` exists) · `routes/web.php` (`/staff` → `members.staff`;
the ACP staff-flair settings route) · `app/Admin/AdminNavigation.php` (Members section: staff-flair settings sub-page).

## 5. Sequence (Sonnet-forward; ultracode downgrades — no apex seam here)
1. **`User::staffRole()` + lang** — the only reasoning step: derive the canonical role label (admins+co-owner → Co-owner/Administrator; moderators → Moderator; a `moderator_assignments` row → Forum moderator; else null). Test each role → right label; a regular member → null.
2. **Flair component + slot-ins** — `<x-ui.staff-flair>` (badge + optional icon, gated by `members.staff_flair_show_badge`); slot into the four surfaces. Test: staff get the role badge, members get nothing.
3. **Migrations + settings** — `groups` flair columns (seed system groups) + the two settings; apply+rollback+re-apply.
4. **`/staff` roster SFC + route** — list groups with `show_on_staff_page=true` and their active members grouped by role (Co-owners → Administrators → Moderators → Forum moderators), gated by `members.staff_roster_enabled` (hidden/404 when off). Test: lists the right people; hidden when off; no non-flagged group leaks.
5. **ACP settings SFC + nav** — toggles for roster-enabled + flair-show, in the Members section.
6. **Gates + child ADR (next # after 0087) + PROJECT-STATE/ROADMAP — v3-g shipped → mark the ACP v3 program COMPLETE.**

## 6. Verification / done
Gates green; migrate apply+rollback+re-apply; `staffRole` correct per role; flair renders on the surfaces;
`/staff` lists the right people grouped by role and respects the visibility setting; **no `acl_entries` write
anywhere** (display only). Child ADR; PROJECT-STATE + ROADMAP mark the **ACP v3 program complete**.

## 7. Commit
Branch `claude/acp-v3-g` off `main`; small conventional commits per step; `-s`, `Tommy Huynh <tommy@saturnhq.net>`. PR to `main`.

Read docs/product/acp-v3-g-kickoff.md and execute it.
