<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# ACP v1.1 — post-deploy bug patch — Claude Code kickoff

> First beta-operator feedback after the ACP v1 deploy surfaced two live bugs and one important test gap.
> Bugs only — no features (group management + layman permissions are the ACP v2 / Phase-2 cycle). Branch +
> PR; CI green before merge (CI is the screenshot/Dusk producer).

---

```
Patch two ACP v1 bugs found on the live host and close the test gap that let the first one ship. No
features. Branch from main (includes ACP v1). Commit identity per CLAUDE.md (Tommy Huynh
<tommy@saturnhq.net>, DCO -s, no AI attribution). Suite green before you start.

BUG 1 — Admin → Settings → Registration returns 500 (live evidence):
  production.ERROR: "Too few arguments to function Livewire\Component@anonymous::gates(), 0 passed …
  exactly 1 expected" while rendering admin.settings.registration (a Livewire component view).
  • Root-cause it: the registration settings component/view references a `gates()` member with 0 args
    where the method requires 1 (a name/lifecycle collision — likely the anti-spam `gates()` helper called
    from the view, or a Livewire magic-method clash). Fix at the root (pass the arg / rename the call /
    restructure the read-only anti-spam-gates display). Verify the page renders 200 as an admin.
  • Sweep the other settings/admin Livewire components for the same pattern (a config/helper method named
    like a Livewire hook or called arg-less from a view).

BUG 2 — Forum width (Appearance setting) does not govern the topic view:
  Setting forum width (boxed-narrow/standard/wide/full → --layout-max-width) widens the index/board but the
  TOPIC view stays narrow. Make the width setting authoritative for EVERY main content container — forum
  index, board view, TOPIC view, and search — via the shared container consuming --layout-max-width (find
  where the topic view pins its own max-width and switch it to the token). Confirm visually at each width.

THE TEST GAP (the reason BUG 1 shipped — fix this, it is the most valuable part):
  • The ACP authz-walk asserts non-admins are DENIED every /admin route. It never asserts an AUTHENTICATED
    ADMIN can RENDER each page. Add the mirror: an admin-render smoke test that visits EVERY /admin and
    /admin/settings/* page (and the MCP pages) as a 2FA'd admin and asserts 200 with no exception. This
    catches the whole "renders fine for guests-denied but 500s for admins" class. It must FAIL on the
    unpatched registration page and pass after BUG 1's fix.
  • If feasible, a feature/assertion that the topic view honors the configured width (assert the shared
    layout container variable is emitted on the topic route — a Dusk visual check is acceptable but the
    in-process assertion is cheaper and CI-portable).

OPTIONAL (only if cheap + clearly in-scope) — deploy-window note: the live log showed brief
'Unknown column color_mode/density' 500s during a past upgrade window (signed-in requests that 500'd
instead of getting the RH-10 maintenance 503). If the maintenance gate has an obvious gap that let
column-touching requests through pre-migration, note it; do NOT redesign RH-10 here — flag it as a
follow-up if non-trivial.

GATES: full Pest + the new admin-render smoke test (fails on main, passes after) · Pint/Larastan/audit ·
assets-fresh green · Dusk + screenshots via CI · bundle rebuild + verify (report size + sha256) ·
PROJECT-STATE updated. Small conventional DCO commits.

SCOPE FENCE: the two bugs + the admin-render test + (optional) the deploy-window note only. No group
management, no permissions redesign, no new appearance options — those are queued (ACP v2 / Phase 2 /
Phase 3 configurator).
```

---

## After this (queued, NOT in this patch)

- **ACP v2 / early Phase 2 — "groups & community" cycle:** member-group manager (create/edit custom
  groups, membership, group permissions for mod/global-mod/admin), **layman-friendly permissions** (a
  "simple mode" of per-board who-can-see/post/reply + reusable permission *roles* layered over the existing
  mask engine — design-first, ADR), and the community-feel pack's **staff/group name colors** (pairs
  naturally with the group manager).
- **Phase 3 — configurator:** deeper layout/style/look-and-feel granularity (the owner's "more granular
  appearance options"), visual theme editing, layout/widget arrangement — atop the override layer (ADR-0009).
