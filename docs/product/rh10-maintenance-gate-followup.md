<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The Hearth Authors
-->
# Follow-up: the RH-10 maintenance gate's "null-safe reads" claim doesn't cover the write path

> **Status: NOTE / follow-up — not actioned in the ACP v1.1 patch.** Surfaced while patching ACP v1
> (the kickoff's optional deploy-window item). This is a deliberately scoped *note*, **not** a redesign
> of RH-10. Pick it up in a future operability pass if desired.

## What we saw

The live log showed brief `Unknown column color_mode/density` **500s** during a past upgrade window —
signed-in requests that 500'd instead of receiving the RH-10 branded maintenance **503**. The
`PreventRequestsDuringUpgrade` middleware's own docblock promises the opposite ("never a raw SQL error",
`app/Http/Middleware/PreventRequestsDuringUpgrade.php:16`).

## Root cause (precise)

`SchemaState::shouldGateRequests()` (`app/Upgrade/SchemaState.php`) fails **OPEN** in three situations.
Two of them are intended, one is the documented manual-mode asymmetry:

1. **Cold-cache bootstrap, concurrent loser (auto mode).** On an empty cache (first request after a
   deploy, or after a cache flush) one request takes the non-blocking bootstrap lock and runs the
   authoritative `refresh()`; a *concurrent* request that loses the lock re-reads still-empty state and
   returns `false`, serving the un-gated app for that instant (`SchemaState.php:80-84`). The
   deploy→first-tick **fingerprint trick** (`SchemaState.php:26-29`) *cannot* fire on a cold cache —
   `pendingFromState()` needs a *stored* fingerprint to mismatch against (`SchemaState.php:374-384`), and
   there is nothing cached to compare — so a cold cache always falls to this bootstrap path.
2. **Any cache `Throwable` (auto mode).** The whole method is wrapped `catch (Throwable) { return false; }`
   (`SchemaState.php:97-98`); `state()` likewise returns `[]` on a read error (`:345-354`). On the
   baseline tier the cache **is** the DB, so a DB hiccup *during* the upgrade window opens the gate. This
   is deliberate ("a cache blip should not 503 the entire site", `:62`).
3. **Manual mode (`hearth.upgrade.auto=false`).** A merely-pending schema does not gate at all
   (`SchemaState.php:92-96`; documented asymmetry, `config/hearth.php:235-243`) — the admin must reach the
   panel to apply. **Manual-mode 500s on signed-in pages are by design, NOT the gap.** (Default is
   `auto=true`.)

The actual defect is the **justification**, not those paths. The inline note at `SchemaState.php:79` says
an ungated page "can't 500 on a missing column anyway" because "reads are null-safe." That holds for
Eloquent **attribute reads** — `layouts/app.blade.php` reads `$authUser?->color_mode` / `?->density`,
which return `null` on a missing column with no query. It does **not** hold for **writes**:
`AppearanceController::update()` runs `$user->color_mode = …; $user->density = …; $user->save()`
(`app/Http/Controllers/AppearanceController.php:47-53`), emitting `UPDATE users SET color_mode=?, density=?`
against a not-yet-migrated column — exactly the reported `Unknown column color_mode/density` 500.
(`color_mode`/`density` are real migration-added columns —
`database/migrations/2026_06_06_000501_add_appearance_to_users.php` — not casts.)

And it auto-fires: the header colour/density **quick-toggle** fetch-POSTs to `/settings/appearance` for any
signed-in user (`resources/js/app.js`, wired to the header/footer controls in `layouts/app.blade.php`). The
POST runs the full web stack including `PreventRequestsDuringUpgrade` (`routes/web.php`,
`bootstrap/app.php`). So during any **auto-mode** fail-open instant (path #1 or #2), a single toggle click
500s.

## Scope of any future fix (when picked up)

Auto-mode fail-open windows **only** — manual mode is out of scope (by design). Options, smallest first:

- **Narrow the comment** at `SchemaState.php:79` so it no longer reads as a blanket safety guarantee
  (it covers reads, not writes/queries). Lowest effort; documents the real risk surface.
- **Gate writes more conservatively:** during a cold/error read in auto mode, treat **non-idempotent**
  (non-GET) requests as gate-closed even when a read would fall open — a write that can't be proven safe
  gets the 503 instead of a raw SQL error. Keeps the read fast-path's fail-open ergonomics.

No code change in the ACP v1.1 patch — tracked here as a follow-up.
