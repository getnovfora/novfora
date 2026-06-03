<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The Hearth Authors
-->
# M3 — Claude Code kickoff prompt (Phase 1, Anti-spam baseline & moderation)

> Paste the block below into the **Claude Code** session to begin Phase 1 **M3**. The Phase 1 plan (M0–M5) is
> owner-approved; **M0 + M1 + M2 are done**. M3 is a **deep-reasoning / security-sensitive** milestone
> (CLAUDE.md) — the anti-spam subsystem is Hearth's headline differentiator, and trust-level gating must run
> **through the existing permission engine**, not a second system.
> Specs: [phase-1-plan.md](phase-1-plan.md) §5 (M3); **[security-and-permissions.md](../architecture/security-and-permissions.md) §2
> (anti-spam, ADR-0007) + §3 (moderation)**; [data-model-initial.md](../architecture/data-model-initial.md) §5
> (moderation/audit) + §6 (anti-spam storage); ADR-0007.

---

```
Begin Phase 1 — M3 (Anti-spam baseline & moderation, ADR-0007). M0+M1+M2 are done; the plan is approved.
This is a security-sensitive milestone — the anti-spam subsystem is the differentiator, and ALL new-user
gating runs through M1's permission engine (no parallel permission system).

STEP 0 — IDEMPOTENCY GUARD + M2 CLOSE-OUT (do this first, before any build):
  • Confirm M3 isn't already done: read PROJECT-STATE.md + `git log --oneline`. If M3 is recorded done or its
    commits exist, STOP and report — do NOT rebuild. (An accidental re-fire must be a no-op.)
  • Confirm M2 is green: bring up the Docker dev env and run the full suite at HEAD (the trailing db6248a was a
    post-completion test fix). If it isn't green, STOP and fix M2 before starting M3.
  • If `hearth-spike/` is still physically on disk, delete it (`rm -rf hearth-spike/` — it's retired in git,
    only untracked build cruft remains). Confirm a clean working tree, then build.

START OF SESSION: read PROJECT-STATE.md, CLAUDE.md, docs/PROJECT-BRIEF.md (standing rule). Then the M3 spec:
docs/product/phase-1-plan.md §5 (M3); docs/architecture/security-and-permissions.md §2 (anti-spam — the
layered defense, the trust-level↔ACL crux in §2.3, the CAPTCHA abstraction §2.5) + §3 (moderation model);
docs/architecture/data-model-initial.md §5 (moderation/audit) + §6 (anti-spam storage).

MODEL/EFFORT: Opus 4.8 at xhigh, THINK HARD on (a) trust-level enforcement expressed through the M1 engine
(NEVER = hard gate on true spam vectors, NO = soft gate), (b) graceful degradation when external services are
absent (nothing may hard-depend on StopForumSpam/CAPTCHA providers), and (c) the privacy/GDPR retention on
registration checks. Open with a SHORT M3 plan, then proceed.

BUILD M3:

1) TRUST LEVELS THROUGH THE ACL (the crux, security §2.3): M1 seeded TL0…TL4 system groups; now wire the
   GATING as acl_entries on those groups — TL0 carries NEVER on true spam vectors (links/images in the first
   window, mass-PM) and NO on soft gates (attachments, post rate); higher TLs grant more. NO second permission
   path — the "why can't X" inspector must explain a new-user block as "TL0 group: post.links = NEVER".
   Auto-promotion/demotion automation (data-model §4 auto_promotion): TL0→TL1 thresholds (reading/time/posts,
   no active flags), demotion on flags/infractions — all configurable, shipped as seeded defaults.

2) REGISTRATION LAYER (security §2.2), tri-state allow/flag/block, FLAG-DON'T-BLOCK on uncertainty:
   StopForumSpam-style blocklist (live API + cron-cached fallback + confidence threshold; degrade to cache +
   heuristics if the API is down); disposable-email block (local list); honeypot + min-fill-timing;
   CAPTCHA provider abstraction (§2.5 — Q&A baseline needs no external service; hCaptcha/Turnstile pluggable,
   degrade to Q&A if unreachable), selectable per action; IP/velocity rules (local); email verification.
   Privacy/GDPR: `registration_checks` carry a configurable retention/purge (data-model §6); IP logging disclosed.

3) POSTING / REACTIVE (security §2.4): a pluggable content-scanning CONTRACT (ship local heuristics behind it;
   the Akismet provider is Phase 2 — build the contract, not the integration); DB-backed rate limiting per
   trust tier (tier-graceful → Redis on enhanced); new-user moderation queue (first N=2 posts, configurable)
   via M2's `approved_state`; Spam Cleaner (bulk-remove a flagged account's content + ban); user/IP/email/range
   bans; word filters.

4) MODERATION + ACP/MCP (security §3): the moderation QUEUE + reports→staff dashboard; warnings/infractions
   (typed, point-weighted, time-decaying, automated consequences at thresholds + required acknowledgment);
   approval workflows via `approved_state`; the ACP (admin) + MCP (moderator) control-panel baseline. Inline
   moderation already partly exists from M2 (lock/pin/move/soft-delete/recycle) — extend, don't duplicate.

DEFINITION OF DONE: anti-spam + permission tests — assert the NEVER hard-gate actually blocks TL0 spam vectors
through the engine (and an admin ALLOW cannot lift a NEVER); tri-state registration outcomes; the moderation
queue + approval flow; bans/word-filters. TIER-GRACEFUL TESTS: force StopForumSpam/CAPTCHA-provider absence →
assert degrade to cache/Q&A + moderation, never an error (this is a non-negotiable suite alongside M0's). All
CI guards green; M0/M1/M2 suites STAY green; runs on the baseline tier (PHP 8.3 + MySQL + cron, no daemons).
Small conventional DCO commits; keep PROJECT-STATE current.

SCOPE FENCE — build ONLY M3. NOT in M3: notifications, search, SEO, theme (M4); reactions, PMs (Phase 2);
the Akismet provider integration (Phase 2 — contract only now); advanced moderation/anti-spam intelligence +
Meilisearch/Reverb (Phase 4). Keep the nullable tenant_id seam; don't build tenancy. Strict clean-room;
security-by-default. When M3 lands runnable + tested, report back here.
```

---

## When M3 reports back

The Cowork session reviews M3 — with particular attention to (1) the trust-level gating actually flowing
through the **M1 engine** (the inspector explains a TL0 block; no parallel permission path), (2) the
**tier-graceful degradation** tests (StopForumSpam/CAPTCHA absent → no error), and (3) the privacy/GDPR
retention on registration checks. Then it updates PROJECT-STATE and preps **M4** (notifications · search · SEO ·
theme) — the last build milestone before M5 operability closes out Phase 1 / the MVP.
