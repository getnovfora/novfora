# Flagged-member review queue + pending-account exit ramp — Build Spec (design-first)

> Handoff spec. Surfaced by the live demo: a member ("Dan") falsely flagged at registration sat at
> `status=pending` through **15 mod-approved posts** — every post held forever (the `NewUserModeration`
> condition-A rule), with **no way for an admin to clear the account** and nothing nudging them to. This
> closes that gap: a review queue for flagged/pending members, an activate/reject action, a moderator
> nudge on post-approval, and an *optional* auto-activation heuristic. **Activating a member lifts spam
> moderation, so the activation/auto-activation logic is an anti-spam boundary → ultracode, xhigh floor;
> the queue UI is Sonnet.** Branch `claude/pending-member-review` off `main`, gated, git on the VPS.

## 1. Goal
Give operators a clear, in-product way to **review and clear falsely-flagged members**, and stop a
legitimate user from languishing in per-post moderation indefinitely — without weakening the spam
posture (the admin stays in the loop by default).

## 2. Background (from the code + the live finding — don't re-derive)
- `RegistrationGuard` (tri-state allow/flag/block) sets a flagged registration to **`status='pending'`**
  in `app/Actions/Fortify/CreateNewUser.php`; every attempt is logged to `registration_checks` (the flag
  reason lives there).
- `NewUserModeration::shouldHold()` **condition A**: `($author->status ?? 'active') === 'pending'` →
  **hold every post, forever**, independent of trust level (condition B — the TL0 + approved-count rule —
  is separate). So a pending account never escapes by posting; only a status change clears it.
- `TrustLevelManager` freezes a non-active account at its current level (correctly — the Branch-2 soften
  deliberately left the status-freeze in place). So a pending user is double-stuck: held by condition A
  AND frozen at TL0.
- **The gap:** nothing surfaces pending users for review and nothing clears the flag. Dan: `status=pending,
  tl=0, posts=15, approved=15` — 15 separate manual approvals, never an account-level decision.
- The just-merged Branch 2 (`post-approval-promotion`, ADR-0092) added a **per-item hold reason** to the
  moderation queue and an **eager trust recompute on post approval** — extend those, don't duplicate.

## 3. Scope / Non-goals
**In scope:** a **Pending / flagged members** review queue in the ACP (list + context + activate/reject,
audited); a **moderator nudge** when approving a pending author's post ("this author is still pending —
activate the account?"); an **optional, config-gated auto-activation** after K mod-approved posts; on
activation, **trigger the trust recompute** so a long-time poster promotes immediately (reusing Branch 2's
recompute entry point). **Non-goals:** **no change to `RegistrationGuard` screening** (allow/flag/block
stays); **no `acl_entries`/resolver/permission change**; no change to the trust *engine* (Branch 2 owns
promotion); do **not** make auto-activation the silent default if it weakens spam posture (decide in the
ADR — §4).

## 4. Step 0 — the ADR (do FIRST; the anti-spam-sensitive core)
Write a `DECISIONS.md` ADR (next free #) pinning the **activation policy** — the load-bearing, security-
sensitive decision:
1. **Default posture = admin-in-the-loop, not silent auto-clear.** The queue + the approve-post nudge are
   the P0 exit ramp; the admin always makes the call. Justify why silent auto-activation is *not* the
   default (an overwhelmed mod approving K posts must not auto-clear a still-suspicious account).
2. **Auto-activation (optional, config-gated, default conservative/off).** If enabled, it fires **only**
   on the human signal — **K mod-approved posts** (for a pending user every approval is manual, so K
   approvals = K human vouches) — and **never** activates an account that is banned/blocked or has an
   active ban record. Pin K (proposed default 5), the config key, and the default (recommend **off** or a
   conservative K with the rationale). 
3. **What "activate" does:** `status` pending→active, an **audit entry**, and a **trust recompute** for
   that user (so they promote out of TL0 if earned). Reversible/auditable; never touches permissions.

## 5. Sequence
1. **ADR (step 0)** — the activation policy (top rung; everything depends on it).
2. **Review queue** — an ACP page (Members section) listing `status='pending'` users with context:
   the registration flag reason (from `registration_checks`), join date, post count + **mod-approved**
   count, last post. Per-row **Activate** and **Reject** (suspend/ban per existing machinery) actions,
   gated by the existing admin/moderation capability + audited. *(Sonnet — mirror an existing ACP list
   SFC; the activate action calls the §6 service.)*
3. **Activate action/service (xhigh)** — `status→active` + audit + **trust recompute** (reuse Branch 2's
   per-user recompute). One transaction. The actor-independent guard: never "activate" a banned account.
4. **Approve-post nudge** — in `ModerationController::approvePost()` / the moderation queue view, when the
   author is `pending`, surface a CTA linking to the activate action ("Author still pending — activate
   their account?"). Ties the per-post decision to the account-level one. *(Extends Branch 2's hold-reason
   hint.)*
5. **Optional auto-activation (xhigh, config-gated per the ADR)** — on post approval, if enabled and the
   author is pending with ≥ K mod-approved posts and not banned, activate (via the §6 service). Default
   per the ADR.
6. **Gates + child ADR cross-link + PROJECT-STATE/ROADMAP.**

## 6. Correctness seams (apex-adjacent — the review pins)
- **Activation lifts spam moderation**, so it happens **only** via an explicit admin action or the vetted,
  config-gated auto-threshold — never as a silent side effect. A **banned/blocked** account is never
  activated by either path (guard in the service, actor-independent).
- **Auto-activation counts only mod-approved posts** (the human signal); it must not count auto-published
  posts, and must respect the ADR's default (recommended off / conservative). A spammer who somehow
  accrues approvals on a *banned* account still can't be auto-cleared.
- **No escalation / no permission write** — the queue sets `status`, never `acl_entries`; reuse the
  existing admin gate + rank guard. Activation can't grant permissions, only lift the pending hold.
- **Idempotent + audited** — activating an already-active user is a no-op; every activation (manual or
  auto) writes one audit entry naming the path.

## 7. Verification / done
Gates green (`pest`/`pint`/`phpstan`). Tests (`tests/Feature/Admin/PendingMemberReviewTest.php` +
moderation coverage):
- A pending user appears in the queue with the flag reason + post/approved counts; a non-pending user
  doesn't.
- **Activate** → `status=active`, audit entry, trust recompute runs, and **condition A no longer holds**
  the author's next post (it flows); a 15-approved-post user promotes to TL1 on activation.
- **Reject/ban** path works and that account is never auto- or manually "activated" thereafter.
- The approve-post nudge renders only for a pending author.
- Auto-activation (if enabled): fires at exactly K **mod-approved** posts, never for a banned account,
  and obeys the ADR default when disabled. No `acl_entries` writes anywhere.
PR to `main` (do not merge) with the activation policy flagged for the adversarial review on the Cowork
side.

## 8. Commit
Branch `claude/pending-member-review` off `main`; small conventional commits (**ADR first**); `-s`,
`Tommy Huynh <tommy@saturnhq.net>`; clean-room. PR to `main`.

Read docs/product/pending-member-review-kickoff.md and execute it.
