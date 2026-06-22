<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# P2-M1 — Engagement & content depth — Claude Code kickoff

> Paste the block below into the **Claude Code** session to begin **Phase 2 · P2-M1**. The Phase-2 plan is
> **owner-approved (2026-06-09)**; build source is
> [phase-2-implementation-plan.md](../phase-2-implementation-plan.md) (8 review amendments folded in — its §0 is
> the diff from Code's draft). P2-M1 is the **engagement & content-depth** milestone: 7 independent, beta-
> independent, parallel-safe PR slices on the proven Phase-1 engine. **oEmbed is the one security-critical
> slice (Opus `xhigh`).** Authoritative specs: impl-plan §0 (amendments), §1 (engineering contract), §2
> (P2-M1 table), §3 (security inventory); ADR-0006 (permission engine); the verified seams below.
>
> **Build order across the greenlit scope:** P2-M1 content (this packet) and the deliverability light-up
> (M2 Half-A) are parallel-safe and either can go first; **PMs (M2 Half-B) come after, and are blocked on the
> §6 account-deletion ADR.** Should-tier social is **held**. Separate kickoff packets follow (see §After).

---

```
Begin Phase 2 — P2-M1 (Engagement & content depth). The Phase-2 plan is owner-approved; build source is
docs/product/phase-2-implementation-plan.md. This milestone is 7 independent content-depth slices on the
live Phase-1 engine — low-coupling, beta-independent. Get oEmbed right over getting it fast; the rest mirror
existing patterns. Run in a PHP-capable env if available (so Pest/Pint/Larastan self-verify before push).

START OF SESSION: read PROJECT-STATE.md, CLAUDE.md, docs/PROJECT-BRIEF.md (standing rule). Then the spec:
docs/product/phase-2-implementation-plan.md — §0 (the 8 amendments; these OVERRIDE the draft), §1 (the
per-feature engineering contract), §2 P2-M1 (the feature table), §3 (security inventory). Re-read the
permission engine (ADR-0006; docs/architecture/security-and-permissions.md §1) and the content pipeline
(app/Content/ContentRenderer.php → CanonicalRenderer + ContentSanitizer) before touching gated/rich-text
surfaces. Branch from main (post-ACP-v2). Commit identity per CLAUDE.md (Tommy Huynh <tommy@saturnhq.net>,
DCO -s, no AI attribution). Suite green before starting.

VERIFIED SEAMS YOU BUILD ON (do not re-create):
  • topics.poll_id, topics.prefix_id (bare nullable FK seams — the target tables are yours to add).
  • post_revisions table is live, written by PostService::editPost (diff VIEWER only remains).
  • PermissionCatalogSeeder::catalog(), RoleSeeder presets, TrustGateSeeder + config/novfora.php
    antispam.trust_gates (never|no|allow), $user->canDo('key', $scope) via Gate::before → PermissionResolver
    over acl_entries. NO second permission system.
  • ContentRenderer::render() is the SINGLE rich-text path; ContentModerator::review() for free text.
  • SFC convention: resources/views/components/<area>/⚡<name>.blade.php, anon class extends Component,
    #[Locked] on injected IDs, auth asserted in mount() AND every action, <x-ui.*> controls, dusk= hooks.
  • Budgets: QueryBudgetTest ≤30 thread / ≤15 index are a HARD GATE.

MODEL/EFFORT (CLAUDE.md routing):
  • Opus 4.8 xhigh — THINK HARD: oEmbed (SsrfGuard + the embed-render policy), reaction/poll COUNTERS +
    RH-9 cache boundary, poll VOTE INTEGRITY, and every new permission key's NEVER/trust-gate reasoning
    (react.create, poll.create [TL-gated], poll.vote, prefix.manage, tag.create [TL-gated], tag.apply,
    post.history.view).
  • Sonnet 4.6 — the CRUD/SFC/view bulk once each design is settled: prefixes ACP (mirror ⚡groups), tags UI,
    drafts island, the diff-viewer modal, the reactions UI partial.

Open each slice with a SHORT plan (schema + perms + the test matrix), then build — the plan is approved, no
wait. First: `git status` shows three uncommitted Cowork planning docs — phase-2-implementation-plan.md,
p2-m1-code-kickoff.md, and the PROJECT-STATE.md update; commit all three together (docs:) then build.

BUILD P2-M1 — 7 INDEPENDENT PR SLICES (each lands runnable + tested on baseline; oEmbed is its own ⚙ PR):

1) REACTIONS (single-choice typed + score — Opus on counters/cache, Sonnet on UI):
   • Schema: reactions(post_id, user_id, type, UNIQUE(post_id,user_id)); post_reaction_counts(post_id,type,
     count). Typed set + score weights in config.
   • ReactionService + model-event counters; ReactionRateLimiter (per-TL); perm react.create; emits a
     `reaction` domain event (consumed later by M2 notifications and — if greenlit — M3 reputation).
   • AMENDMENT #4: the SCORE WEIGHT is CONFIG-ONLY and INERT this milestone — reputation (M3) is HELD, so
     reactions accrue no rep. Reactions must be fully functional with NO reputation dependency.
   • ⚡post-reactions in the post partial. Tests: mask · toggle/change/unreact integrity · RH-9 cache-HIT
     (primitives-only + rehydrate, serializing-store zero-requery) · query-budget · rate-limit · Dusk.

2) DRAFTS / AUTOSAVE (own-only authz ⚙, island ◻):
   • Schema: post_drafts(user_id, context_type, context_id, body_canonical, UNIQUE) — own-only.
   • Editor island: debounce ONLY the network $wire.saveDraft (Spike-0 #3 — JS sync stays immediate);
     @persist for wire:navigate cursor restore; the editor stays CLOSURE-LOCAL (Spike #1 — NEVER a reactive
     Alpine prop, or ProseMirror identity breaks). Extend the existing content-editor.
   • Tests: own-only authz · save/restore/discard-on-publish · Dusk type→navigate→restore.

3) EDIT-HISTORY DIFF VIEWER (over live post_revisions):
   • RevisionDiffService is FORMAT-AWARE (AMENDMENT #3): markdown posts diff body_canonical (readable source);
     tiptap_json posts diff a NORMALIZED text/structure extraction — NOT body_text (that is the tags-stripped
     SEARCH PROJECTION and hides formatting/link/image edits). Render via ContentRenderer.
   • perm post.history.view (author + staff only). RECORD the extraction approach + any diff lib in
     DECISIONS.md (Apache/MIT/BSD only). ⚡post-history modal.
   • Tests: visibility (author/staff yes, others no) · diff correctness INCLUDING a formatting-only edit.

4) oEMBED (SSRF-safe — ⚙ ENTIRE SLICE, its own PR):
   • Schema: oembed_cache(url_hash UNIQUE, html, provider, expires_at). Canonical stores URL ONLY (client
     never supplies embed HTML).
   • SsrfGuard: DNS-resolve + block RFC-1918/5156/loopback/link-local, RE-VALIDATE after every redirect
     (anti-rebind), redirect cap, timeout, response size cap, https-only.
   • OEmbedService: host allowlist → server fetch → AMENDMENT #2: the provider HTML goes through a DEDICATED
     EMBED POLICY (a fixed allowlist of embed hosts + a single <iframe> with forced sandbox + minimal allow)
     — NOT the post ContentSanitizer (that allowlist forbids iframes and would strip the embed). A
     non-allowlisted host renders a NovFora LINK-CARD FACADE, never raw provider HTML. Cache the result.
   • Record the embed-host allowlist + sandbox policy in DECISIONS.md.
   • Tests (SSRF battery, permanent): IP-literal, DNS→private, redirect-to-internal, oversize, timeout,
     non-allowlist → facade; PLUS an embed-policy test asserting the sandboxed iframe renders AND that the
     post ContentSanitizer still strips a raw iframe; forced-absence.

5) POLLS UI (over topics.poll_id seam — vote integrity ⚙, UI ◻):
   • Schema: polls(topic_id, question, is_multiple, max_choices, closes_at, is_closed);
     poll_options(poll_id, label, position, vote_count); poll_votes with PER-MODE UNIQUE (AMENDMENT #5):
     UNIQUE(poll_option_id, user_id) floor for both modes; single-choice ALSO enforces one row per
     (poll_id, user_id); multi-choice enforces max_choices at the app layer. Pick the constraint set off
     polls.is_multiple.
   • PollService + event counters; perms poll.create (TL-gated), poll.vote; option text via sanitizer +
     ContentModerator. ⚡poll + a poll block in ⚡create-topic.
   • Tests: vote integrity (single / multi / closed) · perm · RH-9 cache-HIT · budget.

6) TOPIC PREFIXES UI (over topics.prefix_id seam — ◻, mirror ⚡groups):
   • Schema: prefixes(forum_id nullable, label, color_token, position). ACP CRUD; perm prefix.manage;
     prefix-filtered topic listing. ⚡prefixes + a selector in create-topic.
   • Colours are TOKENS, AA both light/dark (reuse the ACP-v2 group-colour discipline). Tests: ACP
     render-mirror (auto) · filtered-listing budget · CRUD.

7) TAGS UI (◐ — TL gate ⚙, UI ◻):
   • Schema: tags(name, slug UNIQUE, usage_count); taggables(polymorphic, UNIQUE). TagService (usage_count
     via events); perms tag.create (TL-GATED, anti-spam) + tag.apply; tag names sanitized. Tag input
     (autocomplete) + a tag listing page. Tests: apply/filter · tag.create TL gate · budget.

ENGINEERING CONTRACT (apply per slice, impl-plan §1): new perm key → PermissionCatalogSeeder + RoleSeeder
(+ trust_gates + TrustGateSeeder if TL-gated) → authorize ONLY via $user->canDo(); rich text ONLY through
ContentRenderer (EXCEPT oEmbed's embed policy) + ContentModerator on free text; ⚡-SFC pattern; RH-9 cache
(primitives-only + rehydrate) with a serializing-store cache-HIT test on every new cached count; query
budgets are a HARD GATE — if reactions+polls+prefix+tag legitimately push the thread page past ≤30 even with
eager-loaded count tables + cache, that ceiling is a JUSTIFIED DECISIONS.md entry, NEVER a silent bump.

DEFINITION OF DONE (binding): all 7 slices green on the baseline tier (PHP 8.3 + MySQL + cron); PermissionMaskTest
EXTENDED for every new key; the oEmbed SSRF battery + embed-policy test + the RH-9 cache-HIT tests in the
permanent suite; query budgets ≤30/≤15 hold (or an ADR); forced-absence stays green; Dusk + the screenshot
gate extended to react/poll/prefix/tag journeys; ACP admin-render mirror covers the prefixes page; Pint /
Larastan L5 / composer+npm audit / assets-fresh all green; CSS budget reported; demo seed + .env.example
current; PROJECT-STATE updated; DECISIONS.md carries the diff-source, oEmbed allowlist+sandbox, and any
budget-ceiling entries. Small conventional DCO commits, one logical change each.

SCOPE FENCE — build ONLY these 7 P2-M1 slices. NOT in this packet: the deliverability light-up (M2 Half-A —
its own packet, parallel-safe); PMs / conversations (M2 Half-B — its own packet, and BLOCKED on the §6
account-deletion ADR); reputation/points, badges, follow (follow-half), staff notes, the 2nd theme
(Should-tier — HELD per the owner sequencing decision); merge/split + search facets + bulk-select (M4).
Reaction score-weights stay config-only/inert (reputation held). Reversible, non-destructive migrations.
Strict clean-room — study reference-forum semantics, reimplement independently; copy no code/markup/themes.
If a feature needs a mechanism that doesn't exist in the engine, DO NOT invent a parallel one — flag it.
When P2-M1 lands runnable + tested, report back here.
```

---

## When P2-M1 reports back

The Cowork session reviews P2-M1 — especially: (1) does the **oEmbed SSRF battery** actually cover DNS-rebind
(post-redirect re-validation) and does the **embed-policy test** prove the sandboxed iframe renders *while* the
post `ContentSanitizer` still strips a raw iframe (amendment #2); (2) is every new cached count **provably
non-load-bearing** for correctness (RH-9 cache-HIT); (3) does the **diff viewer** show a formatting-only edit
(amendment #3); (4) did the thread page stay within `≤30` or arrive with a justified ADR (amendment #6). Then
updates PROJECT-STATE and confirms the `DECISIONS.md` entries landed.

## After this

- **Deliverability light-up (M2 Half-A)** — its own kickoff packet; the pipeline is merged + dormant, so this
  is wire-in not build: `Notifier→DigestQueue`, `SuppressionGate` dedupe, new event types
  (`reaction`/`pm.received`/`follow`), prefs UI, and the memo follow-ups (unsubscribe GET/POST split,
  SES+Mailgun parsers, manual-review queue). Parallel-safe with P2-M1.
- **PMs (M2 Half-B)** — its own kickoff packet, **gated on the §6 account-deletion / privacy-cascade ADR**
  (decide co-owned-`messages` deletion behaviour before the PM migration merges). Builds `user_relationships`
  (ignore half) once, for the follow half to reuse in M3.
- **Should-tier social** (follow, reputation, badges, staff notes, 2nd theme) stays **held** as the descope
  lever until you release it.

Say the word and I'll prep the **deliverability light-up** and **PMs** packets next.
