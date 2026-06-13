<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# P2-M2 Half-B — Multi-participant PMs / conversations — Claude Code kickoff

> Paste the block below into **Claude Code** to begin **Phase 2 · M2 Half-B**. PMs are a **new spam/abuse
> surface** AND the **first co-owned PII** in the product — so the gating and the deletion cascade are the
> load-bearing parts (Opus `xhigh`). **ADR-0025 (account-deletion / privacy-cascade) is already accepted** —
> read it at session start; no ADR work needed. Reuses the existing permission engine, the M3 anti-spam
> posture (the TL0 mass-PM NEVER gate is already seeded), and `ContentRenderer` (no new render path). Builds
> `user_relationships` once — the **ignore** half here (Core); the **follow** half is M3.
> Authoritative specs: [phase-2-implementation-plan.md](phase-2-implementation-plan.md) §2 (M2 Half-B) + §1;
> [ADR-0025](../../DECISIONS.md#adr-0025--account-deletion-and-content-cascade-policy-2026-06-10) (deletion
> cascade contract — binding); ADR-0006 (permission engine).

---

```
Begin Phase 2 — M2 Half-B (multi-participant PMs / conversations). PMs are a new abuse surface and the first
co-owned PII — get the gating (TL0 mass-PM NEVER) and the deletion cascade right over getting it fast. Opus
xhigh on the security spine; Sonnet on the inbox/conversation UI.

START OF SESSION: read PROJECT-STATE.md, CLAUDE.md, docs/PROJECT-BRIEF.md. Read DECISIONS.md
ADR-0025 IN FULL before writing any migration — it is the binding deletion cascade contract for this build.
Then phase-2-implementation-plan.md
§2 (M2 Half-B) + §6 + §1; the permission engine (ADR-0006, docs/architecture/security-and-permissions.md §1);
the EXISTING pm.send catalog entry + config/novfora.php antispam.trust_gates tl0 'pm.send' => 'never'; the
content pipeline (app/Content/ContentRenderer.php). Branch from main AFTER M2 Half-A has merged (so the
`pm.received` event vocabulary exists). Commit identity per CLAUDE.md (Tommy Huynh <tommy@saturnhq.net>,
DCO -s, no AI attribution). Suite green before starting.

MODEL/EFFORT (CLAUDE.md routing):
  • Opus 4.8 xhigh — THINK HARD: the TL0 mass-PM NEVER pin (an admin ALLOW must NOT lift it), the deletion
    cascade (§6), ConversationPolicy (participant-only), the rate / ignore / recipient-cap anti-spam controls.
  • Sonnet 4.6 — the conversation-list / conversation / new-conversation SFC UI once the design is settled.

BUILD:

1) SCHEMA (reversible, with explicit FK on-delete per the §6 ADR):
   • conversations(id, subject?, created_by, last_message_at, created_at).
   • conversation_user(conversation_id, user_id, last_read_at, left_at, can_invite, UNIQUE(conversation_id,
     user_id)).
   • messages(conversation_id, user_id, body_format, body_canonical, body_html_cache?, created_at) — REUSE
     ContentRenderer; NO new render path.
   • user_relationships(user_id, related_user_id, type ENUM('follow','ignore'), UNIQUE(user_id,
     related_user_id, type)) — BUILD + USE THE IGNORE HALF (Core). Leave the follow half for M3 (don't wire
     feed/notification-routing here), but the table is built once.

2) GATING (⚙ — the security spine):
   • Implement pm.send (already in the catalog). PIN the TL0 mass-PM NEVER: verify config tl0 'pm.send' =>
     'never' holds end-to-end and add a regression test that an admin/group ALLOW CANNOT lift it (mirror the
     M3 anti-spam pin).
   • PmRateLimiter (per trust level); a mass-PM recipient cap per send.
   • Recipient IGNORE check: a user who ignores you does not receive your message and cannot be added to a
     conversation you start.
   • report-on-PM via the existing Report polymorph; ConversationPolicy = participant-only (non-participants
     get 403 on read / reply / invite).

3) CONTENT: message bodies render ONLY through ContentRenderer + ContentSanitizer (identical to posts) and pass
   ContentModerator::review() on send; store body_canonical. No second sanitize path.

4) NOTIFICATIONS: wire the `pm.received` event (Half-A added the vocabulary) into the Notifier — it now has a
   live emitter; it must respect digest cadence + SuppressionGate + the recipient's per-event prefs.

5) UI (Sonnet): ⚡conversation-list (inbox; unread via last_read_at), ⚡conversation (thread + reply, editor
   reuse), ⚡new-conversation (recipient picker honouring ignore + the mass-PM cap). #[Locked] on conversation
   ids; auth asserted in mount() AND every action; participant-only throughout.

6) DELETION CASCADE (⚙): implement ADR-0025 exactly for PMs — conversation_user rows hard-deleted,
   messages authored by the deleted user pseudonymised (user_id = NULL, body intact); forced-cascade tests.

DEFINITION OF DONE (binding): PermissionMaskTest EXTENDED with the TL0-mass-PM-NEVER pin (admin ALLOW can't
lift it) green; PM body XSS-sanitized via ContentRenderer; rate / ignore / report / recipient-cap tests;
query budgets inbox ≤15 / conversation ≤30; forced-absence (mail down → DB notification, never an error);
deletion-cascade tests green (ADR-0025 contract fully covered); Dusk PM journey + light·dark × mobile·desktop
screenshots; Pint / Larastan L5 / composer+npm audit / assets-fresh green; PROJECT-STATE updated. Small
conventional DCO commits, one logical change each.

SCOPE FENCE — PMs + conversations + the IGNORE half of user_relationships + their tests ONLY. Build the
user_relationships table once (the follow half is M3 — HELD — so do not wire follow/feed/notification-routing,
but the schema is here). NOT here: reputation, badges, staff notes (Should-tier — HELD); merge/split, search
facets, cross-page bulk-select (M4); follow feeds (M3). No second permission or render path. Reversible
migrations. Strict clean-room. If a needed mechanism isn't in the engine, FLAG it — do not invent a parallel
one. When Half-B lands runnable + tested, report back here.
```

---

## When Half-B reports back

Cowork reviews (adversarially, security-critical): (1) can an admin/group ALLOW lift the **TL0 mass-PM NEVER**
anywhere (it must not — dedicated mask test required)? (2) is the **ignore** check enforced at *both* send and
add-to-conversation at the service layer, not just the UI? (3) does the **deletion cascade** match ADR-0025 —
`conversation_user` hard-deleted, authored `messages` pseudonymised (user_id = NULL, body intact), thread
survives for remaining participants? (4) PM bodies sanitized through the **single** ContentRenderer path — no
second sanitizer? (5) participant-only policy holds on read/reply/invite (non-participant gets 403, not a data
leak). Then updates PROJECT-STATE.

## After this

- **M3 (mostly HELD):** activity feed (Core) + the follow half (reuses this table) + reputation / badges
  (Should-tier) — release when you choose.
- **M4:** cross-page bulk-select, merge/split topics, staff notes, search facets, consolidated preferences.
