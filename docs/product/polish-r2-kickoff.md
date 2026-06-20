# Polish R2 — hot-path query fix + admin layout/nav UI fixes — Build Spec

> Handoff spec. Four independent polish fixes in **one PR off main** (branch `claude/polish-r2`). Mostly
> Sonnet-level mechanical edits; Fix 1 is a small Opus debug. One small conventional commit per fix, each
> shipping a test or gated. All git on the VPS.

## Goal
Clear the lingering issues before more feature work: restore the one failing gate on `main` (the hot-path
query budget) and three UI/UX fixes the owner flagged on the live demo (`demo.novfora.com`).

## Scope / Non-goals
In scope: the four fixes below. Non-goals: no broader UI redesign; don't touch the permission engine; the
rest of `UI-AUDIT-FIX-SPEC.md` is a separate effort.

## Locked constraints
Tests/gates with every fix (`pint` · `phpstan` · `pest`, capped output); small conventional commits, one
per fix, `-s` (DCO), authored `Tommy Huynh <tommy@saturnhq.net>`, no AI trailers; clean-room. Branch
`claude/polish-r2` off `main`.

## Fix 1 — hot-path query-budget regression (the failing gate) — Opus `high`
The hot-path query-count test (e.g. `tests/Feature/.../HotPathQueryTest`) is at **42 vs its 41 budget**;
the extra query crept in with **v3-e** (group system). **Reproduce before fixing.**
- Run the failing test; capture the query log on the asserted render path.
- Diagnose the extra query v3-e added — likely a per-row group/membership lookup that should be eager-loaded or memoized (v3-e added `MembershipCache`, `groups` relation reloads, the primary-group chooser). Compare to the pre-v3-e path.
- Fix by eager-loading / memoizing so the path is back to ≤ 41. **Do NOT bump the budget** — find and remove the query.
- Verify: the test passes at the restored budget; no new N+1 introduced elsewhere.

## Fix 2 — admin pages render as a giant bare SVG (BUG-001 class) — Sonnet
`/admin/groups/permissions` and `/admin/groups/roles` render a full-viewport `<svg>` with no page chrome:
the views emit a bare `<x-admin.shell>` with **no `@extends('layouts.app')` / `@section('content')`
envelope**, so the browser renders the shell's first icon `<svg>` unconstrained.
- Fix `resources/views/admin/group-permissions.blade.php` and `resources/views/admin/roles.blade.php`: wrap the existing `<x-admin.shell>…</x-admin.shell>` body in the standard envelope, mirroring `admin/dashboard.blade.php` / `admin/structure.blade.php` — `@extends('layouts.app', ['title' => 'Admin · …'])` + `@section('content') … @endsection` (optional `@section('breadcrumbs')`). The shell body and `<livewire:…>` tags are unchanged.
- **Audit for the same latent bug:** grep all `resources/views/admin/*.blade.php` for a top-level `<x-admin.shell>` emitted WITHOUT a preceding `@extends('layouts.app')`, and fix every instance. `resources/views/admin/forum-permissions.blade.php` is a known candidate (structurally identical).
- Test (Pest feature): for each affected route, as a 2FA admin, `get(...)->assertOk()` and assert the body contains `<html` (the layout ran) and is not a bare `<svg>` root. Add these routes to `AdminAccessWalkTest`'s render check if missing.

## Fix 3 — admin content width should match the forum — Sonnet
The forum body is `max-w-[var(--layout-max-width,64rem)]` (`<x-ui.container size="lg">`); the admin shell
is `max-w-6xl` (`size="xl"`), ~8rem wider and ignoring the operator's Appearance width setting.
- `resources/views/components/admin/shell.blade.php` (≈ line 16): change `<x-ui.container size="xl" …>` to `size="lg"`. Admin then shares the forum's `--layout-max-width`.
- Verify visually at the default and a custom Appearance width: admin content tracks the forum.

## Fix 4 — remove the dark/light toggle from the top nav — Sonnet
The nav theme toggle crowds the right cluster so "What's new" wraps to two lines. The preference also
lives in user settings, so the nav toggle is redundant.
- `resources/views/layouts/app.blade.php`: delete the colour-mode toggle (the `{{-- Colour-mode toggle … --}}` comment + its `<button>` block, ≈ lines 206–219). Confirm "What's new" + the bell sit on one line.
- Keep the full control at `/settings/appearance` (`resources/views/settings/appearance.blade.php`, linked from the user dropdown) — do not remove it.
- **Flag in the PR:** the nav toggle was also the only theme control for *guests* (settings needs an account). After removal, guests fall back to `auto` (follows the OS), which is the accepted tradeoff per the owner. Note it so it's a conscious call.

## Verification / done
All gates green **including the now-restored hot-path budget**; the affected admin routes render with full
chrome; admin width matches the forum; the nav toggle is gone with the settings control intact. One PR
`claude/polish-r2` → `main`.

## Commit
Four small commits (one per fix), `-s`, `Tommy Huynh <tommy@saturnhq.net>`, conventional messages. PR to `main`.

Read docs/product/polish-r2-kickoff.md and execute it.
