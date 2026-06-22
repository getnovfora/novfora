<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# P2-M5 — Beta polish, full regression & the social pack (follow + reputation + badges) — Claude Code kickoff

> Paste the block below into **Claude Code** to build **Phase 2 · M5** — the Phase-2 closer that ships the
> 🚩 **Public Beta**. Owner decision (2026-06-12): the **full social pack is pulled out of HELD into M5 Core** —
> **follow + reputation/points + badges**. Staff notes and a reputation leaderboard stay deferred.
>
> **The correctness-critical core (Fable @ max — get these right; the rest is Sonnet scaffolding):**
> 1. **Idempotent reputation award** — a double-fired event must never double-count; `users.reputation_points`
>    is a denormalised sum that must always reconcile to the `reputation_events` ledger.
> 2. **The EXTENDED ADR-0025 deletion cascade** — the subtle one. Deleting a user already hard-deletes *their*
>    reactions; those reactions **awarded reputation to OTHER authors**. The cascade must revoke that rep and
>    **recompute the affected authors'** `reputation_points` authoritatively — mirroring the
>    `post_reaction_counts` recompute the service already does. Plus remove the user's own
>    `reputation_events` / `user_badges` / `user_relationships` rows.
> 3. **Idempotent badge award** — criteria → `insertOrIgnore` on `UNIQUE(user_id, badge_id)`; the recompute
>    cron must be safe to run repeatedly and under `withoutOverlapping`.
> 4. **`follow.create` trust-gate / anti-spam** — mass-follow is a notification-spam vector; gate + rate-limit
>    it; self-follow is a hard NEVER.
>
> Authoritative specs: [phase-2-implementation-plan.md](../phase-2-implementation-plan.md) §2 (P2-M3 Held rows:
> Follow / Reputation / Badges) + §1 (engineering contract) + §3 (security inventory) + §6 (deletion cascade);
> ADR-0025 (cascade — must be honoured + extended); ADR-0006 (permission engine); ADR-0007 (anti-spam / trust
> gates). Format + the reusable feed seams: [p2-m3-activity-code-kickoff.md](p2-m3-activity-code-kickoff.md).

---

```
Begin Phase 2 — M5. Scope = the social pack pulled from HELD (FOLLOW + REPUTATION/POINTS + BADGES) +
beta polish + a FULL Phase-2 regression → tag the Public Beta. Mode: ultracode (start Fable@max,
downgrade as it deems fit). Fable@max apex on the four correctness pieces below; Sonnet on
migrations/models/factories, ACP CRUD, profile displays, the follow UI, DemoSeeder/docs/.env, and the
regression scaffolding. Staff notes + reputation leaderboard stay HELD.

START OF SESSION: read PROJECT-STATE.md, CLAUDE.md, docs/product/phase-2-implementation-plan.md
§2 (P2-M3 Held rows) + §1 (engineering contract) + §3 + §6, and DECISIONS.md ADR-0025/0006/0007. Then read:
  • app/Account/AccountDeletionService.php — the ONE-transaction cascade; note the existing
    ReactionService::recomputeForPosts / PollService::recomputeForOptions authoritative-recount seams.
    You will EXTEND this service (this is the highest-risk change in M5).
  • app/Forum/ReactionService.php — the Reacted event emit + the per-type config score weights
    (currently inert per amendment #4); lines ~183-222 = the RH-9 cache-primitive pattern.
  • config/novfora.php — reaction score weights; antispam.trust_gates (follow.create goes here).
  • database/seeders/{PermissionCatalogSeeder,RoleSeeder,TrustGateSeeder}.php — add the new perm keys.
  • app/Permissions/VisibleForumIds.php + the M3 ⚡activity-feed component — REUSE for the following-feed
    (do NOT bypass the permission filter).
  • app/Notifications (Notifier) + NotificationController::EVENTS — the 'follow' event vocab, renderers
    and prefs rows already exist from M2 Half-A; you wire the REAL emitter (no fake emitters were added).
  • app/Models/User.php — users.reputation_points column EXISTS (denorm sum); user_relationships table
    EXISTS with the follow type column (M2 Half-B) but the follow half is UNWIRED.
Branch claude/p2-m5-beta-social off main. Suite green before starting. Commit identity per CLAUDE.md
(Tommy Huynh <tommy@saturnhq.net>, DCO -s, no AI attribution). Small conventional commits, one slice per PR.

MODEL/EFFORT (CLAUDE.md routing — ultracode default):
  • Fable@max (apex) — THINK HARD: (a) ReputationService idempotent award/revoke + the recompute cron;
    (b) the EXTENDED AccountDeletionService cascade — revoke rep sourced from the deleted user's reactions
    and recompute the affected third-party authors (privacy + integrity boundary); (c) BadgeService criteria
    idempotent award + cron; (d) follow.create trust-gate / mass-follow anti-spam + self-follow NEVER.
  • Sonnet 4.6 — reputation_events/badges/user_badges migrations + models + factories; FollowService + the
    profile follow button/counts; ACP ⚡badges CRUD; profile rep/badge displays; DemoSeeder + getting-started
    + .env.example refresh; the regression rehearsal scaffolding.

KEY CODEBASE FACTS (verified — reuse, do NOT rebuild):
  • users.reputation_points column EXISTS (denorm). The reputation_events LEDGER is greenfield.
  • user_relationships EXISTS (M2 Half-B): type column (follow|ignore), ignore half wired, follow half NOT.
  • Reactions + per-type config SCORE WEIGHTS exist (M1) but are INERT (amendment #4) — this is where they
    light up. The Reacted domain event + an unreact path already exist.
  • Notifier 'follow' event vocab + mail/in-app/digest renderers + prefs rows exist (M2-A). Wire the emitter.
  • ActivityFeed + VisibleForumIds exist (M3). The following-feed is a FILTER on the existing feed, still
    threaded through VisibleForumIds (a followed user's activity in a forum you can't see stays hidden).
  • badges / user_badges / reputation_events do NOT exist — greenfield.
  • AccountDeletionService already recomputes post_reaction_counts after removing the user's reactions —
    MIRROR that exact pattern for reputation_points.

BUILD — four PR slices, low-coupling first (each lands independently runnable + tested on baseline):

────────────────────────────────────────────────────────────────────────
SLICE 1 — FOLLOW (◐ — wire the existing user_relationships follow half)
────────────────────────────────────────────────────────────────────────
  • Perms: follow.create, follow.delete (member). follow.create is TL-gated `no` (admin-liftable) in
    antispam.trust_gates + a per-TL FollowRateLimiter (mass-follow = notification spam). SELF-FOLLOW is a
    hard refuse in the service (a user cannot follow themselves). Respect the ignore graph: do not deliver a
    follow notification to someone who ignores the follower (reuse the M2-B ignore check).
  • FollowService: idempotent create on UNIQUE(follower_id, followee_id, type='follow'); delete; follower /
    following count helpers (query or denorm — keep within budget).
  • Notification: on follow → emit the EXISTING 'follow' event (notify the followee). This replaces the
    M2-A stub; no new event type, no second render path.
  • Following-feed: ActivityFeed variant filtered to actor_id IN (followed ids), STILL passed through
    VisibleForumIds (reuse — do not rebuild or bypass). Empty follow set → fall back to the global feed with
    a "follow people to personalise this" hint (decide + document). Same RH-9 cache discipline + ≤20 budget.
  • Profile: follow/unfollow button (own-profile hides it) + follower/following counts.
  • DELETION CASCADE: in AccountDeletionService, remove user_relationships rows where follower_id = U OR
    followee_id = U (both directions), same transaction. Forced-cascade test.
  • Tests: idempotent follow; self-follow refused; follow.create TL gate + rate limit; following-feed
    excludes a followed user's activity in a forum the viewer lacks forum.view on; follow notif fires,
    respects ignore + prefs; cascade removes both directions.

────────────────────────────────────────────────────────────────────────
SLICE 2 — REPUTATION / POINTS (⚙ — idempotent award is the apex piece)
────────────────────────────────────────────────────────────────────────
  • Schema (reversible): reputation_events(
        id,
        user_id        unsignedBigInteger NOT NULL,  -- RECIPIENT (indexed); the denorm sum's owner
        source_type    varchar(100)       NOT NULL,  -- polymorphic source (e.g. Reaction)
        source_id      unsignedBigInteger NOT NULL,
        points         integer            NOT NULL,
        created_at,
        UNIQUE(source_type, source_id)              -- idempotency: one source awards at most once
    )
    users.reputation_points (EXISTS) = denormalised SUM(points) per user.
  • ReputationService (⚙):
      award(User $recipient, Model $source, int $points): insertOrIgnore the event on the UNIQUE(source);
        ONLY when a row is actually inserted, increment users.reputation_points by $points (use an atomic
        DB increment, not read-modify-write). A re-fired event = no-op (no double count).
      revoke(Model $source): delete the event for that source; if a row was deleted, decrement the
        recipient's reputation_points by the stored points.
      recomputeFor(array $userIds): authoritative SUM from the ledger → overwrite the denorm (self-heal).
  • Reaction wiring (amendment #4 — the score weights light up here): a QUEUED / afterCommit Reacted
    listener → award(post.author, reaction, config weight for that reaction type). A Reacted-removed /
    unreact listener → revoke(reaction). GUARD self-reaction (no rep to self). KEEP THIS OFF THE HOT REACT
    PATH — the react action's ≤15 query budget must still hold (mirror M2-A's queued SendReactionNotification).
  • Optional (config, owner-tunable, default 0): a small fixed award per post / topic created.
  • Cron: nevo:reputation:recompute (withoutOverlapping + short mutex; bounded batch) recomputes the denorm
    from the ledger. Idempotent. NOTE: adds a 'nevo:' cron to the Phase-5 rename surface (#8) — do NOT
    pre-rename to nevobb:.
  • Profile: reputation_points display.
  • DELETION CASCADE (⚙ — the subtle, must-get-right extension): in AccountDeletionService, in the same
    transaction:
      (a) capture the set of OTHER authors whose rep was sourced from U's reactions BEFORE U's reactions are
          hard-deleted (the service already deletes U's reactions — capture alongside the reacted-post ids it
          already captures);
      (b) delete reputation_events where user_id = U (U's own received rep);
      (c) delete reputation_events whose source is one of U's now-deleted reactions;
      (d) ReputationService::recomputeFor(those affected author ids) — authoritative, mirroring the existing
          post_reaction_counts recompute. Do NOT trust running ±decrements through a half-deleted graph.
  • DECISIONS.md: the UNIQUE(source) idempotency key; the atomic-increment + recompute-self-heal design; the
    cascade revoke-from-deleted-sources + third-party recompute rule; the nevo: cron rename-surface note (#8).
  • Tests: award twice = single count (idempotent); unreact revokes + decrements; recomputeFor self-heals a
    drifted denorm; self-reaction awards nothing; react hot-path ≤15 budget HOLDS; CASCADE — deleting a heavy
    reactor zeroes their own rep AND drops each affected author's reputation_points by exactly the revoked
    weight (this is the headline cascade test).

────────────────────────────────────────────────────────────────────────
SLICE 3 — BADGES (⚙ idempotency · ◻ CRUD) — depends on Slice 2 (rep-threshold criteria)
────────────────────────────────────────────────────────────────────────
  • Schema (reversible): badges(id, slug UNIQUE, name, description, criteria JSON, icon_token nullable,
    color_token nullable, is_active bool, created_at); user_badges(id, user_id, badge_id, awarded_at,
    UNIQUE(user_id, badge_id)).
  • BadgeService (⚙): evaluate(User $user, string $trigger) called from auto-discovered listeners on the
    relevant events (PostCreated → post-count criteria; a ReputationAwarded signal → rep-threshold criteria;
    registration → join badge). Award = insertOrIgnore on UNIQUE(user_id, badge_id) — idempotent, PERMANENT
    (badges are not revoked if criteria later lapse; document this). nevo:badges:recompute cron does a bounded
    full-sweep re-evaluation (withoutOverlapping + short mutex) to catch anything a missed event dropped.
    NOTE: another 'nevo:' cron on the rename surface (#8) — do NOT pre-rename.
  • Criteria JSON: a small documented schema ({type: post_count|reputation|join|..., threshold: N}); validate
    on save. Keep the engine closed-set for M5 (no arbitrary expression eval — that's a security surface).
  • ACP ⚡badges CRUD (mirror ⚡prefixes / ⚡groups); perm badge.manage (admin). AdminAccessWalkTest auto-covers
    the new /admin route.
  • Profile: earned-badges display (AA-token chips, reuse the prefix/tag badge pattern).
  • DELETION CASCADE: remove user_badges where user_id = U (same transaction). Forced-cascade test.
  • Tests: criteria award idempotent (no duplicate on re-fire); cron sweep awards a badge a missed event
    skipped; rep-threshold badge fires after Slice-2 award crosses the threshold; ACP render-mirror + CRUD;
    badge.manage gate; cascade removes user_badges.

────────────────────────────────────────────────────────────────────────
SLICE 4 — BETA POLISH + FULL PHASE-2 REGRESSION (closer → 🚩 Public Beta)
────────────────────────────────────────────────────────────────────────
  • DemoSeeder refresh (◻): a fresh baseline install must DEMONSTRATE the beta — seed reactions, polls, PMs,
    the activity feed, follows, reputation_events (→ visible points), and a default badge set with awards.
  • Docs (◻): getting-started.md + .env.example — add the two new cron entries (nevo:reputation:recompute,
    nevo:badges:recompute) to the cron block; document any new config (rep weights, default award).
  • FULL Phase-2 regression (⚙ on the rehearsal): perf/asset/query budgets (react ≤15, feed ≤20, profile +
    board within their documented ceilings); forced-absence suite (mail / search engine / Redis absent →
    graceful); permission-mask truth-tables EXTENDED for follow.create + badge.manage; the EXTENDED
    deletion-cascade truth tables (reactions/polls/tags/PMs/activities + reputation/badges/follow all proven);
    RH-10 auto-upgrade rehearsal (Phase-2 migrations upgrade cleanly on the baseline tier, backup-first) +
    RH-11 panel-restore rehearsal (backup → restore round-trip verified).
  • OPTIONAL / stretch (Should — only if the above is green and there's headroom): a 2nd example child theme
    under themes/<slug>/ (theme.json api_version ^1.0 + real views/ overrides + prebuilt assets) to exercise
    the semver'd override layer end-to-end; extend ThemeOverrideTest. If skipped, note it as the one Should
    item carried to a fast-follow.

DEFINITION OF DONE (binding):
  • All three social-pack slices green on baseline, each its own PR with full conventional-commit history.
  • Idempotency PROVEN by test: reputation double-award no-ops; badge re-evaluation no-dupes; both crons safe
    under withoutOverlapping + repeated runs.
  • The EXTENDED cascade PROVEN: deleting a user with received rep, given rep (via reactions), badges, and
    follows leaves zero orphans AND recomputes affected third-party authors' reputation_points exactly.
  • Budgets HOLD: react action ≤15 (rep award is off the hot path); following-feed ≤20; new profile/ACP
    surfaces within documented ceilings (record any change as a DECISIONS entry, never a silent bump).
  • follow.create TL-gate + rate limit + self-follow-NEVER enforced and tested; follow notif respects the
    ignore graph + prefs.
  • ACP ⚡badges render-mirror (AdminAccessWalkTest) + badge.manage gate green.
  • RH-10 upgrade rehearsal + RH-11 restore rehearsal EXECUTED and green on the baseline tier (this is the
    public-beta gate — not a paper check).
  • DemoSeeder shows reactions/polls/PMs/feeds/follows/reputation/badges on a fresh install; getting-started
    + .env.example current (cron entries added).
  • Dusk journeys: follow a user → their activity in the following-feed; receive a reaction → points rise;
    earn a badge → it shows on the profile. Light/dark × mobile/desktop screenshots (p2m5-*).
  • Pint / Larastan L5 / composer+npm audit / assets-fresh green; full suite green.
  • PROJECT-STATE + PROJECT-HISTORY + DECISIONS updated. Post-build adversarial review on the ⚙ pieces
    (idempotent award, the extended cascade, the badge criteria engine, follow anti-spam) before the PRs land.
  • Then TAG the Public Beta.

SCOPE FENCE — follow + reputation/points + badges + beta polish + full regression ONLY. HELD / NOT here:
staff notes (staff_notes / StaffNote — still deferred); a reputation LEADERBOARD / top-members surface
(fast-follow — avoids a new cached hot surface in the beta gate); trust-level AUTO-PROMOTION tied to rep
(the TL system is ADR-0007 anti-spam — do NOT entangle it with reputation here); a full ACP member-management
page; GDPR data-export; all Phase-3 items (plugin/module API, REST API + webhooks, importers, visual theme
configurator, admin analytics). REUSE VisibleForumIds / ActivityFeed / the Notifier 'follow' vocab / the
reaction score config — do NOT rebuild or add a second permission or render path. Reversible migrations only.
Strict clean-room. If a needed mechanism isn't in the engine, FLAG it rather than improvising. When the
social pack + regression land runnable + tested and RH-10/RH-11 rehearsals are green, report back here.
```

---

## Owner-tunable product decisions (confirm before / during build — not blockers)

These are content/config calls, not mechanism; Code can ship sensible defaults and you tune them:

- **Reputation weights.** Per-reaction-type point values (the inert M1 config), and whether post/topic
  creation awards a small fixed amount (default **0**). Suggested start: a "like"-class reaction = **+1** to
  the author; no creation award.
- **Default badge set + criteria.** Which badges ship in the seed and their thresholds — e.g. *Welcome*
  (join), *First Post*, *Conversationalist* (N posts), *Well-Regarded* (N reputation). Code proposes a
  starter set; you approve the list and numbers.
- **Empty following-feed behaviour.** Fall back to the global feed with a hint (proposed) vs. an explicit
  empty state.

## When M5 reports back

Cowork reviews: (1) **idempotent award** — does a double-fired Reacted event leave the count unchanged, and
does the recompute cron reconcile a deliberately-drifted denorm? (2) **the extended cascade** — does deleting
a heavy reactor drop *each affected author's* `reputation_points` by exactly the revoked weight (not just zero
the deleted user)? (3) **following-feed permission-filtering** — is a followed user's activity in a forum the
viewer can't see correctly excluded (VisibleForumIds reused, not bypassed)? (4) **react hot-path budget** —
still ≤15 with the rep award queued off it? (5) **crons** — `withoutOverlapping` + bounded + idempotent?
(6) **RH-10/RH-11 rehearsals actually executed** on the baseline tier, not asserted on paper? Then update
PROJECT-STATE / PROJECT-HISTORY and tag the Public Beta.

## After this

- **🚩 Public Beta is live** — fold private/public-beta feedback (may reorder later work per product-plan §8).
- **Fast-follows** (deferred from M5): staff notes; reputation leaderboard / top-members; trust-level
  auto-promotion tied to reputation.
- **Phase 3 — Extensibility:** module/plugin API + hook/event/slot system (semver'd) + compat check; visual
  theming + layout configurator; REST API + webhooks; phpBB/MyBB/SMF importers (verify + redirects); admin
  analytics. This is the next *big* architectural phase and gets its own discovery + plan-before-code gate.
