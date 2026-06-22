<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# v1.x — Owner-strand guard (apex fast-follow) + merge/build handoff — Claude Code kickoff

> **One Code session, two parts.** **Part 1** builds the apex fast-follow A2 flagged (close the front-end ban/warn
> owner-strand gap). **Part 2** merges the full v1.x batch **+ this slice** into `main`, re-gates the union, and **stops
> for the owner to push** (nothing pushed/merged to `origin`). Standing rules + run protocol as
> `docs/product/v1x-feature-program-kickoff.md` (commit identity `Tommy Huynh`, DCO `-s`, no AI trailers; gate in
> `forum-dev`/VPS; `route:clear` before trusting subdir/PWA reds).

---

## Part 1 — S5: owner-strand guard on the front-end ban/warn paths · `claude/v1x-s5-owner-guard` · ⚠ APEX · rung: Fable @ max
**Why:** A2's review found a **pre-existing HIGH-class gap** — A2 added the last-owner guard to the *new ACP* per-member
path, but the **front-end `BanController`** and the **`WarningService` auto-consequence** (a warning crossing the
`temp_ban`/`ban` threshold) can still ban/suspend the **sole owner/co-owner**, stranding the owner tier (a banned owner
can't reach the panel). This pre-dates v1.x and is live on demo — close it before the deploy that touches member-mgmt.

**Verify-first.** Locate the existing **locked** last-owner guard — `AdminCoOwnerService::assertNotSoleCoOwnerLocked`
(the `lockForUpdate` re-read mirroring `AccountDeletionService::assertNotSoleAdminLocked`) — and the guard **A2** added on
the ACP member path. Confirm exactly which *effective ban/suspend* code paths still lack it (names may differ — prefer the
codebase).

**Do.** Route **every effective ban/suspend application** through that **same locked guard**, as an actor-independent
backstop (not just a UI check):
- the front-end **`BanController`** (+ the ban service it calls),
- **`WarningService`**'s consequence applier where points cross `temp_ban`/`ban` (the indirect path — the real trap),
- any other suspend/restrict entry point the verify-first sweep finds.
The guard is the **`lockForUpdate` re-read** (TOCTOU-safe), **fail-closed**, and protects the owner tier specifically
(sole co-owner / last full admin via `AdminCoOwnerService::isCoOwner` + the sole-owner count). A **non-sole** co-owner is
still bannable; ordinary users are unaffected.

**Mandatory apex review (verify-then-refute, before commit):** the concurrent-ban race; the **warning → auto-ban**
indirect path; whether a banned owner can recover; and that the guard can't be bypassed via a different
suspend/restrict/role-strip entry point. Record it like the A2 review. **HALT + report on any unresolved HIGH.**

**Tests:** a moderator cannot **ban** — nor **warn-to-auto-ban** — the sole owner (BOTH paths); a co-owner with a peer
CAN be banned; ordinary-user ban unaffected; the lock holds under a simulated concurrent attempt. **ADR-0100** (or extend
**ADR-0086** — confirm next-free; 0095–0099 are the v1.x proposals). Reversible; baseline-runnable; gates green.

---

## Part 2 — Merge the batch + S5, re-gate the union, STOP for push
Run only after Part 1 is green on `claude/v1x-s5-owner-guard`.

**Pre-flight:** `git switch main` (HEAD `3b70653`); `git branch backup/pre-v1x main`; confirm the gate toolchain.

**Merge (`--no-ff --no-edit`) in dependency order** — resolve the **additive** hand-merge conflicts keeping **BOTH** sides:
```
R1 → S3 → R2            (R2 is stacked on R1+S3)
A1 → A2 → A3 → S5       (A2 stacked on A1; S5 shares the member/permission + ban/warn surface)
S1 ; S2
M1 → M2 → M3
T1 → T3 → T2
```
**Known additive hand-merges (keep both):** the **reply-composer** (M1/M2/T1), the **AdminAccessWalkTest** sentinel
(A1/A3/T1), and the **ban/warn services** (A2/S5).

**After all merges:**
1. `php artisan route:clear` (the stale `routes-v7.php` is the false subdir/PWA red — see memory).
2. **Re-gate merged `main` as a union:** `php artisan test --parallel` · `pint` · `phpstan` L max · `migrate`
   apply+rollback+re-apply · `npm run build` + asset-drift · **Dusk** (CI Chrome) · the **a11y page gate**. Fix forward.
3. Commit the **rebuilt assets**.
4. **Lift ADRs 0095–0100** into `DECISIONS.md` (confirm next-free; renumber if needed).

**STOP before pushing.** Do **not** push or touch `origin`. Write a report to the top of `PROJECT-STATE.md` with:
- the merged `main` HEAD + the **union** gate result (pest/pint/phpstan/migrate/dusk/a11y/asset-drift),
- every conflict resolution made,
- the **S5 apex-review** outcome (candidates → fixed/refuted; confirm no open HIGH),
- the ADRs lifted (0095–0100) + their decisions,
- "what the owner does next" (push `origin main`; then the release-zip deploy).

---

## After the merge — go live
Once merged `main` is green and the owner has pushed it: build the portable release per
`docs/product/live-deploy-kickoff.md` (`scripts/build-release.sh` → `scripts/verify-release.sh`) and upgrade
demo.novfora.com **backup-first** — this bundle carries **new migrations** (attachments, subscriptions, member
management, canned replies, email templates, + the S5 guard), so it's the cron auto-upgrade path, not assets-only.
