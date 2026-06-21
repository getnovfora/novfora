# Branch 2 — Post approval / trust promotion ("Dan is stuck in the queue") — Build Spec

> Handoff spec (Batch 2026-06-21, Branch 2). A long-time, active member still has every post held for
> manual approval. Root cause is in the **moderation + trust layer**, not permissions — **no
> `acl_entries` touch, not apex.** **Opus `high`** (correctness-load-bearing). Branch
> `claude/post-approval-promotion` off `main`, gated, git on the VPS.

## 1. Goal
A member who has earned their way out of new-user moderation stops having posts held — promptly (on
approval), not "up to an hour later if the cron runs" — and an operator can see **why** any user is held.

## 2. Background (from the code — confirmed, don't re-derive)
- **The hold gate** is `NewUserModeration::shouldHold()` (`app/AntiSpam/NewUserModeration.php`), called
  via `ContentModerator::review()` inside `PostService::writePost()`. Two hold conditions:
  1. `($author->status ?? 'active') === 'pending'` → hold **everything, forever**.
  2. author is in the **`tl0` group** AND approved-post count `< NOVFORA_NEW_USER_HOLD_POSTS`
     (config `novfora.antispam.new_user_moderation.posts`, default **2**). The count is
     `Post::where('user_id',…)->where('approved_state','approved')->count()`. Reads **`tl0` group
     membership**, not the `users.trust_level` column. Not in `tl0` → never held by this gate.
- **Trust promotion out of TL0** is `TrustLevelManager` (`app/AntiSpam/TrustLevelManager.php`), run from
  the **hourly cron** `novfora:trust:recompute`. `earnedLevel()` counts **all** posts
  (`Post::where('user_id')->count()`, includes pending). TL1 threshold: 5 posts / 1 day / 5 topics read.
  **Freeze:** in `evaluate()`, if `$points > 0` (any *live* warning point) or `status !== 'active'`, it
  **returns the current level** — i.e. a single live warning pins the user at TL0 indefinitely.
- **The bug on approval:** `ModerationController::approvePost()` sets `approved_state='approved'` and
  sends the reply/mention notifications, but **does not** dispatch `PostCreated` or re-run trust
  evaluation. So approving a queued post never advances the author. Combined with the gate reading
  *approved* count, a TL0 user is in a **circular trap**: every post is held → approved count never
  grows from posting → the gate keeps holding. Only the hourly trust recompute breaks it (it counts all
  posts) — and only if it's running and the user has no live warning.
- **Demo note:** the demo cron was just enabled, so the hourly recompute may now promote Dan on its own
  (if he has no live warning). The fixes below make this **prompt and diagnosable** rather than relying
  on an hour-delayed sweep — and that's the durable value, so build them regardless.

## 3. Scope / Non-goals
**In scope:** trigger trust re-evaluation when a moderator approves a post; a per-user "why is this user
held / not promoted" diagnostic; surface the hold reason to moderators. **Non-goals: do NOT change the
trust thresholds or the warning-freeze *semantics* unattended** — that's a product call (see §5.3); flag
it for review instead. No `acl_entries`/resolver change. Don't touch the co-owner/admin machinery.

## 4. Locked constraints
Reuse `TrustLevelManager` as the single promotion authority (don't fork the threshold logic). On
approval, **do not double-send notifications** — `approvePost()` already calls the notification path, so
any new dispatch must drive only promotion/badges/activity, not a second round of reply/mention emails.
Tests with the feature. Small conventional commits, `-s`, `Tommy Huynh <tommy@saturnhq.net>`; clean-room.
Branch `claude/post-approval-promotion` off `main`.

## 5. The fixes

### 5.1 Eager trust re-evaluation on approval (the core fix)
In `app/Http/Controllers/ModerationController.php` `approvePost()`, after `approved_state='approved'` is
persisted, **re-evaluate the author's trust level** (call the `TrustLevelManager` recompute entry point
for that single user; add one if only a bulk/all-users path exists). An approved post should immediately
count toward lifting TL0 instead of waiting for the hourly cron. Verify whether dispatching
`PostCreated($post)` here is also wanted (for `AwardPostCountBadges` / `RecordPostActivity` so a queue-
approved post earns badges and shows in activity like any post) — **first inspect `PostCreated`'s
listeners**; only dispatch it if it will not re-trigger the reply/mention notifications already sent on
approval. If it would double-notify, do the trust recompute directly and leave a `// TODO` note rather
than risk duplicate emails.

### 5.2 Per-user diagnosis (operability — answers "why is Dan stuck")
Extend the trust-recompute command (`app/Console/Commands/…` for `novfora:trust:recompute`) to accept
`--user=<id|username>` and **print the reasoning**: current level, the computed `earnedLevel`, and the
*reason* it didn't change — one of: `frozen: N live warning point(s)`, `status != active`,
`below threshold (posts X/5, days Y/1, reads Z/5)`, or `eligible → promoted to TLn`. This turns a silent
no-op into a diagnosis. (This is how you'd confirm Dan's actual cause.)

### 5.3 Surface the hold reason + flag the warning-freeze (no silent semantics change)
- In the moderation queue UI, show **why** each held post is held — "New user: 1 of 2 approved posts" or
  "Author trust frozen by active warning(s)" — so a moderator isn't guessing. (Read-only; derive from
  `NewUserModeration` + the trust freeze reason.)
- **Do NOT change** the "a single live warning freezes promotion forever" rule in code. Instead,
  **document it in the PR** as a finding with a recommendation (e.g. "consider: a minor/expiring warning
  shouldn't permanently trap a member in moderation — should the freeze cap at TL-current rather than
  block the TL0→TL1 graduation, or honor warning expiry?") and let Tommy decide on the Cowork side. If
  you want to offer a knob, add a **config flag defaulting to current behavior** (off = no change) rather
  than altering the default.

## 6. Verification / done
Gates green. Tests (`tests/Feature/Moderation/…`):
- A TL0 author with 0 approved posts has post #1 held; a moderator approves it; re-evaluation runs; once
  approved-count reaches the limit (or the author crosses the TL1 threshold), a subsequent post is **not**
  held — the circular trap is broken without waiting on the cron.
- Approval triggers trust recompute exactly once and does **not** send duplicate notifications.
- `novfora:trust:recompute --user=<id>` prints the correct reason for: an eligible user (promotes), a
  below-threshold user, and a user with a live warning (`frozen`).
- The moderation queue shows a hold reason per item.
No `acl_entries`/resolver writes anywhere (confirm). PR to `main` (do not merge); call out the warning-
freeze recommendation for Tommy's product decision.

## 7. Commit
Branch `claude/post-approval-promotion` off `main`; small conventional commits (eager recompute →
diagnostic command → queue reason + freeze write-up); `-s`, `Tommy Huynh <tommy@saturnhq.net>`. PR to `main`.

Read docs/product/post-approval-promotion-kickoff.md and execute it.
