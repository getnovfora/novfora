<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The Hearth Authors
-->
# P2-M2 Half-A — Deliverability light-up & rich notifications — Claude Code kickoff

> Paste the block below into **Claude Code** to begin **Phase 2 · M2 Half-A**. The digest + tri-path bounce
> pipeline is **already merged and dormant** (Spike P2 → GO, PR #8) behind `HEARTH_DELIVERABILITY=false`. **This
> milestone is LIGHT-UP + WIRE-IN, not a build — do not rebuild the pipeline.** The lone intentional gap is
> wiring `Notifier::send()` → `DigestQueue::enqueue()`, plus three memo follow-ups. Parallel-safe with P2-M1.
> Authoritative specs: [phase-2-implementation-plan.md](phase-2-implementation-plan.md) §2 (M2 Half-A) + §1;
> **[spike-p2-memo.md](spike-p2-memo.md) §4 (the cron contract + the 5 constraints you inherit) + §7 (the
> existing file map)** — read §4 closely, it is the correctness contract. Opus `xhigh` on the wiring + untrusted
> bytes; Sonnet on prefs/vocab/docs.

---

```
Begin Phase 2 — M2 Half-A (Deliverability light-up & rich notifications). The pipeline is MERGED + DORMANT
(HEARTH_DELIVERABILITY=false). This is a LIGHT-UP + WIRE-IN, not a build — do NOT rebuild DigestAssembler,
the bounce paths, Suppressor, VERP, etc. They exist (spike-p2-memo §7 file map). You wire them on and close
three follow-ups.

START OF SESSION: read PROJECT-STATE.md, CLAUDE.md, docs/PROJECT-BRIEF.md. Then docs/product/spike-p2-memo.md
§4 (THE CRON CONTRACT + the 5 inherited constraints — implement exactly) and §7 (the file map of what already
exists), plus docs/product/phase-2-implementation-plan.md §2 (M2 Half-A table) + §1 (engineering contract).
Branch from main AFTER P2-M1 has merged (so the `Reacted`/reaction event seam exists). Commit identity per
CLAUDE.md (Tommy Huynh <tommy@saturnhq.net>, DCO -s, no AI attribution). Suite green before starting.

MODEL/EFFORT (CLAUDE.md routing):
  • Opus 4.8 xhigh — THINK HARD: the Notifier→DigestQueue idempotent wiring, the SuppressionGate dedupe, the
    unsubscribe GET-confirm/POST-apply split, and the SES + Mailgun webhook parsers (untrusted bytes).
  • Sonnet 4.6 — the prefs UI, the event-type vocabulary, .env.example + the operator docs.

THE 5 CONSTRAINTS YOU INHERIT (spike-p2-memo §4 — do NOT violate):
  1) Idempotency lives in the DB, not the lock — the committed UNIQUE(user_id,cadence,period_key) row inside
     the assembler txn IS the guarantee. Do not move the claim out of its transaction or the SMTP send into it.
  2) The send is at-least-once — any "a digest was delivered" reader must tolerate a rare duplicate.
  3) Suppression + cadence are re-checked AT SEND, not only at enqueue. SuppressionGate is the single send gate.
  4) Volume caps are load-bearing for sending reputation — keep the conservative per-tick / per-user defaults.
  5) Wire batched-cadence recipients through DigestQueue::enqueue() — it returns null for immediate/off, so the
     live immediate path stays the default and is UNTOUCHED.

BUILD — light-up + wire-in + 3 follow-ups:

1) ACTIVATE (Sonnet): set HEARTH_DELIVERABILITY=true + HEARTH_DIGEST=true in .env.example with the operator
   checklist (SPF / DKIM / DMARC + the From-must-be-on-your-sending-domain lesson, memo §5). Surface that
   checklist in the ACP email page next to the existing suppressions list (hearth:mail:test already prints it).

2) WIRE Notifier→DigestQueue (⚙): in App\Notifications\Notifier::send(), for batched-cadence recipients call
   DigestQueue::enqueue() FIRST (returns null for immediate/off → fall through to the unchanged live path).
   Keep SendDigestJob idempotent on status. The UNIQUE row is the guarantee (memo §2a) — don't add a lock-based
   one.

3) DEDUPE SUPPRESSION (⚙): make Notifier::suppressed() delegate to the shared SuppressionGate so there is ONE
   send-time suppression gate (memo §4 follow-up), re-checked at send.

4) EVENT VOCAB (Sonnet): add `reaction`, `pm.received`, `follow` to NotificationController::EVENTS, the Notifier,
   the notification display component, and the notification_preferences rows. NOTE — only `reaction` has a LIVE
   emitter right now (P2-M1 emits Reacted): wire reaction notifications END-TO-END (immediate AND digest-batched
   per cadence). `pm.received` fires when PMs land (M2 Half-B); `follow` fires in M3 — add the vocab now so they
   slot in without a later migration, but do NOT fake emitters.

5) PREFS UI (Sonnet): ⚡notification-preferences (per-event × channel rows) + a digest cadence picker over
   DigestPreference (off / immediate / daily / weekly). #[Locked] on any injected id; own-prefs-only.

6) MEMO FOLLOW-UPS (the §8 items, not spike blockers):
   • Unsubscribe GET-confirm / POST-apply split (⚙): a GET shows a confirm page (resists email-scanner
     prefetch); only the POST applies cadence=off. The link stays signed.
   • SES + Mailgun parsers in ProviderWebhookParser (⚙ untrusted bytes): currently only Postmark + generic.
     Map their bounce/complaint payload shapes; the parser stays TOTAL and conservative (garbage → no event,
     never throws, never 500). Pin them with forged-signature / replay / oversize tests like the Postmark ones.
   • Non-VERP bounce-mailbox MANUAL-REVIEW QUEUE (⚙): a mailbox without VERP cannot authenticate a
     sender-supplied address, so it must NOT auto-suppress — queue such bounces for staff review in the ACP
     (memo §2b / §8). VERP/webhook paths are unchanged.

DEFINITION OF DONE (binding): the deliverability suite (DigestIdempotencyTest, BounceIngestionTest,
WebhookSecurityTest, DigestVolumeCapTest, DeliverabilityAbsenceTest, UnsubscribeSendGateTest,
DeliverabilitySchedulerTest) stays green and EXTENDS to the wiring — a reaction notification digest-batches for
batched-cadence users while the immediate path is byte-for-byte unchanged for immediate-cadence users;
forced-absence stays green (no provider / no webhook → VERP + manual floor, never an error); new SES/Mailgun
parser tests (forged/replay/oversize); the GET-doesn't-apply / POST-applies unsubscribe test; the
manual-review-queue path tested; operator email setup visible in the ACP. Pint / Larastan L5 / composer+npm
audit / assets-fresh green; PROJECT-STATE updated. Small conventional DCO commits.

SCOPE FENCE — light-up + wire-in + the 3 memo follow-ups ONLY. Do NOT rebuild the pipeline (memo §7 — it
exists). Flipping the flags must NOT change behaviour for immediate-cadence users (the live path is the
default). NOT here: PMs / conversations (M2 Half-B — its own packet); follow-half, reputation, badges
(Should-tier — HELD); Reverb live-notifications (a daemon — violates the baseline rule, fenced to Phase 4).
Reversible migrations only (the dormant ones already are). Strict clean-room. If a needed mechanism isn't in
the pipeline, flag it — don't invent a parallel one. When Half-A lands runnable + tested, report back here.
```

---

## When Half-A reports back

Cowork reviews: (1) is the live immediate path provably **unchanged** for immediate-cadence users (the wiring
only diverts batched cadence)? (2) does idempotency still rest on the **committed UNIQUE row**, not a new lock?
(3) are the SES/Mailgun parsers **total** under forged/replay/oversize input, and do they still only ever
*write* to the suppression list (no SSRF sink)? (4) does the unsubscribe **GET not apply** anything? Then
updates PROJECT-STATE.

## After this

- **PMs (M2 Half-B)** — its own packet ([p2-m2b-pms-code-kickoff.md](p2-m2b-pms-code-kickoff.md)); `pm.received`
  gets its live emitter there. **Blocked on the §6 account-deletion ADR.**
- Should-tier social (follow, reputation, badges, staff notes, 2nd theme) stays **held**.
