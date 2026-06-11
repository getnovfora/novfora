<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# Phase 2 Implementation Plan — NovFora Community (APPROVED, build source)

> **Status: APPROVED with amendments (owner, 2026-06-09).** This is the engineering companion to the
> owner-approved [product plan](phase-2-plan.md) and the build source for Claude Code. It was drafted by Code
> (plan-before-code), reviewed in Cowork against a live codebase audit, and amended per that review (§0).
> **No Phase-2 app code is written by Cowork** — Code executes this plan when session limits reset.
> **Grounds:** [phase-2-plan.md](phase-2-plan.md) · [PROJECT-STATE.md](../../PROJECT-STATE.md) ·
> [spike-p2-memo.md](spike-p2-memo.md) · [roadmap.md](roadmap.md) · live codebase audit (2026-06-09).
> **Stack (locked, ADR-0001/0002):** Laravel 13 · Livewire 4 · Alpine · Blade · PHP 8.3 · MySQL 8/MariaDB.

---

## 0. Review amendments folded in (2026-06-09)

Cowork verified Code's draft against the tree: the reconciliation and ~20 schema/seam/permission/test claims
all checked out (see §0b). Eight amendments were folded in; none is silent. Code should treat §0 as the diff
from its draft.

1. **Kickoff scope — owner override of the §8 gate (RESOLVED).** Product-plan §8 held feature milestones until
   the private beta is live, with Spike P2 the lone greenlit-now exception. The owner has **greenlit a wider
   kickoff**: **P2-M1 (full engagement core) + deliverability light-up (M2 Half-A) + multi-participant PMs
   (M2 Half-B)**, with the **Should-tier social items held as the descope lever**. This **deliberately
   supersedes the literal §8 sequencing gate** and is recorded here as an explicit owner decision (see §5).

2. **oEmbed render policy is SEPARATE from `ContentSanitizer` (correctness fix).** The draft routed cached
   provider HTML through `ContentRenderer`/`ContentSanitizer`. That allowlist forbids `<iframe>` (it is an XSS
   surface), so it would strip the very iframe that makes a video/rich embed render. Embeds get a **dedicated
   embed policy** — a fixed allowlist of embed hosts + forced `sandbox` + minimal `allow` on a single
   `<iframe>` — distinct from the post-content sanitizer, which keeps forbidding iframes. Non-allowlisted hosts
   render a **NovFora link-card facade**, never raw provider HTML. (See §2 P2-M1 · §3.)

3. **Edit-history diff is format-aware, not `body_text` (correctness fix).** `body_text` is the *tags-stripped
   search projection* — diffing it hides formatting/link/image edits, and it is not the lossless source.
   `RevisionDiffService` diffs **format-aware**: markdown posts diff `body_canonical` (readable source);
   `tiptap_json` posts diff a **normalized text/structure extraction** (line-diffing raw JSON is unreadable).
   Record the chosen extraction + any diff library in `DECISIONS.md`. (See §2 P2-M1.)

4. **Reaction score-weights are config-only and inert until reputation lands (sequencing fix).** Reactions ship
   in P2-M1 (Core); the `reputation_events` ledger that consumes their score is P2-M3 **Should-tier (held)**.
   So M1 ships **fully functional single-choice typed reactions that emit a `reaction` domain event**; the
   **score weight is config and accrues nothing until reputation is greenlit**. Reactions have **no reputation
   dependency** — if M3 stays descoped, reactions still work, no rep accrues. (See §2 P2-M1 · §2 P2-M3.)

5. **`poll_votes` UNIQUE is per-mode (schema fix).** One index can't serve both modes. Structural floor:
   **`UNIQUE(poll_option_id, user_id)`** (no duplicate vote on an option) for both modes; **single-choice
   additionally enforces one row per `(poll_id, user_id)`** via the service (and a `UNIQUE(poll_id, user_id)`
   guard where the schema allows), **multi-choice enforces the `max_choices` cap** at the app layer. Pick the
   constraint set off `polls.is_multiple`. (See §2 P2-M1.)

6. **Query-budget changes are an ADR, never a silent bump (gate rule).** The verified `≤30` thread / `≤15`
   index budgets are a hard gate. If reactions + polls + prefix + tag legitimately push the thread page past
   `≤30` even with eager-loaded count tables + RH-9 cache, the new ceiling is a **documented `DECISIONS.md`
   entry with justification** — the threshold is not quietly raised. (See §1 · §4.)

7. **Account-deletion / privacy cascade is a named cross-cutting decision (gap fix).** Phase 2 introduces the
   first co-owned PII (multi-party `messages`) plus `reactions`, `reputation_events`, `activities`. User
   deletion behaviour for these is **undefined in the draft** and must be decided **before M2 PMs land** — a
   short ADR + forced-cascade tests. (See new §6.)

8. **New `nevo:` crons grow the rename surface (noted, not changed).** `nevo:reputation:recompute` and
   `nevo:badges:recompute` correctly follow the existing `nevo:` convention — **do not pre-rename to
   `nevobb:`** (that would fragment the single Phase-5 rename, ADR-0024). Just note each adds to the ~197-ref
   rename surface swept at the v1.0 gate.

**Reaction model (Code Decision-Point 2) — CONFIRMED:** XF-style **single-choice typed reactions with score
weights** (one reaction per post per user). **Plan persistence (Code DP-3) — DONE:** this file.

---

## 0b. Reconciliation — what the codebase audit changed vs. the product plan

**A. Spike P2 is already done (GO, PR #8) — product-plan §4 "spike first" is complete.** The full deliverability
pipeline is **merged and dormant** behind `NOVFORA_DELIVERABILITY=false` (verified `config/novfora.php` →
`deliverability.enabled` defaults false): `DigestAssembler`, `DigestQueue`, `SendDigestJob`, `DigestMail`,
`Verp`, tri-path bounce (`WebhookVerifier` HMAC, `ImapBounceMailbox`, manual floor), `Suppressor` /
`SuppressionGate`, `Unsubscribe`, migrations, ACP suppressions UI, and the GO test suite. **M2's deliverability
half is a "light-up + wire-in," not a build** — the lone intentional gap is wiring `Notifier::send()` →
`DigestQueue::enqueue()` (verified: `Notifier` references `DigestQueue` zero times), plus the memo follow-ups.

**B. Seam inventory — verified pre-built vs. greenfield:**

| Pre-built / seam exists (verified) | Greenfield — no table/model yet (verified absent) |
|---|---|
| `post_revisions` (live; written by `PostService::editPost`) → only **diff viewer** remains | reactions · post_reaction_counts · polls/options/votes · prefixes · tags/taggables · conversations/conversation_user/messages · user_relationships · badges/user_badges · staff_notes · activities · post_drafts · oembed_cache |
| `notifications` + `notification_preferences` (live; `NotificationController::EVENTS = [reply, mention, moderation]`) | — |
| Deliverability schema + code (dormant) | — |
| `topics.poll_id` · `topics.prefix_id` · `topics.moved_to_topic_id` (bare nullable FK seams, no `constrained()`) | the tables they point at |
| `pm.send` in `PermissionCatalogSeeder` + **TL0 `'pm.send' => 'never'`** in `config/novfora.php` + mod/admin ALLOW in `RoleSeeder` | the PM **delivery** schema + UI |
| `users.reputation_points` column | the `reputation_events` **ledger** |
| `groups.color` (M1 seam) + `groups.description` (ACP v2) → community-feel "Part B" largely done | forum stats / view-count / online heuristic |

**C. Owner decisions (binding):** engagement core first; **Should-tier = explicit descope lever** (reputation/
points, badges, follow/ignore-follow-half, staff notes, 2nd theme); **rename → Phase 5** (no rename now; don't
multiply `nevo:` surface gratuitously); **enhanced tier → Phase 4** (baseline-first, seams only, no Reverb/
Meilisearch light-up); kickoff scope per §0.1 / §5.

---

## 1. Engineering contract — every feature passes through these layers

Per-feature checklist the milestones assume (verified seams in **bold**):

1. **Permission key** → add to **`PermissionCatalogSeeder::catalog()`**; grant in **`RoleSeeder`** presets; if
   TL-gated, add to **`config/novfora.php → antispam.trust_gates`** (`never` = hard spam gate an admin ALLOW
   can't lift; `no` = admin-liftable) and re-run **`TrustGateSeeder`**. **No second permission system** —
   authorize only via **`$user->canDo('key', $scope)`** (the **`Gate::before`** hook in `AppServiceProvider`
   routes `Scope`-typed args to **`PermissionResolver`** over **`acl_entries`**). Every new key's NEVER/
   trust-gate reasoning is an **Opus `xhigh`** decision even when the surrounding CRUD is Sonnet.
2. **Content reuse** → any rich text (PM body, poll option, profile text) renders **only** through
   **`ContentRenderer::render()`** (wraps `CanonicalRenderer` + `ContentSanitizer`, symfony/html-sanitizer).
   Never a second sanitize path. Free-text surfaces also pass **`ContentModerator::review()`**.
   **Exception (amendment #2):** oEmbed provider HTML does **not** use the post `ContentSanitizer` — it uses the
   dedicated embed policy (§3). Canonical store keeps **URL only**.
3. **SFC pattern** → `resources/views/components/<area>/⚡<name>.blade.php`, anon `class extends Component`,
   `#[Locked]` on injected IDs, **auth asserted in `mount()` AND every action**, arg-first/service-second
   injection, `<x-ui.*>` controls, `dusk="…"` hooks. (Convention verified: `⚡create-topic`, `⚡audit-log`.)
4. **RH-9 cache discipline** → every new cached surface (reaction counts, poll counts, feed pages) caches
   **primitives only + rehydrate after the boundary**, with a serializing-store **cache-HIT test** asserting
   zero re-query.
5. **Query budgets are a hard gate (amendment #6)** → new list pages stay within **`QueryBudgetTest`**
   (`≤30` thread / `≤15` index, verified `tests/Feature/Performance/QueryBudgetTest.php`). Any increase is a
   justified `DECISIONS.md` entry, never a silent threshold bump.
6. **Tests (non-negotiable)** → extend `PermissionMaskTest` for new keys; `QueryBudgetTest` for new lists;
   forced-absence for any service-touching surface; `AdminAccessWalkTest` auto-covers new parameterless
   `/admin/*` routes; Dusk journey + screenshot for user-visible flows; **forced-deletion cascade tests** for
   new PII tables (§6).

---

## 2. Milestone breakdown

Legend — **Tier:** `Core` (committed) · `Should` (descope lever, **held** per §5). **Model:** ⚙ = Opus `xhigh`
(security/permission/concurrency) · ◻ = Sonnet (scaffold/CRUD/view) · ◐ = mixed.

### P2-M1 — Engagement & content depth (parallel-safe)

| Feature | Tier | New schema | Key services / perms | UI (SFC) | Primary tests | Model |
|---|---|---|---|---|---|---|
| **Reactions** (single-choice typed + score) | Core | `reactions`(post_id,user_id,type · **UNIQUE(post_id,user_id)**); `post_reaction_counts`(post_id,type,count); config typed set + score weights | `ReactionService` + model-event counters; `ReactionRateLimiter` (per-TL); perm `react.create`; **emits `reaction` domain event** (Notifier). **Score weight is config-only, inert until M3 reputation (amendment #4)** | `⚡post-reactions` in post partial | mask · toggle/change/unreact integrity · **cache-HIT (RH-9)** · query-budget · rate-limit · Dusk | ◐ (counters/cache ⚙, UI ◻) |
| **Drafts / autosave** | Core | `post_drafts`(user_id,context_type,context_id,body_canonical · UNIQUE) own-only | editor island: **debounce only the network `$wire.saveDraft`** (Spike-0 #3), keep immediate deferred sync, `@persist` for `wire:navigate`; editor stays **closure-local** (Spike #1 — never a reactive Alpine prop) | extend `content-editor` | own-only authz · save/restore/discard-on-publish · Dusk type→navigate→restore | ◐ (own-only authz ⚙, island ◻) |
| **Edit-history diff viewer** (over live `post_revisions`) | Core | — | **`RevisionDiffService` — format-aware (amendment #3):** markdown → diff `body_canonical`; tiptap_json → diff a normalized text/structure extraction (NOT `body_text`); render via `ContentRenderer`. perm `post.history.view` (author+staff). Record extraction + any lib in `DECISIONS.md` | `⚡post-history` modal | visibility (author/staff yes, others no) · diff correctness incl. formatting-only edit | ◻ (+ ⚙ on the diff-source/perm decision) |
| **oEmbed** (SSRF-safe) | Core | `oembed_cache`(url_hash UNIQUE, html, provider, expires_at) | **`SsrfGuard`** (DNS-resolve, block RFC-1918/5156/loopback/link-local, re-validate post-redirect, redirect cap, timeout, size cap, https-only); `OEmbedService` (host allowlist → server fetch → **dedicated embed policy, NOT post ContentSanitizer (amendment #2)** → cache); **non-allowlisted host → NovFora link-card facade**; canonical stores **URL only** | embed node in editor | **SSRF battery** (IP-literal, DNS→private, redirect-to-internal, oversize, timeout, non-allowlist) · **embed-policy test (iframe sandbox/allowlist; post-sanitizer still strips iframes)** · forced-absence | ⚙ **(all)** |
| **Polls UI** (over `topics.poll_id` seam) | Core | `polls`(topic_id,question,is_multiple,max_choices,closes_at,is_closed); `poll_options`(poll_id,label,position,vote_count); `poll_votes` — **per-mode UNIQUE (amendment #5):** `UNIQUE(poll_option_id,user_id)` floor + single-choice one-per-`(poll_id,user_id)` + multi `max_choices` app cap | `PollService` + event counters; perms `poll.create`(TL-gated), `poll.vote`; option text via sanitizer/moderator | `⚡poll` + poll block in `⚡create-topic` | vote integrity (single/multi/closed) · perm · **cache-HIT** · budget | ◐ (vote integrity/gate ⚙, UI ◻) |
| **Topic prefixes UI** (over `topics.prefix_id` seam) | Core | `prefixes`(forum_id nullable,label,color_token,position) | ACP CRUD; perm `prefix.manage`; filtered listing | `⚡prefixes` (mirror `⚡groups`) + selector | ACP render-mirror (auto) · filtered-listing budget · CRUD | ◻ |
| **Tags UI** | Core | `tags`(name,slug UNIQUE,usage_count); `taggables`(poly, UNIQUE) | `TagService` (usage_count via events); perms `tag.create`(**TL-gated, anti-spam**), `tag.apply`; names sanitized | tag input (autocomplete) + tag listing page | apply/filter · `tag.create` TL gate · budget | ◐ (TL gate ⚙, UI ◻) |

**PR slices:** one per feature (7), each independently runnable + tested. oEmbed is its own ⚙ PR.
**DoD:** all 7 green on baseline; thread page with reactions/polls stays `≤30` (or an ADR per #6); SSRF battery
+ embed-policy + cache-HIT tests in the permanent suite.

### P2-M2 — Messaging & notifications depth (consumes the done spike) — **in kickoff scope (PMs included)**

**Half A — light up deliverability + notifications:**

| Work | Tier | Detail | Model |
|---|---|---|---|
| **Activate pipeline** | Core | `NOVFORA_DELIVERABILITY=true` / `NOVFORA_DIGEST=true` in `.env.example` (graceful absence already handled → VERP/manual floor); document SPF/DKIM/DMARC + on-domain `From` (memo §5) | ◻ |
| **Wire `Notifier` → `DigestQueue`** | Core | `Notifier::send()` calls `DigestQueue::enqueue()` first for batched cadence (returns null for immediate/off → live path untouched); idempotency lives in the DB UNIQUE row, not the lock (memo §4) | ⚙ |
| **Dedupe suppression** | Core | `Notifier::suppressed()` delegates to the shared `SuppressionGate` (memo §4 follow-up) | ⚙ |
| **New event types** | Core | `reaction`, `pm.received`, `follow` added to `NotificationController::EVENTS` + Notifier + display component + prefs rows | ◻ |
| **Prefs UI** | Core | `⚡notification-preferences` (per-event×channel) + digest cadence picker (`DigestPreference`) | ◻ |
| **Memo follow-ups** | Core | unsubscribe **GET-confirm / POST-apply split** (scanner-prefetch safety ⚙); **SES + Mailgun parsers** in `ProviderWebhookParser` (currently Postmark + generic only — untrusted bytes ⚙); non-VERP **manual-review queue** | ◐ |

**Half B — multi-participant PMs:**

| Feature | Tier | New schema | Key services / perms | UI | Tests | Model |
|---|---|---|---|---|---|---|
| **Conversations / PMs** | Core | `conversations`; `conversation_user`(last_read_at,left_at,can_invite · UNIQUE); `messages`(body_format,body_canonical → **ContentRenderer reuse**); `user_relationships`(type follow\|ignore · UNIQUE) — **ignore half built here (Core)**, follow half is M3 | `pm.send` (exists — implement; **pin TL0 mass-PM NEVER, admin-can't-lift**); `PmRateLimiter` (per-TL); recipient **ignore** check; mass-PM recipient cap; report-on-PM (`Report` poly); `ConversationPolicy` (participant-only). **Deletion cascade per §6** | `⚡conversation-list`, `⚡conversation`, `⚡new-conversation` (editor reuse) | mask **TL0 NEVER pinned** · PM body XSS sanitized · rate/ignore/report · inbox `≤15` / convo `≤30` · forced-absence (mail down → DB notif) · **deletion-cascade** · Dusk | ⚙ (gating/NEVER pin/deletion ⚙) ◻ (UI) |

**DoD:** digest idempotency + bounce + forced-absence suites stay green and extend to the wiring; PM anti-spam
regression (TL0 NEVER pinned) green; deletion-cascade tests green; no second render/permission path.

### P2-M3 — Social, reputation & profiles (**Should-tier mostly HELD per §5**)

| Feature | Tier | New schema | Notes | Model |
|---|---|---|---|---|
| **Activity feed (baseline)** | **Core** | `activities`(actor_id,verb,subject poly,scope_forum_id,created_at) append-only via model events | **fan-out-on-read**, per-viewer **permission-filtered** via resolver (like M4 search), cached primitives+rehydrate, within budget; works without follow. **Deletion cascade per §6** | ⚙ (perm-filter/cache) ◻ (view) |
| **Follow** (follow half of `user_relationships`) | **Held** | follow half (table from M2) | drives feed inclusion + notif routing | ◻ |
| **Reputation / points** | **Held** | `reputation_events`(source poly, points · **UNIQUE source for idempotency**); `users.reputation_points` = denorm sum | `ReputationService` (idempotent award — **reactions' score wires here, amendment #4**); `nevo:reputation:recompute` cron (withoutOverlapping + short mutex). **Adds to rename surface (#8)** | ⚙ (idempotent award) |
| **Badges / trophies** | **Held** | `badges`(slug,criteria JSON); `user_badges`(UNIQUE) | criteria engine (events → idempotent awards; `nevo:badges:recompute` cron). **Adds to rename surface (#8)** | ⚙ (idempotency) ◻ (CRUD) |
| **Community-feel pack** | Core | — | **mostly pre-done** (group colours = ACP v2); remaining = forum stats, **view-count increment** (throttle per session, no write-storm), online heuristic via `last_seen` | ◻ |

### P2-M4 — Moderation depth & discovery

| Feature | Tier | New schema | Notes | Model |
|---|---|---|---|---|
| **Cross-page bulk select** | Core | — | XF-style select-across-pages; per-item rank guard + permission on apply | ◐ |
| **Merge / split topics** | Core | uses `topics.moved_to_topic_id` | `MergeTopicsService`/`SplitTopicService`: transactional, **authoritative counter recompute** (reply_count, forum topic/post counts, last-post pointers) + audit, never silent (mirrors ACP delete-safety) | ⚙ **(concurrency/counters)** |
| **Staff notes** | **Held** | `staff_notes`(user_id subject,author_id,body) | staff-only, audited; perms `staff.notes.view/create` | ◻ |
| **Search filters / facets** | Core | — | author/forum/date/tag/type over **Scout DB driver**; **degrade to direct DB when Meilisearch absent** (forced-absence contract) | ◐ |
| **Consolidated user-preferences** | Core | — | `⚡user-preferences` folds Appearance + notification + new prefs | ◻ |

### P2-M5 — Theme proof, beta polish, runnable milestone → 🚩 Public Beta

- **2nd example/child theme** under `themes/<slug>/` (`theme.json api_version ^1.0` + real `views/` overrides +
  prebuilt assets) — exercises the semver'd override layer end-to-end; extend `ThemeOverrideTest`. *(Held — Should-tier.)* ◻
- **Fold private-beta feedback** (may reorder earlier work per product-plan §8).
- **Refresh** `DemoSeeder` (reactions/PMs/feeds/polls visible), `getting-started.md`, `.env.example`. ◻
- **Re-run regression vs. Phase-2 migrations:** perf/asset/query budgets · forced-absence suite ·
  **RH-10 auto-upgrade + RH-11 restore rehearsal** · permission-mask truth-tables · **deletion-cascade**. ⚙

---

## 3. Security-critical (Opus `xhigh`) inventory

oEmbed `SsrfGuard`/fetch **+ the dedicated embed policy (allowlisted hosts + sandboxed iframe, separate from
the post `ContentSanitizer`, amendment #2)** · PM anti-spam gating + **TL0 mass-PM NEVER pin** · digest
`Notifier→DigestQueue` idempotent wiring + `SuppressionGate` dedupe · SES/Mailgun webhook parsers (untrusted
bytes) + unsubscribe GET/POST split · **merge/split counter recompute** · reputation/badge **idempotent award**
(held) · every new permission key's NEVER/trust-gate reasoning · **account-deletion cascade for co-owned PII
(§6)** · RH-9 cache-boundary design for reaction/poll/feed counts. Everything else (migrations, models,
factories, SFC scaffolding, ACP managers, prefs pages, 2nd theme, seed/env/docs) is **Sonnet**.

---

## 4. Testing & gates (carry-forward + new)

Non-negotiables stay green and **extend**: permission-mask truth-tables (+ new keys, **TL0-NEVER-pinned for
PM**); service-tier forced-absence (+ mail provider absent, bounce webhook absent, Meilisearch-absent
facets→DB). New permanent batteries: **oEmbed SSRF**, **embed-render policy** (iframe sandbox/allowlist; assert
the post sanitizer still strips iframes), **deliverability** (already in), **RH-9 cache-HIT** on feeds +
reaction/poll counts, **account-deletion cascade** (§6). **Query-budget gate (amendment #6):** `≤30`/`≤15`
hold; any increase is a `DECISIONS.md` entry. CI gates unchanged: `pint --test` · phpstan L5 ·
`composer`/`npm audit` · Pest · **Dusk + screenshot gate** (extend to react→PM→feed→digest-preview) ·
assets-fresh drift + size budgets · query budgets · admin-render mirror.

---

## 5. Sequencing & kickoff (OWNER DECISION, 2026-06-09)

**Kickoff scope (greenlit):** **P2-M1 (full engagement core) + deliverability light-up (M2 Half-A) +
multi-participant PMs (M2 Half-B)**, built by **Code (Opus)** when session limits reset. **Should-tier social
items are HELD as the descope lever:** follow (follow-half), reputation/points, badges/trophies, staff notes,
2nd example theme. This **supersedes the literal product-plan §8 sequencing gate** (which held feature work
until private-beta-live) — a deliberate, recorded owner override, not silent.

**Build order (low-coupling first; each lands independently runnable + tested on baseline):**
1. **Deliverability light-up (M2 Half-A)** — code already merged; smallest, highest beta value (beta stresses
   email immediately). Wire `Notifier→DigestQueue`, `SuppressionGate` dedupe, new event types, prefs UI, memo
   follow-ups.
2. **P2-M1 content depth** — 7 independent PR slices in parallel; oEmbed is its own ⚙ PR.
3. **PMs (M2 Half-B)** — its own ⚙ track; build `user_relationships` (ignore half) here once. **Decide §6
   account-deletion behaviour before this lands.**

Small reviewable DCO conventional commits (`Tommy Huynh <tommy@saturnhq.net>`, no AI attribution). Note: with
reputation held, **reaction score-weights stay config-only/inert** until reputation is greenlit (amendment #4).

**Implementation-level risks (beyond product-plan §7):** (a) editor autosave must stay closure-local (Spike #1)
— never a reactive Alpine prop, or ProseMirror identity breaks; (b) reaction/poll counts on the thread hot path
stay within `≤30` via eager-loaded count tables + RH-9 cache (else ADR per #6); (c) `user_relationships` is a
**cross-milestone seam** — ignore in M2, follow in M3 — built once in M2; (d) **oEmbed embed-policy vs.
post-sanitizer split** must be explicit so iframes render *only* through the embed allowlist, never the post
path; (e) **edit-history diff source is format-aware** (#3), recorded in `DECISIONS.md`.

---

## 6. Cross-cutting — account deletion & privacy (decide before M2 PMs; short ADR)

Phase 2 introduces the first **co-owned PII** (multi-party `messages`) plus `reactions`, `reputation_events`,
`activities`. User-deletion behaviour must be defined before PMs land, with explicit FK on-delete semantics in
the (reversible) migrations and **forced-cascade tests**. Recommended defaults (owner/Opus to confirm via ADR):

- **conversations / messages:** on participant deletion, **anonymize** their authored messages (retain body so
  the thread stays coherent for remaining participants) and drop them from `conversation_user`; the
  conversation persists while ≥1 participant remains, else it is purged. (Avoids destroying other users' record
  of the exchange while honouring the deletion.)
- **reactions:** hard-delete the user's rows and **decrement `post_reaction_counts`**.
- **reputation_events / reputation_points (held):** remove the user's events; the denorm sum recomputes to 0.
- **activities:** hard-delete the actor's rows (and any feed cache rehydrates without them).
- **staff_notes (held):** notes are *about* a subject user — purge on subject deletion; retain author identity.

This is a privacy boundary → **Opus `xhigh`**, recorded in `DECISIONS.md` as an ADR before the PM migration
merges.

---

## 7. Open items / decision log

- **RESOLVED (owner, 2026-06-09):** kickoff scope = M1 + deliverability + PMs; Should-tier held (§5) —
  supersedes product-plan §8 gate.
- **RESOLVED:** reaction model = single-choice typed + score weights (score inert until reputation, #4).
- **TO DECIDE before M2 PMs (Opus ADR):** account-deletion/privacy cascade (§6).
- **TO RECORD in `DECISIONS.md` during M1:** edit-history diff source/extraction (#3); any diff library;
  oEmbed embed-host allowlist + sandbox policy (#2); any query-budget ceiling change (#6).
- **Phase-5 (not now):** `nevo → nevobb` rename sweep (ADR-0024) — new `nevo:` crons add to its surface (#8).
