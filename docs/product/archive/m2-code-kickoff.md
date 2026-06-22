<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# M2 — Claude Code kickoff prompt (Phase 1, Forum structure & content + the editor)

> Paste the block below into the **Claude Code** session to begin Phase 1 **M2**. The Phase 1 plan (M0–M5) is
> owner-approved; **M0 + M1 are done** (M1 at commit `be9040d`). **Owner sign-off (2026-06-02): the permission
> `NO` is `neutral/inherit` — interpretation (ii); use `NEVER` to hard-deny.** M2 is where the **validated Spike 0
> editor finally ports into the real app** and `nevo-spike/` retires.
> Specs: [phase-1-plan.md](../phase-1-plan.md) §5 (M2) + **§4 (the 7 editor findings — now you implement them)**;
> [data-model-initial.md](../../architecture/data-model-initial.md) §2 (structure) + §3 (canonical storage);
> ADR-0005; [security-and-permissions.md](../../architecture/security-and-permissions.md) §3 (soft-delete/recycle/audit)
> + §4 (XSS/sanitize); the **[spike-0 memo](../spike-0-memo.md)** + the `nevo-spike/` reference files.

---

```
Begin Phase 1 — M2 (Forum structure, content, and the WYSIWYG editor). M0 + M1 are done; the Phase 1 plan is
approved. This is the milestone where the validated Spike 0 editor pattern ports into the real app.

START OF SESSION: read PROJECT-STATE.md, CLAUDE.md, docs/PROJECT-BRIEF.md (standing rule). Then the M2 spec:
docs/product/phase-1-plan.md §5 (M2) AND §4 (the 7 editor findings — you implement these now);
docs/architecture/data-model-initial.md §2 (categories→forums→topics→posts) + §3 (canonical content storage);
ADR-0005; docs/architecture/security-and-permissions.md §3 (soft-delete/recycle/audit) + §4 (sanitize/XSS);
the Spike 0 memo (docs/product/spike-0-memo.md) and the nevo-spike/ reference files you are porting from.

MODEL/EFFORT: Opus 4.8 at xhigh on (a) the canonical renderer + sanitizer — the security boundary — and
(b) the editor integration (apply the 7 findings exactly; a reactive-proxy regression there is the known trap).
Sonnet is fine for CRUD + view-scaffolding breadth once the content model is settled.

Open with a SHORT M2 plan (schema for §2; the canonical-storage + renderer approach; how the editor ports from
the spike; attachments; what's fenced out), then proceed — no wait, the plan is approved.

STEP 0 — housekeeping commit: `git status` will show this kickoff doc. Also record the owner's NO=neutral
sign-off durably (it currently lives only in code + a flagged test): add a one-line confirmation to
security-and-permissions.md §1.1 and the ADR-0006 row in DECISIONS.md (or a short ADR-0020) — "NO = neutral/
inherit (interpretation ii), owner-confirmed 2026-06-02; use NEVER to hard-deny." Commit (docs:), then build.

BUILD M2:

1) STRUCTURE (data-model §2): `forums` (self-referential parent_id + cached path/depth/position, type
   category|forum|link, is_locked), `topics` (type normal|sticky|announcement, status open|locked|moved|merged,
   approved_state), `posts`, `post_revisions` (edit history). Server-rendered Blade/Livewire views; ordering.
   **Every action authorizes through M1's engine** — `$user->can('forum.view'|'topic.create'|'post.reply'|…,
   $scope)`, deny-by-default; this is the per-node permission payoff. Soft-delete + recycle bin + restore;
   the audit log (security §3); sticky/announcement/locked/moved/merged operations.

2) CANONICAL CONTENT STORAGE (ADR-0005 / data-model §3): each post carries body_format (tiptap_json default |
   markdown | legacy), body_canonical (lossless source — editing reopens THIS), body_html_cache (display HTML
   ALWAYS regenerated + sanitized server-side from canonical), body_text (search projection). **Port
   `CanonicalRenderer` from nevo-spike/app/Support/** — the proven JSON→HTML mapper + symfony/html-sanitizer
   allowlist is the security boundary; client HTML is never trusted. Decide whether to back it with a maintained
   MIT tiptap-php lib or keep the hand-rolled mapper — license-vet either way (ADR-0015, record in DECISIONS.md).
   Port + extend the spike's CanonicalRendererTest (the XSS battery) against the M2 node set.

3) THE WYSIWYG EDITOR — port the validated Spike 0 pattern, applying ALL 7 findings (phase-1-plan §4):
   wire:ignore + Alpine island; **editor in per-instance closure state, NEVER a reactive Alpine property**
   (the mismatched-transaction trap); deferred $wire.set with no debounce; Livewire 4 single-file component;
   async (post-upload) insert defers a tick + insertContent; StarterKit v3 bundles Link (don't re-register);
   dynamic-import the editor so it stays out of the main bundle (≤180 KB gz). Add **Markdown input mode** + the
   richer node set (slash commands, tables, code blocks, spoilers, quotes, advanced embeds) per Full-MVP §3.

4) ATTACHMENTS (data-model §2): typed allowlist + size limits; image re-encode + thumbnail (sync or cron);
   checksum; disk = local (baseline) / S3 (enhanced); stored off the web root. Wire to the editor's drag-drop +
   paste upload path (the spike's uploadAndInsert).

5) CACHING: fragment/response caching for forum + thread views (baseline file/DB cache, tier-graceful, never
   load-bearing for correctness).

6) RETIRE nevo-spike/ once CanonicalRenderer + the editor are ported and the M2 editor tests are green.

DEFINITION OF DONE: the Spike 0 acceptance battery now runs as Dusk journeys against the REAL app (state
survival across Livewire round-trips, drag-drop/paste upload, mentions, a11y) + the CanonicalRenderer XSS
battery (Pest) + the bundle budget; CRUD + per-node authorization tests (every gated action goes through M1's
engine); soft-delete/recycle/restore; canonical round-trip + sanitize; reversible migrations. All CI guards
green; the M0 tier suite + M1 permission/truth-table + auth suites STAY green. Runs on the baseline tier
(PHP 8.3 + MySQL + cron). Small conventional DCO commits; keep PROJECT-STATE current.

SCOPE FENCE — build ONLY M2. NOT in M2: anti-spam enforcement, the moderation queue, approval workflows,
ACP/MCP, bans/word-filters beyond what M1 seeded (all M3); reactions, PMs, notifications, search, SEO, theme
polish (M3/M4); reactions are explicitly Phase 2. The data model reserves tags/prefixes/polls/reactions
columns — you may add the tables as seams, but do NOT build those features in M2. Keep the nullable tenant_id
seam; don't build tenancy. Strict clean-room; security-by-default (sanitize from canonical, CSRF, strict CSP,
upload validation, rate limits, audit log). When M2 lands runnable + tested, report back here.
```

---

## When M2 reports back

The Cowork session reviews M2 — with particular attention to (1) the **editor battery passing in the real app**
(not just the spike), (2) the **sanitizer/canonical round-trip** holding under the richer node set, and (3)
every CRUD action **authorizing through M1's engine** (no ad-hoc checks). Then it updates PROJECT-STATE and
preps the **M3** kickoff: the anti-spam baseline + moderation + ACP/MCP — where M1's trust-level groups and the
`NEVER` hard-gate finally get their enforcement.
