# Polish R3 — Dusk installer debug + Permission Inspector readability — Build Spec

> Handoff spec. Two fixes in **one PR off main** (branch `claude/polish-r3`): a debug of the failing Dusk
> `InstallerWizardTest`, and a human-readable explanation layer for the Permission Inspector. Both Opus-level
> (the Dusk one is a real debug; the inspector one needs the plain-language mapping to be *correct*). Tests/
> gates per fix; small conventional commits, one each. All git on the VPS.

## Goal
Clear `main`'s last CI red (the Dusk installer timeout) and make the Permission Inspector explain "why
can/can't X do Y" in plain language an operator can trust — not raw `group_allow` / `never` / `forum:2` codes.

## Locked constraints
**Do NOT change the resolver or `PermissionInspector` core logic** — the readable layer is a pure read-only
presentation layer over the existing trace. i18n under `admin.inspector.*` (ADR-0079 / G8 — never a bare key).
Tests with each fix; `pint` · `phpstan` · `pest` (+ `dusk` for #1), capped output; small conventional commits,
`-s` (DCO), `Tommy Huynh <tommy@saturnhq.net>`, no AI trailers; clean-room. Branch `claude/polish-r3` off `main`.

## Fix 1 — Dusk `InstallerWizardTest` TimeoutException (the last CI red) — Opus `high` to diagnose; escalate if it's a real installer defect
**Rung:** `high` to find the cause (this is test-infra diagnosis, not security reasoning). **But the installer
surface is apex-listed** — so if the root cause is a genuine installer-logic or security-boundary bug (a broken
wizard step, an auth/token gate, a DB-test SSRF path), escalate the *fix* to `xhigh` / Fable @ max. A pure
flaky-test harden (readiness wait, Chrome/MySQL startup) stays at `high`.
The installer-wizard browser test times out in CI; it's been red on `main` and is unrelated to recent feature
work. **Reproduce first** (Dusk needs Chrome + the disposable MySQL via `docker/dusk/`).
- Run it; capture **where** it times out (which step/selector/wait). Decide: a genuine installer regression (a wizard step broken, a changed selector, a JS error) **or** a Dusk/env flake (Chrome startup, MySQL readiness, a too-short or blind wait)?
- If a real bug: fix the wizard or the stale selector. If env/timing: harden with a proper `waitFor…` on a real readiness signal — **do not paper over a real hang by bumping a timeout**.
- Verify: `php artisan dusk` green for `InstallerWizardTest`; record the root cause in the PR.

## Fix 2 — Permission Inspector: human-readable explanation — ultracode (start Fable @ max on the mapping; downgrade scaffolding)
**Rung:** the inspector is **apex-listed** in CLAUDE.md. The readable layer doesn't change permission *semantics*,
but it must *faithfully represent* them — a wrong explanation (mis-stating a NEVER, an override, or who decided)
is a security-trust defect that propagates into real grant/revoke decisions. So **verify the reason-code → sentence
mapping at the top rung** (each template must match exactly what the resolver decided, incl. NEVER short-circuit
and the override chain), then **downgrade the Blade/i18n scaffolding + the name lookups to Sonnet**. Floor is
`xhigh`, not plain `high`.
`/admin/security/permissions` → `resources/views/components/admin/⚡permission-inspector.blade.php` renders
raw codes today (`group_allow`, `forum:2`, `group#7`). Add a **plain-language explanation block ABOVE the
existing technical trace** (keep the trace + candidate-entries table for power users) — read-only, **core untouched**.
- **Enrich the report** in the SFC `inspect()` action (read-only lookups): the permission key → its catalog `label` + `description` (`Permission::where('key', …)`); the deciding scope → a human **name** (`global` → "site-wide"; `forum:N` / `category:N` / `club:N` → the actual forum/category/club name); the deciding holder (`group#N` → the group **name**; `user#N` → the username) — and the same for the trace rows.
- **Compose the verdict + reason** via new `admin.inspector.*` i18n templates, one per verdict and per reason code (`user_allow`, `group_allow`, `never`, `banned`, `default`), interpolating the user / permission-label / scope-name / holder-name, and **stating the override where relevant** — e.g. "**Tommy can moderate topics** — the **Moderators** group grants it in **General Discussion** (forum level), overriding the site-wide default." For NEVER: "**hard-denied** — a NEVER rule at the forum level can't be overridden by any grant." Show the key's `description` as a sub-line.
- Cover all five reason codes + the scope-level labels + holder-type labels (per the inspector map). The machine `summary` and the candidate-entries table stay below, unchanged.
- **Test (Pest feature):** for each reason code, build an `Acl::make()` fixture that produces it, run the inspector, and assert the rendered explanation shows the **human** pieces — the permission *label* (not the raw key), the scope *name*, the holder *name*, the right verdict — and that no raw `group#` / `forum:` code leaks into the readable block. Assert the new `admin.inspector.*` keys resolve (no raw key rendered).

## Verification / done
All gates green **including Dusk `InstallerWizardTest`**; the inspector shows a plain-language verdict + reason
(names, not codes) above the technical trace; i18n keys resolve. One PR `claude/polish-r3` → `main`.

## Commit
Two commits (Dusk fix; inspector readability), `-s`, `Tommy Huynh <tommy@saturnhq.net>`, conventional. PR to `main`.

Read docs/product/polish-r3-kickoff.md and execute it.
