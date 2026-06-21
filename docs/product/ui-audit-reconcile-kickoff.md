# UI-audit reconcile — land the 21-finding fixes on current main — Build Spec

> Handoff spec. `claude/fix-ui-audit` (branch `fix/ui-audit`) holds the **complete, tested implementation of
> the 21 UI-audit findings** (`UI-AUDIT-FIX-SPEC.md` + 13 fix commits), but it was branched **before** all the
> ACP v3 work + the polish bundles, so a wholesale merge is conflict-hell. This reconciles its still-unmerged
> fixes onto **current `main`**, dropping what's already landed and resolving conflicts to keep **both** the UI
> fix and the newer ACP v3 / polish change. **ultracode** — the BUG-002/003 dual route-resolver is
> permission-scoped (xhigh floor); the rest is Sonnet-to-Opus UI work. Branch off `main`, gated, git on the VPS.

## 1. Goal
Get the polished, tested UI fixes (clean slug/username URLs, pluralization, breadcrumbs, profile tabs,
display-name editing, configurable activity feed, presence-badge fix, etc.) onto `main` — most have been
sitting unmerged on `fix/ui-audit` the whole time — then retire that branch.

## 2. What's on `fix/ui-audit` (13 wanted fixes + 1 to SKIP)
Every commit shows as unique-to-branch (`git cherry main fix/ui-audit` = all `+`). **SKIP `ae9c594` (BUG-001 section-landing envelope) — already on `main` via polish R2.** Bring the rest:
- `e5a2a4e` BUG-014 blank-TipTap draft banner · `a1362fa` BUG-019 display-name editing · `47b72cf` BUG-017/018 profile tabs + de-emphasised staff tools · `6f398cb` BUG-016 settings sidebar nav · `a749aa3` BUG-020 RecentActivity widget · `111bced` BUG-012 admin activity-feed limit · `88b4b9e` **BUG-002/003 dual slug/username route binding** · `1f924bb` BUG-009/011 count-integrity tests · `c56f1b4` BUG-010 presence-badge opt-in gate · `08451b0` BUG-007/008/015 pluralization · `96d4968` BUG-013/021 breadcrumbs · `7cc08de` BUG-004/005 ampersand + breadcrumb label · `49866fe` the `UI-AUDIT-FIX-SPEC.md` doc.

## 3. Approach
Create `claude/ui-audit-reconcile` off `main`. **Cherry-pick the 13 commits (skip `ae9c594`) in order**, resolving conflicts as they land. (Cherry-pick over merge: the branch is far behind `main`; replaying just the wanted commits avoids dragging the stale base.) The conflicts are all **"both sides added different things to the same file" → keep both.** Likely conflict files + the other side that touched them:
- `resources/views/components/⚡members-directory.blade.php` — v3-g staff flair vs BUG-010 presence gate → keep both.
- `resources/views/profiles/show.blade.php` — v3-g staff flair (hero) vs BUG-017/018 tabs → keep both.
- `app/Models/User.php` — v3-g `staffRole()` vs BUG-002/003 `getRouteKeyName()`/`resolveRouteBinding()` → keep both.
- `lang/en/forum.php` — v3-g `role_*` keys vs BUG-007/008 pluralization keys → keep both.
- `resources/views/admin/structure.blade.php` / `section.blade.php` — polish R2 + ACP v3 vs BUG-004/005 → keep main's BUG-001 envelope, apply the BUG-004/005 ampersand+label on top.
- `app/Settings/SettingsRegistry.php` — v3-g staff/roster settings + BUG-012 activity-limit setting → keep both.
**After each cherry-pick, the fix's own test comes with it — run it.** Where a view evolved under ACP v3 so a fix no longer applies cleanly, **re-apply the fix's intent against the current view** (don't force the old patch).

## 4. Locked constraints
**BUG-002/003 (xhigh):** the dual resolver must resolve numeric→id and non-numeric→slug/username and must **not widen visibility** — a permission-scoped route binding; keep numeric resolution working (no 301s), and confirm a private-forum slug URL still 404s/403s for a guest exactly as the id URL does. Reuse the engine; clean-room; tests with every fix (they come along); `pint`/`phpstan`/`pest` green; small conventional commits (one per finding-group), `-s`, `Tommy Huynh <tommy@saturnhq.net>`. Branch `claude/ui-audit-reconcile` off `main`.

## 5. Verification / done
All gates green (the full suite — these add ~14 test files); each finding re-verified against the *current* UI (some views changed under ACP v3 — confirm the fix still lands); BUG-002/003 confirmed not to widen visibility (guest still blocked from a private forum by slug). `UI-AUDIT-FIX-SPEC.md` on `main` as the record. PR to `main`; once merged, **delete `fix/ui-audit`** (closed out). Note in the PR which of the 21 are now done vs any deliberately re-scoped.

## 6. Commit
Branch `claude/ui-audit-reconcile` off `main`; one small conventional commit per finding-group; `-s`, `Tommy Huynh <tommy@saturnhq.net>`. PR to `main`.

Read docs/product/ui-audit-reconcile-kickoff.md and execute it.
