<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The Hearth Authors
-->
# Spike P2 — baseline deliverability (digest + bounce) — GO/NO-GO memo

> **Verdict: GO** (with criterion #1 stated honestly as *exactly-once assembly + effectively-once delivery*).
> **Date:** 2026-06-08. **Branch:** `claude/spike-p2-deliverability` (off `main`, post-ACP).
> **Status:** spike reference pipeline + tests, **dormant behind `hearth.deliverability.enabled` (default
> false)** — the live immediate notification path (`App\Notifications\Notifier`) is **untouched**. This is the
> Phase-2 analog of Spike 0: a focused de-risk of the one uncertain, high-blast-radius Phase-2 seam, per
> [phase-2-plan.md](phase-2-plan.md) §4 and [the kickoff](spike-p2-deliverability-kickoff.md). It is **not**
> merged feature code — P2-M2 builds the production version on this foundation only because this memo says GO.

---

## 1. TL;DR

Cron-only digest batching **and** daemon-free bounce/complaint/suppression are **viable on the baseline tier**
(PHP 8.3 + MySQL/MariaDB + the single cron line, no daemon, no Redis, no worker), with volume hygiene that
won't burn the host's sending reputation. All five GO criteria are met by a reference pipeline that:

- adds **zero new Composer dependencies**,
- depends on **no enhanced-tier service** (degrades cleanly under forced absence),
- keeps the **live immediate path unchanged**, and
- rests its one hard guarantee (digest idempotency) on a **committed DB UNIQUE row inside a transaction**, not
  on a lock that a coarse/killed cron could betray.

**→ GO.** Adopt the cron-batched digest + tri-path bounce pipeline as the P2-M2 mechanism. Neither fallback
was triggered.

---

## 2. What was validated (the intended mechanism)

### (a) Cron-batched digest — exactly-once assembly

Pending notifications for digest-cadence users are staged into a durable ledger (`digest_queue_items`). One
cron tick (`hearth:deliverability:digest-run`, `everyMinute → withoutOverlapping → skip during restore`)
**assembles** due digests:

```
per due user, in ONE DB transaction:
  INSERT digest_runs (user_id, cadence, period_key, status='claimed')   ← the claim/gate
        └ duplicate-key (a racing/repeat tick) → ROLLBACK + skip          (exactly-once)
  UPDATE digest_queue_items SET digest_run_id = <run> WHERE unclaimed … LIMIT <per_user_rate>
  status='built'
COMMIT                                                                    ← run row + claim commit together
then (outside the txn): dispatch SendDigestJob(run); stamp mailed_at      ← two-phase self-heal flag
```

The keystone is **`UNIQUE(user_id, cadence, period_key)`**. `period_key` is a **floored time bucket** (daily
`2026-06-08`, weekly ISO `2026-W24`) computed from `now()`, so every coarse/overlapping tick in the same period
derives the identical key and collides on the unique index — **exactly one digest assembled per user per
period.** The send itself is a separate idempotent job (`SendDigestJob`, drained by the existing M5
`queue:work` cron) that no-ops if the run is already `sent`.

**The honest claim for GO criterion #1** (this is the one wording the spike deliberately sharpened):

> The digest pipeline guarantees **exactly-once assembly and claim**, proven by a `UNIQUE(user_id, cadence,
> period_key)` row committed inside the assembler transaction: no overlapping, coarse, or pre-commit-killed
> tick can double-assemble or drop. **Delivery is effectively-once**: the only residual double-*delivery*
> window is the SMTP-accept-to-DB-commit gap, which is **identical to and no wider than** the window the
> existing immediate `NotificationMail` path already accepts on the baseline tier. Closing it fully would
> require a transactional outbox plus provider idempotency keys (enhanced tier) — out of baseline scope.

A process killed at every step was walked through and is safe: pre-commit → atomic rollback (no run, items
un-claimed); post-commit/pre-enqueue → a `built` run with `mailed_at IS NULL` is re-dispatched by the next
tick's **self-heal** pass (the UNIQUE row blocks re-assembly, the send job blocks re-send). The only
double-*delivery* window is the irreducible SMTP-accept→`status='sent'` gap shared with the live path.

### (b) Daemon-free bounce/complaint ingestion — three paths, detect + degrade

`App\Deliverability\DeliverabilityManager` resolves the active path in order and degrades cleanly:

1. **Provider webhook** (`POST /webhooks/mail/{provider}`, registered only when configured): trust is an
   **HMAC over the raw body** + a timestamp **replay window** + a **replay-dedupe ledger**
   (`mail_webhook_events`), never reachability. A missing/forged/stale signature writes nothing (401);
   oversize → 413; malformed JSON → 422; **never 500, never suppresses a bogus address.** It only ever
   *writes* to the suppression list — it never fetches a URL from the payload, so it is **not an SSRF sink**.
   A reference parser handles **Postmark** (the recommended provider) and a `generic` shape.
2. **Cron-polled IMAP mailbox** (`hearth:deliverability:poll-bounces`): guarded by `extension_loaded('imap')`;
   absent → bound to `NullBounceMailbox` (no-op). It is the *delivery* mechanism; **authentication is VERP**
   (see 3). Raw messages are parsed clean-room from **RFC 3464 (DSN)** and **RFC 5965 (ARF)** — body headers
   are used **only to classify** (permanent `5.x.x` → suppress; **transient `4.x.x` NEVER**; ARF → complaint).
   The parser is **total** (any garbage → no event, never throws).
3. **VERP signed Return-Path + manual floor** (always available): the recipient is embedded in the envelope
   sender with an **HMAC in the local-part**. This is the **sole authoritative identity** for the mailbox
   path — the bounce is suppressed for the user the *signed* address decodes to; attacker-controlled body
   headers (`Final-Recipient`, embedded `To:`) are **never** trusted as identity. A forged VERP address fails
   the HMAC and suppresses nobody. **Without VERP, the polled mailbox auto-suppresses nothing** (it cannot
   authenticate a sender-supplied address — the safe baseline is the webhook or the manual floor; a non-VERP
   mailbox would need a manual-review queue, a P2-M2 follow-up). The **manual ACP floor** (Admin → System →
   Email suppressions) works with no provider at all — satisfying "visible in the ACP."

   > **This was the one HIGH the adversarial review caught and the design absorbed** (§9): the first cut
   > preferred body recipient headers over VERP, which would have let anyone who can email the bounce mailbox
   > suppress an arbitrary victim (suppression-as-DoS). Making the signed VERP address the *only* identity is
   > both the fix and a cleaner tri-path story — the mailbox is a transport, VERP/HMAC is the authentication.

A hard bounce/complaint from any path auto-suppresses via the shared, idempotent `Suppressor` (reuses the
existing `email_suppressions` table; reasons `bounce | complaint | manual`). Suppression is enforced at send
by the shared `SuppressionGate`.

### (c) Volume hygiene

Two dials, both in the dormant config block: **`max_users_per_tick`** (the per-tick send cap — a backlog
drains over later ticks, never one burst) and **`per_user_item_rate`** (one digest's max size; overflow rolls
to the next period). Because `period_key` is time-bucketed (not tick-bucketed), capping only **delays** a user
to a later tick — it can never double-send or drop. The global send rate is additionally bounded by the
existing M5 `queue:work --max-time=50` drain.

---

## 3. GO criteria → evidence (these become PERMANENT tests on GO)

| # | Criterion | How it's met | Test |
|---|---|---|---|
| 1 (blocker) | Digest idempotency across ≥2 ticks; overlap/kill never doubles or drops | UNIQUE(user,cadence,period) committed in-txn; floored period_key; two-phase `mailed_at` self-heal; idempotent send job | `DigestIdempotencyTest` (repeat/overlap tick → 1 digest; DB unique rejects a dup; kill-before-commit → rollback, no drop, clean re-run → 1; self-heal; period roll-forward) |
| 2 | Bounce → suppression on any path; transient not suppressed; visible in ACP | webhook (HMAC), DSN/ARF parse, VERP; transient `4.x.x` skipped; ACP list + manual add/remove | `BounceIngestionTest` (DSN/ARF/VERP/poll + forged-VERP + ACP render) · `WebhookSecurityTest` (HMAC/replay/forgery/413/422) |
| 3 | Volume cap holds; backlog drains over ticks | per-tick user cap + per-user item rate; period-bucketed so no double/drop | `DigestVolumeCapTest` |
| 4 | Graceful absence — never an error | NullBounceMailbox; `ingestAvailable()` returns 0; VERP no-op; manual floor | `DeliverabilityAbsenceTest` (mirrors `ServiceTierFallbackTest`) |
| 5 | Preference + 1-click unsubscribe honoured at send | signed unsubscribe → cadence `off`; gate re-checked at assembly AND in the send job | `UnsubscribeSendGateTest` |
| — | Cron contract | both ticks wired with `withoutOverlapping` + short mutex (`expiresAt < 60`) + `skip(duringRestore)`; commands no-op while dormant | `DeliverabilitySchedulerTest` |

> **Test-execution caveat (as in every prior RH-* / ACP pass):** this authoring environment has **no PHP /
> Composer / MySQL**, so the Pest suite, Pint, Larastan, and `composer/npm audit` are the **Docker `php:8.3` /
> CI step**. Correctness here was established by design (the pre-implementation design panel) + reading + an
> **adversarial multi-lens review of the diff with per-finding verification** (the same method that caught the
> RH-10/RH-11 HIGH bugs). The tests are written against the suite's SQLite `:memory:` + `QUEUE_CONNECTION=sync`
> + `MAIL_MAILER=array` config; criterion-1's transactional rollback is also valid on MySQL/InnoDB.

---

## 4. The cron contract (the constraint P2-M2 inherits)

Two lines join the single cron entry (`* * * * * php artisan schedule:run`), both following the M5 drain
discipline and **dormant until the flag**:

```php
Schedule::command('hearth:deliverability:digest-run')
    ->everyMinute()->withoutOverlapping($shortMutex)   // short mutex (≥2 min, <60) — the DB UNIQUE row is the
    ->name('hearth-digest-run')->skip($duringRestore);  // real guard; a SIGKILL must not strand for a day
Schedule::command('hearth:deliverability:poll-bounces')
    ->everyMinute()->withoutOverlapping()
    ->name('hearth-poll-bounces')->skip($duringRestore);
```

**Constraints P2-M2 must honour:**

- **Idempotency lives in the DB, not the lock.** The unique `(user_id, cadence, period_key)` row + the in-txn
  claim is the contract. Do not "optimise" the claim out of a transaction, and do not move the SMTP send
  inside it. Keep the send job idempotent on `status`.
- **The send is at-least-once.** Any downstream feature that reads "a digest was delivered" must tolerate a
  rare duplicate (same as all baseline mail). Don't build exactly-once *delivery* assumptions on top.
- **Suppression + cadence are re-checked at send**, not only at enqueue. Keep `SuppressionGate` as the single
  send-time gate (and consider delegating `Notifier::suppressed()` to it in P2-M2 — not done in this spike).
- **Volume caps are load-bearing for reputation.** Keep conservative per-tick/per-user defaults; never burst.
- **Wire P2-M2's notification path through `DigestQueue::enqueue()`** for batched-cadence users (it returns
  null for `immediate`/`off`, so the live immediate path stays the default and is untouched here).

---

## 5. Outsider-email recommendation (the live deliverability lesson)

Sending to **strangers** (verification mail, digests to people who haven't whitelisted you) is where baseline
shared-host SMTP burns reputation. The recommended path, to be documented for operators in P2-M2:

- **Use a transactional provider** (Postmark / SES / Mailgun / Resend) as the enhanced upgrade. The webhook
  bounce path was validated against **Postmark's** payload shape; SES/Mailgun differ only in the parser map.
- **The From address MUST be on your sending domain** (the live cPanel lesson — an off-domain From fails
  SPF/DKIM alignment and lands in spam or is rejected). VERP rewrites only the **envelope sender / Return-Path**
  — the on-domain `From` is untouched, preserving alignment.
- **Publish SPF, DKIM, and DMARC** for the sending domain. `hearth:mail:test` already prints this checklist;
  P2-M2 should surface it in the ACP email page next to the suppression list.
- **Baseline `sendmail`/SMTP stays the zero-config floor** — it works, it just isn't reputation-safe for
  strangers. The provider is the recommended upgrade, not a requirement.

---

## 6. Fallbacks (defined, NOT triggered)

The kickoff defined two NO-GO fallbacks; neither was needed, and both are cheap config/scope retreats (not
redesigns) because the dangerous part — idempotency — rests on a committed UNIQUE row, not on any absent
service:

1. **Webhook-only bounce ingestion** (drop the baseline IMAP/DSN/ARF parser, document manual suppression where
   no webhook reaches): would have been forced only if the clean-room MIME report parser proved materially
   harder than scoped. It didn't — the parser is total and conservative. *Not triggered.*
2. **Digest-as-opt-in with immediate-only baseline default**: would have been forced if per-tick claim
   transactions couldn't sustain a realistic backlog within a coarse cron interval. The per-tick cap bounds
   exactly this. *Not triggered* — and note the digest already ships **opt-in/dormant**, so this fallback is
   effectively "leave the flag off," a config decision, not a redesign.

---

## 7. Reference pipeline — file map

- **Digest:** `app/Deliverability/Digest/{DigestAssembler,DigestQueue,PeriodKey,DigestNothingToSend}.php`,
  `app/Jobs/SendDigestJob.php`, `app/Mail/DigestMail.php`, `resources/views/mail/digest.blade.php`.
- **Bounce/suppression:** `app/Deliverability/{DeliverabilityManager,Suppressor,SuppressionGate,Verp}.php`,
  `app/Deliverability/Bounce/{BounceEvent,BounceMailbox,NullBounceMailbox,ImapBounceMailbox,BounceParser}.php`,
  `app/Deliverability/Webhook/{WebhookVerifier,ProviderWebhookParser}.php`,
  `app/Http/Controllers/MailWebhookController.php`.
- **Preference / unsubscribe:** `app/Deliverability/Unsubscribe.php`,
  `app/Http/Controllers/UnsubscribeController.php`, `resources/views/deliverability/unsubscribed.blade.php`.
- **Models / schema:** `app/Models/{DigestRun,DigestQueueItem,DigestPreference,MailWebhookEvent}.php` +
  `database/migrations/2026_06_08_0008{01..04}_*.php` (reversible; no change to `email_suppressions` /
  `notification_preferences`).
- **ACP:** `resources/views/admin/suppressions.blade.php` + `…/components/admin/⚡suppressions.blade.php`
  (Admin → System → Email suppressions); nav entry in `app/Admin/AdminNavigation.php`.
- **Wiring (dormant):** `config/hearth.php` (`deliverability` block), `routes/console.php`, `routes/web.php`,
  `bootstrap/app.php` (CSRF exempt for the two machine endpoints), `bootstrap/providers.php` +
  `app/Providers/DeliverabilityServiceProvider.php`, `.env.example`.
- **Tests:** `tests/Feature/Deliverability/*` + `tests/Support/Deliverability.php`.

---

## 8. Adversarial review outcome

The diff was put through a 6-lens adversarial review (idempotency, untrusted-input/SSRF, Laravel/Larastan
runtime, volume/absence/unsubscribe, scope/clean-room/dormancy, test efficacy) with **per-finding
verification** (each finding independently re-checked by a skeptic who defaults to "refuted"). **13 findings
raised → 11 refuted → 2 confirmed and fixed in this branch:**

- **HIGH — suppression-as-DoS (bounce parser):** the IMAP/DSN/ARF parser preferred unauthenticated body
  recipient headers over the signed VERP address, so anyone who could email the bounce mailbox could suppress
  an arbitrary victim. **Fixed:** the signed VERP address is now the *sole* identity; body headers classify
  only; no VERP → no auto-suppression (see §2b). New regression tests pin the attack (forged Final-Recipient /
  forged VERP / no-VERP → no suppression).
- **MEDIUM — gated-user starvation/leak (assembler):** an unsubscribed/suppressed user with staged items was
  re-scanned every tick forever (never got a run row), starving the per-tick cap and leaking unclaimed items.
  **Fixed:** a gated user's period is *retired* (a terminal `sent`/item-0 run + their items claimed), mirroring
  `SendDigestJob::markSent($run, 0)`; new regression test proves active users aren't starved and the gated
  user isn't re-scanned.

The 11 refuted findings were either framework-handled (e.g. PHPStan resolves `imap_*` via bundled stubs, so
the IMAP reader is analysis-clean), moot under the test config, or out of spike scope. Two were noted as
**P2-M2 follow-ups** rather than spike blockers: a GET-applies unsubscribe link should split into
GET-confirm / POST-apply to resist email-scanner prefetch; and a non-VERP bounce mailbox would want a
manual-review queue. Neither affects the GO verdict.

## 9. After this

GO memo in hand → the **P2-M2** digest/bounce feature work has a proven foundation and the constraints above.
The rest of **P2-M1** (engagement core) proceeds in parallel and does not wait on this spike. Both feature
milestones stay gated on private-beta feedback having started (plan §8). To activate the pipeline, P2-M2 flips
`HEARTH_DELIVERABILITY=true` (+ `HEARTH_DIGEST=true` and the chosen bounce path) and wires the notification
path through `DigestQueue::enqueue()` for batched-cadence recipients.
