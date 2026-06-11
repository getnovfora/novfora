<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# Spike P2 — baseline deliverability (digest + bounce) — Claude Code kickoff

> The one genuinely uncertain, high-blast-radius seam of Phase 2: **digest email + bounce/complaint/
> suppression on the baseline tier — cron-only, no persistent daemon — without burning the host's sending
> reputation.** Greenlit to run NOW, in parallel with the private beta (it's foundational infra, and beta
> stresses email immediately). Output = a **GO/NO-GO memo + a reference pipeline**, NOT merged feature code
> — the digest/bounce feature work in P2-M2 starts only after this memo says GO. Plan: [phase-2-plan.md](phase-2-plan.md) §4.

---

```
Run the Phase-2 deliverability spike: prove cron-only digest batching + daemon-free bounce/suppression on
the baseline tier, with volume hygiene that won't burn sending reputation. Output a GO/NO-GO memo + a
minimal reference pipeline. This is a SPIKE — favor a focused reference implementation behind a branch and
a written memo over production polish; do not wire it into the live notification path yet.

STEP 0: read PROJECT-STATE.md, phase-2-plan.md §4, the M4 Notifier + notification_preferences +
email_suppressions, routes/console.php (the cron contract — everyMinute/withoutOverlapping discipline,
the M5 queue drain idempotency), the mail config + the live email reality (sendmail on the cPanel host;
from-address MUST be on-domain — the live lesson). Branch from main (post-ACP). Commit identity per
CLAUDE.md (Tommy Huynh <tommy@saturnhq.net>, DCO -s, no AI attribution).

MECHANISM TO VALIDATE (the plan's intended design):
  (a) CRON-BATCHED DIGEST — coalesce a user's pending notifications into ONE email per chosen cadence,
      drained by the existing single cron line. IDEMPOTENT within/across cron ticks (same discipline as
      the M5 queue drain): a tick that overlaps or is killed mid-run never double-sends and never drops.
  (b) DAEMON-FREE BOUNCE/COMPLAINT INGESTION — detect + degrade across three paths:
      provider WEBHOOK endpoint (SES/Mailgun/Postmark-style) when reachable → cron-polled IMAP/POP mailbox
      when configured → VERP/Return-Path + manual-suppression floor as the always-available baseline.
      A hard bounce/complaint auto-suppresses the address; later sends skip it; suppression visible in the ACP.
  (c) VOLUME HYGIENE — a per-tick send cap + per-user rate so a backlog drains over ticks, never one burst.

GO CRITERIA (all must pass — these become PERMANENT tests if GO):
  1. Digest idempotency across ≥2 cron ticks: N notifications → exactly one digest per user per cadence;
     overlap/kill never double-sends or drops. (GO-BLOCKER)
  2. Bounce → suppression, no daemon: a hard bounce/complaint on ANY of the three paths auto-suppresses;
     subsequent sends skip; visible in the ACP.
  3. Volume cap holds: a large backlog respects the per-tick cap + per-user rate and drains over later
     ticks — never one oversized burst.
  4. Graceful absence (forced-absence, mirrors the M4 search/notification contract): no provider / no
     webhook configured → still sends best-effort baseline mail + honours suppression + degrades to the
     VERP/manual floor — NEVER an error.
  5. Preference + 1-click unsubscribe honoured at send time (no mail to an opted-out/suppressed user).

ALSO VALIDATE (the beta-relevant angle): the recommended OUTSIDER-email path is a transactional provider
(Resend/Postmark/Mailgun) — confirm the bounce-webhook path works against one provider's format, and
document the on-domain-from + SPF/DKIM requirement (the live deliverability lesson) in the memo. Baseline
sendmail stays the zero-config floor; the provider is the recommended upgrade for sending to strangers.

FALLBACKS (NO-GO, in order, each keeping suppression + volume cap + graceful-absence intact):
  (1) webhook-only bounce ingestion with a documented baseline limitation (manual suppression where no
      webhook is reachable); (2) digest-as-opt-in with immediate-only baseline default if cron-batch
      idempotency proves fragile on a given host.

DELIVER:
  • docs/product/spike-p2-memo.md — which mechanism passed, the reference pipeline, the cron contract, any
    constraint it imposes on P2-M2, and the outsider-email recommendation. State GO or the chosen fallback.
  • A reference implementation on the branch (minimal; tests for the 5 GO criteria — idempotency across ≥2
    simulated ticks, bounce→suppress per path with a fake provider, volume-cap drain, forced-absence,
    unsubscribe). Suite + Pint/Larastan/audit green. Do NOT flip the live notification path to digests.
  • Branch + PR (the memo + reference + tests). PROJECT-STATE updated with the GO/NO-GO outcome.

SCOPE FENCE: the spike's reference pipeline + memo + its tests only. No P2-M2 feature build, no other
Phase-2 features, no enhanced-tier daemons. If GO, P2-M2 builds the production version on this foundation.
```

---

## After this

GO memo in hand → the P2-M2 digest/bounce feature work has a proven foundation. The rest of **P2-M1
(engagement core — reactions, drafts, oEmbed, polls, tags)** proceeds in parallel and does **not** wait on
this spike (it sits on the M2 content seams). Both feature milestones stay gated on private-beta feedback
having started (plan §8). The spike itself is safe to run immediately — it's infrastructure the beta needs
regardless.
