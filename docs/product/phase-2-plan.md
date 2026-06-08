<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The Hearth Authors
-->
# Phase 2 Plan — Community (plan-before-code)

> **Project:** NevoBB (the engine — "Hearth" was the working codename, retired at **ADR-0024**, 2026-06-07;
> the codebase string rename `hearth → nevobb` is a separate planned change — see §7). **Status: DRAFT for
> owner approval — NO Phase-2 application code is written until this plan is signed off** (plan-before-code per
> the working agreement). **Date:** 2026-06-08.
> **Stack (locked, ADR-0001/0002):** Laravel 13 · Livewire 4 · Alpine · Blade · PHP 8.3 · MySQL 8/MariaDB.
> **Phase framing:** **Community** — engagement + the moderation depth XF/IPS are known for, on the proven
> Phase-1 engine, all the way to the **Public Beta gate**.
> **Grounding:** [roadmap](roadmap.md) Phase 2 · [feature-prioritization](feature-prioritization.md) ·
> [status-review-2026-06-06](status-review-2026-06-06.md) · [PROJECT-STATE](../../PROJECT-STATE.md) ·
> ADRs in [DECISIONS.md](../../DECISIONS.md). This plan is the brief's "plan before each phase" gate.

---

## 1. Goal & definition of done

**Goal:** turn a proven forum *engine* into a community people **come back to** — reactions, multi-participant
PMs, activity feeds, digest email, polls/prefixes/tags, richer moderation — **without breaking the
always-runnable baseline rule, the unified permission engine, the anti-spam posture, or the semver'd
no-core-edit theme API**. Phase 1 proved NevoBB installs and runs; Phase 2 makes it *sticky* and earns the
**Public Beta** invite list.

**Definition of done (Phase 2 exit criteria):**
1. **Every new feature runs on the baseline tier** (PHP 8.3 + MySQL + the single cron line, no daemon) and
   **degrades gracefully** when enhanced services are absent — the **service-tier forced-absence suite stays
   green**, extended to the new surfaces (mail provider / bounce webhook / Meilisearch facets absent).
2. **Notifications & digests respect deliverability hygiene** — bounce + complaint handling, suppression,
   volume caps, one-click unsubscribe, **no persistent daemon required** (the named roadmap exit).
3. **The two non-negotiable suites STAY green** — permission-mask truth-tables + service-tier fallback — and
   **every new gated action authorizes through the M1 engine** (no second permission system; PMs/reactions/
   feeds are gated and anti-spam-gated, not a new code path).
4. **CI guards stay green:** Pint, Larastan, `composer audit` / `npm audit`, Pest, **Dusk + the screenshot
   gate**, the **assets-fresh drift guard**, and the **query/asset/perf budgets** (system-architecture §7),
   plus the **admin-render mirror** (ACP v1.1) extended to any new admin/MCP page.
5. **New user-content surfaces don't reopen the spam door** — PMs, reactions, and feeds inherit the M3
   anti-spam controls (TL gates through the ACL, trust-tiered rate limits, report/ignore).
6. **Theme API v1.x stays backward-compatible** (no core-edit theming); a **real 2nd example/child theme**
   proves the override layer end-to-end.
7. **The baseline operability contract holds:** reversible migrations, backup/restore, and the **RH-10
   auto-upgrade** + **RH-11 panel restore** paths all still pass after the Phase-2 migrations land; demo seed
   + getting-started + `.env.example` are current.
8. **→ Public Beta entry criteria met:** Phase-2 core has landed runnable on baseline and the private-beta
   feedback has been folded in (status-review §5).

---

## 2. Sequencing — de-risk the one uncertain seam first, then build outward

```
Spike P2  ──►  P2-M1 Engagement & content depth ─┐
(baseline       (reactions · drafts/autosave ·   │
 deliverability  oEmbed · diffs · polls/          │
 digest+bounce)  prefixes/tags UI)                ▼
   │                                     P2-M2 Messaging & notifications depth
   └── gates the digest/bounce          (multi-participant PMs · rich + DIGEST
       work in P2-M2 only                notifications · bounce/suppression · prefs)
                                                   │
                                                   ▼
                              P2-M3 Social, reputation & profiles ──► P2-M4 Moderation depth & discovery
                              (activity feeds · follow/ignore ·        (cross-page bulk select · merge/split ·
                               reputation · badges · community-feel)    staff notes · search filters/facets)
                                                   │                                  │
                                                   └──────────────┬───────────────────┘
                                                                  ▼
                                          P2-M5 Theme proof · beta polish · runnable milestone → 🚩 Public Beta
```

**Why this order:** Unlike Phase 1, Phase 2 has **no single do-or-die unknown** — the engine, editor,
permission masks, anti-spam, and tier abstraction are all proven and live-validated. The **one genuinely
uncertain, high-blast-radius seam is baseline deliverability** (digests + bounce handling on a no-daemon
shared host — and it's the *only* roadmap exit beyond "tested + runnable"). So we **spike it first** and let
the rest proceed in parallel: content-depth features (P2-M1) sit on the M2 canonical-content seams and don't
wait on the spike; the messaging/notification milestone (P2-M2) consumes the spike's result. Social/reputation
(P2-M3) and moderation/discovery (P2-M4) layer on top; P2-M5 proves the theme API and wraps the runnable
milestone. **Each milestone lands independently runnable + tested on the baseline tier.**

---

## 3. Scope — Phase 2 Community (with the Phase-1 reconciliation)

The roadmap's Phase-2 deliverable list was written before Phase 1 shipped Full-MVP. Several items it names
**already shipped in Phase 1** and must be struck from active scope — mirroring the reconciliation
`phase-1-plan.md` §3 applied to its own list.

### 3A. Already shipped in Phase 1 — NOT re-built in Phase 2 (with milestone ref)

| Roadmap listed under P2… | Actually shipped in | Status |
|---|---|---|
| **Reports** → staff dashboard | M3 | ✅ done |
| **Warnings/infractions** (points, time-decay, auto-consequences, required ack) | M3 | ✅ done |
| **Trust-level promotion rules** | M3 (`hearth:trust:recompute` cron) | ✅ done |
| **Edit history** (post revisions, storage) | M2 (`post_revisions`) | ✅ stored — only the **diff *viewer*** remains (→ P2-M1) |
| **Custom profile fields** | M4 (ACP CRUD) | ✅ done |
| **Markdown input mode** | M2 (editor toggle) | ✅ done |
| **Signatures · avatars · covers** | M4 | ✅ done |
| **Opt-in 2FA for general users** | M1 | ✅ done |
| **Per-event × channel notification preferences** | M4 (`notification_preferences`) | ✅ baseline — Phase 2 *extends* (digest cadence, granular email prefs) |
| **Light / dark + density** (a P1→P2 trim) | Theme phase (2026-06-06) | ✅ done — only the **2nd example theme** remains (→ P2-M5) |
| **Inline thread-view moderation** | M2/M3 | ✅ done — only **cross-page bulk select** remains (→ P2-M4) |

> **Net effect:** the *moderation-depth* half of "Community" largely shipped early. Phase 2's real weight is
> **engagement** (reactions, PMs, feeds, digests, polls/tags) + the **deferred editor/discovery items** +
> the **Should-tier social features** — not a re-tread of M3.

### 3B. Genuinely in Phase 2 (what we build)

| Area | Feature | Source | Milestone |
|---|---|---|---|
| Content | **Reactions / likes** (XF reaction-score model) | FP `S`/P2 | P2-M1 |
| Content | **Drafts / autosave** (the Spike-0 §1b-deferred editor feature) | FP `S`/P2 | P2-M1 |
| Content | **Edit-history diff viewer** (over existing `post_revisions`) | FP `S`/P2 | P2-M1 |
| Content | **oEmbed** native embedding (SSRF-safe) | FP `S`/P2 | P2-M1 |
| Content | **Polls UI** (over the M2 model seam) + voting | FP `S`/P2 | P2-M1 |
| Content | **Topic prefixes UI** + **Tags UI** (`tags`/`taggables`) + filtered listings | FP `S`/P2 | P2-M1 |
| Messaging | **Multi-participant PMs / conversations** | FP `S`/P2 | P2-M2 |
| Messaging | **Rich notifications** (reactions/PM/follow events, merge-aware) + **digest emails** | roadmap P2 | P2-M2 |
| Messaging | **Email bounce / complaint / suppression** handling + **granular email/digest prefs** + 1-click unsubscribe | roadmap P2 | P2-M2 |
| Social | **Activity feeds** | FP `S`/P2 | P2-M3 |
| Social | **Follow / ignore** | FP `S`/P2 | P2-M3 |
| Social | **Reputation / points** (distinct from infraction points) | FP `S`/P2 | P2-M3 |
| Social | **Badges / trophies / achievements** (criteria engine) | FP `S`/P2 | P2-M3 |
| Social | **Community-feel pack** (info-center/forum stats, group colours, view-count incrementing — the theme-polish "Part B") | status-review | P2-M3 |
| Moderation | **Cross-page bulk moderation select** (inline already done) | roadmap P2 | P2-M4 |
| Moderation | **Merge / split topics** | FP `S`/P2 | P2-M4 |
| Moderation | **Staff notes** (on users) | FP `S`/P2 | P2-M4 |
| Discovery | **Search filters / facets** (the other P1→P2 trim) | trim | P2-M4 |
| Users | **Consolidated user-preferences** surface | roadmap P2 | P2-M4 |
| Theming | **2nd example/child theme** (proves the override layer; dark mode already shipped) | trim | P2-M5 |

**Two owner trims from Phase 1 folded back in:** (a) **search filters/facets** (P2-M4); (b) the **2nd example
theme** — note **dark mode + density already shipped** in the theme phase, so this trim is now *only* the
second theme, not the dark-mode work it was paired with.

### 3C. Explicitly NOT in Phase 2 (→ Phase 3+, per [roadmap](roadmap.md))

Module/plugin API + hook/UI-slot system; the **visual point-and-click theme configurator**; public REST API +
webhooks; **phpBB/MyBB/SMF importers**; admin analytics dashboards; BBCode *input* layer; SSO (OAuth2/OIDC/
SAML); paid memberships/subscriptions; Clubs; **Meilisearch/Typesense + Reverb real-time + PWA/push** (enhanced
tier — the *driver seams* exist from P1 and degrade gracefully; lighting them up is Phase 4); multi-tenant
(never — seam only). *If timeline pressure appears, the §3B **Should-tier** social items are the first descope
lever — see §8.*

---

## 4. Spike P2 — baseline deliverability (digest + bounce) — **do this first**

**Objective:** prove that **digest email and bounce/complaint/suppression handling work on the baseline tier
— cron-only, no persistent daemon, no streaming worker — without burning the host's sending reputation**,
before the notification milestone (P2-M2) commits to a mechanism. This is the Phase-2 analog of Spike 0: a
focused de-risk of the one uncertain, high-blast-radius seam, output = a **GO memo + a reference pipeline**.

**Intended mechanism (validate this):** extend the M4 `Notifier` + `email_suppressions` with **(a)** a
**cron-batched digest** that coalesces a user's pending notifications into one email per chosen cadence,
**idempotent within a single cron interval** (no duplicate or dropped digests across ticks — the same
coarse-cron discipline as the M5 queue drain); **(b)** **bounce/complaint ingestion with no daemon**, detected
and degrading across three paths — provider **webhook** endpoint when reachable (SES/Mailgun/Postmark-style),
**cron-polled IMAP/POP** mailbox when configured, and a **VERP / `Return-Path` + manual-suppression** floor as
the always-available baseline; **(c)** **volume hygiene** — a per-tick send cap + per-user rate so a backlog
can't burst the host's mail quota.

**Acceptance / GO criteria — all must pass:**
1. **Digest idempotency across cron ticks:** N notifications → exactly one digest per user per cadence; a
   tick that overlaps or is killed mid-run never double-sends and never drops (test drives ≥2 ticks). **(GO-blocker)**
2. **Bounce → suppression, no daemon:** a hard bounce / complaint on any of the three ingestion paths
   **auto-suppresses** the address; subsequent sends to it are skipped; the suppression is visible in the ACP.
3. **Volume cap holds:** with a large pending backlog, a single tick respects the per-tick cap + per-user
   rate and drains the remainder on later ticks — never one oversized burst.
4. **Graceful absence (forced-absence):** no enhanced provider / no webhook path configured → still sends
   best-effort baseline mail + honours the suppression list + degrades to the VERP/manual floor — **never an
   error** (mirrors the M4 search/notification forced-absence tests).
5. **Preference + unsubscribe end-to-end:** per-user digest cadence + 1-click unsubscribe are honoured at
   send time (no email to an opted-out / suppressed user).

**GO** = all pass → adopt the cron-batched digest + tri-path bounce pipeline as the P2-M2 mechanism.
**Fallback (NO-GO), in order:** (1) **webhook-only** bounce ingestion with a documented baseline limitation
(manual suppression only where no webhook is reachable); (2) **digest-as-opt-in** with immediate-only as the
baseline default if cron-batch idempotency proves fragile on a given host. Each fallback keeps the suppression
list + volume cap + graceful-absence boundary intact.

**Spike exit:** a short memo records which mechanism passed, the reference pipeline + the cron contract, and
any constraint it imposes on P2-M2. **No digest/bounce feature work in P2-M2 starts until this memo says GO**
(the rest of P2-M1 proceeds in parallel regardless).

---

## 5. Milestones (each lands runnable + tested on the baseline tier)

**P2-M1 — Engagement & content depth.** Sits on the M2 canonical-content seams; high visible value, low
coupling — can run in parallel with the spike. **Reactions** (XF reaction-score model): typed reactions →
denormalized counters via model events (like M2 counters), cached as **primitives only + rehydrate after the
boundary** (RH-9 discipline), **gated through the engine** and **TL-gated** as a content surface. **Drafts /
autosave**: the Spike-0 §1b-deferred feature — **debounced *network* autosave** (per Spike-0 finding #3: only a
network call is debounced, the JS sync stays immediate) + `@persist` for `wire:navigate` cursor restoration;
server-side per-user draft storage, own-only. **Edit-history diff viewer** over the existing `post_revisions`.
**oEmbed** (security-sensitive): **server-side fetch with a host allowlist, no private-IP / no redirect-to-
internal, timeouts, size caps, cached** — the canonical-store + server-sanitize boundary holds (client never
supplies embed HTML). **Polls UI** over the M2 model seam (+ voting, per-permission). **Topic prefixes UI** +
**Tags UI** (`tags`/`taggables`) + filtered listings.

**P2-M2 — Messaging & notifications depth** (consumes the Spike P2 result). **Multi-participant PMs /
conversations**: new `conversations`/`conversation_user`/`messages` schema, **reusing the M2 canonical content
pipeline + sanitizer** (no new render path), **permission-gated via the engine and anti-spam-gated** — the
**TL0 mass-PM NEVER gate already seeded in M3** stands, plus trust-tiered rate limits, recipient **block/ignore**,
and report-on-PM. **Rich notifications**: more event types (reactions, PMs, follows), grouped/merge-aware,
extending the M4 `Notifier`. **Digest emails** + **bounce/complaint/suppression** (the spike's pipeline).
**Granular email/digest preferences** (extend `notification_preferences`) + **1-click unsubscribe**.

**P2-M3 — Social, reputation & profiles.** **Activity feeds**: **fan-out-on-read on baseline** (no Redis),
per-viewer **permission-filtered** like M4 search, **within the query budget** (cached; cron-warmed only where
measured). **Follow / ignore** (drives feed inclusion, notification routing, and the PM ignore). **Reputation /
points** — **distinct from M3 infraction points** — accrued from reactions/posts, surfaced on the profile.
**Badges / trophies / achievements**: a criteria engine (domain events → idempotent awards; cron-recomputed
where a criterion isn't event-derivable). **Community-feel pack** (the theme-polish "Part B"): forum
info-center/stats, group colours/role badges, **view-count incrementing**.

**P2-M4 — Moderation depth & discovery.** **Cross-page bulk moderation select** (inline moderation already
shipped — this is only the XF-style select-across-pages). **Merge / split topics**: recompute both sets of
counters **authoritatively** + audit, mirroring the ACP structure-manager delete-safety pattern (never silent).
**Staff notes** on users (staff-only, audited). **Search filters / facets** (the P1 trim): author / forum /
date / tag / type filters over the **Scout DB driver**, **degrading to a direct DB query when Meilisearch is
absent** (the M4 search forced-absence contract). **Consolidated user-preferences** surface (folds Appearance +
notification + new prefs into one page).

**P2-M5 — Theme proof, beta polish & the runnable milestone.** A **real 2nd example/child theme** exercising
the **semver'd Blade override layer** end-to-end (the P1 trim — light/dark already shipped, so this proves the
*second theme*, not dark mode), as the theme-API regression proof. **Fold in private-beta feedback.** Refresh
the **demo seed** (reactions/PMs/feeds/polls visible) + **getting-started** + `.env.example`. Re-run the
**perf/asset/query budgets** + the **forced-absence suite** + the **RH-10 auto-upgrade / RH-11 restore
rehearsal** against the Phase-2 migrations. → the **🚩 Public Beta gate**.

---

## 6. Testing & quality gates (per [testing-strategy](../architecture/testing-strategy.md))

- **Carry-forward non-negotiables stay green:** permission-mask truth-tables + service-tier **forced-absence**
  — the latter **extended** to the new surfaces (mail provider absent, bounce webhook absent, Meilisearch
  absent for facets → degrade, never error).
- **Anti-spam regression on the new surfaces:** PMs (the **TL0 mass-PM NEVER gate stays a hard gate an admin
  ALLOW can't lift** — pin it like M3 did; rate limits; block/ignore), reactions, and feeds don't reopen the
  spam door.
- **Deliverability suite (the spike's criteria, as permanent tests):** digest idempotency across ≥2 cron
  ticks, bounce→suppression on each path, volume-cap drain, unsubscribe/suppression honoured at send.
- **oEmbed SSRF battery** (private-IP, redirect-to-internal, oversize, timeout) — the same discipline as the
  M2 XSS render battery; the canonical/sanitize boundary stays the security line.
- **Cache-poisoning guard (RH-9 class)** on every new cached surface (feed pages, reaction counts): **primitives
  only in cache + rehydrate after the boundary**, with a **serializing-store cache-HIT test**.
- **Budgets in CI:** feed-page + PM-inbox query counts (no N+1, within the ≤30/≤15 budgets), bounded digest
  batch, asset/CSS budgets, p95 render targets; **assets-fresh** drift guard.
- **Dusk + the screenshot gate** extended to react → PM → feed → digest-preview journeys; the **ACP/MCP
  admin-render mirror** (ACP v1.1) covers any new admin page.
- **"No feature is done without tests"** — enforced by the PR checklist (GOVERNANCE §3).

---

## 7. Risks & mitigations

| Risk | Mitigation |
|---|---|
| **Baseline deliverability** — digests + bounce on a no-daemon host (the #1 Phase-2 risk) | **Spike P2 first** with a hard GO + two fallbacks; per-tick volume caps; a manual-suppression floor that always works |
| **PMs as a new spam/abuse surface** | Gate through the **M1 engine** + **M3 anti-spam** (TL0 mass-PM NEVER, trust-tiered rate limits, block/ignore, report-on-PM) — never a second permission path |
| **oEmbed SSRF / privacy leak** | Server-side fetch only, **host allowlist + no private-IP + redirect guard + timeout + size cap + cache**; client never supplies embed HTML |
| **Activity-feed fan-out on baseline** (no Redis, query budgets) | Fan-out-**on-read** + caching within the query budget; cron-warm only where measured; forced-absence safe |
| **Notification/digest volume burning domain reputation** | Conservative default cadences + per-tick/per-user caps + 1-click unsubscribe + suppression list |
| **Cache-object-poisoning regressions** (the RH-9 class) on new cached data | Primitives-only in cache + rehydrate after the boundary; serializing-store cache-HIT tests on feeds + counts |
| **Phase-2 scope breadth** | Milestones land independently runnable; the §3B **Should-tier** social items (reputation/points, badges, follow/ignore, staff notes, 2nd theme) are the first descope lever — re-surfaced to you, never silent |
| **Theme-API drift** | Semver discipline; the **2nd example theme is the regression proof**; no core-edit; THEME-API contract tests stay green |
| **The Hearth → NevoBB codebase rename** (ADR-0024, ~197 refs: `config/hearth.php`, `hearth:*` commands, `HEARTH_*` env, SPDX lines) | Land it as **one reviewed change with a documented `HEARTH_*` → `NEVOBB_*` env migration, before the Public Beta gate** (so no operator contract breaks); don't multiply new `hearth:` references in Phase-2 code meanwhile — see §8 Q2 |

---

## 8. Approval — OWNER DECISIONS RECORDED (2026-06-08)

**Owner sign-off received on the three open questions:**
1. **Scope depth → engagement core first.** Build reactions, PMs, feeds, digests, polls/tags first;
   the **Should-tier** social items (reputation/points, badges/trophies, follow/ignore, staff notes,
   2nd theme) are the explicit **descope lever**. Added rule: **private-beta feedback may REORDER the
   work within the core** — the P2-M1…M5 order is not locked until beta signal arrives.
2. **NevoBB rename → deferred to the v1.0 launch gate (Phase 5), NOT Phase 2 or Public Beta.** One
   rename, at the end, after all churn settles (ROADMAP already slots it at Phase 5). During Phase 2:
   don't gratuitously multiply new `hearth:` surface area, but no rename work happens. When executed at
   the v1.0 gate it MUST carry (a) an env back-compat shim (`NEVOBB_*` → fall back to `HEARTH_*`, so the
   live host's hand-maintained `.env` never silently reverts) and (b) a name-clearance scan
   (GitHub/Packagist/domain/trademark) before the public flip. *(§7's rename row is therefore a Phase-5
   item, not a Phase-2 risk.)*
3. **Enhanced tier → fenced to Phase 4.** Phase 2 stays baseline-first; ship the degrade-gracefully
   seams only. Explicitly no Reverb live-notifications (it's a daemon — violates the baseline rule).

**Sequencing gate (owner-confirmed):** Phase-2 *feature* milestones do not begin until the private beta
is live and feedback has started to accrue (this plan's own DoD #8 premise). The **one exception is
Spike P2 (§4)** — foundational, beta-independent email infrastructure — which is **greenlit to start now,
in parallel with the private beta**, since beta stresses email immediately.

On approval I will:
1. Execute **Spike P2** (baseline deliverability) and return its **GO/NO-GO memo** before the digest/bounce
   work in P2-M2 — the rest of **P2-M1 proceeds in parallel**.
2. Proceed through **P2-M1 → P2-M5**, each landing **runnable + tested on the baseline tier**, with small
   reviewable conventional DCO commits (`Tommy Huynh <tommy@saturnhq.net>`, no AI attribution, per CLAUDE.md).
3. Keep `.env.example`, the demo seed, and the getting-started guide current at every milestone; keep the
   permission-mask + forced-absence + RH-10/RH-11 suites green.
4. Land at the **🚩 Public Beta gate** (Phase-2 core runnable + private-beta feedback folded in).

**Open questions for you (optional):**
1. **Scope depth** — proceed with the full §3B surface, or mark the **Should-tier** social items
   (reputation/points, badges/trophies, follow/ignore, staff notes, the 2nd example theme) as an explicit
   **descope lever** for a faster path to Public Beta? *(Recommendation: build the engagement core —
   reactions, PMs, feeds, digests, polls/tags — first; treat the Should-tier as the trim if timeline slips.)*
2. **The NevoBB rename (ADR-0024)** — fold the `hearth → nevobb` codebase rename into Phase 2 as its own
   reviewed change **before the Public Beta gate** (my recommendation), or run it as a separate task outside
   this plan?
3. **Enhanced-tier opportunism** — keep Phase 2 strictly baseline-first with graceful-degrade seams (the
   roadmap's position; enhanced tier = Phase 4), or opportunistically light up a *driver* path where it
   degrades cleanly (e.g. Reverb for live notifications, Meilisearch for facets)? *(Recommendation: keep
   enhanced-tier work fenced to Phase 4; ship the seams now.)*
