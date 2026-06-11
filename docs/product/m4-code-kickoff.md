<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# M4 — Claude Code kickoff prompt (Phase 1, Notifications · Search · SEO · Theme)

> Paste the block below into the **Claude Code** session to begin Phase 1 **M4** — the last *build* milestone
> before M5 (operability) closes out the MVP. The Phase 1 plan is owner-approved; **M0–M3 are done**.
> M4 is broad but mostly known-pattern; the one **deep-reasoning** piece is the **Blade theme-override layer**
> (ADR-0009) — a semver'd public contract, so a breaking change is a major-version event.
> Specs: [phase-1-plan.md](phase-1-plan.md) §5 (M4) + the **two trims in §3**; [data-model-initial.md](../architecture/data-model-initial.md)
> §7 (notifications) + §8 (themes/settings); [system-architecture](../architecture/system-architecture.md) (search ADR-0010,
> email ADR-0014, cron queue ADR-0011); [plugin-and-theme-system](../architecture/plugin-and-theme-system.md) §3 (ADR-0009).

---

```
Begin Phase 1 — M4 (Notifications · Search · SEO · Theme). M0–M3 are done; the plan is approved. This is the
last build milestone before M5 operability. Broad but mostly known-pattern — except the theme-override layer,
which is a versioned public contract: design it deliberately.

STEP 0 — IDEMPOTENCY GUARD + housekeeping (before any build):
  • Confirm M4 isn't already done: read PROJECT-STATE.md + `git log --oneline`. If M4 commits exist / it's
    recorded done, STOP and report — do NOT rebuild.
  • Confirm M3 is green (Docker dev env) and the working tree is clean. If an empty `nevo-spike/` dir is
    still on disk, remove it.
  • Doc reconciliation: phase-1-plan §3's "Explicitly NOT in Phase 1" list is now stale — reports,
    warnings/infractions, and edit history were delivered in M2/M3 (per the brief §6 + security §3). Update
    that list to match what's built, in a small docs commit.

START OF SESSION: read PROJECT-STATE.md, CLAUDE.md, docs/PROJECT-BRIEF.md (standing rule). Then the M4 spec:
phase-1-plan §5 (M4) AND §3 (the two trims — see the fence below); data-model §7 (notifications) + §8
(themes/settings); system-architecture (search/email/queue); plugin-and-theme-system §3 + ADR-0009 (theming).

MODEL/EFFORT: Opus 4.8 at xhigh on (a) the Blade theme-override layer — it's a stable, semver'd public
contract (CLAUDE.md), so design the override-resolution + the published view-slot set so themes never edit
core; and (b) the tier-graceful search + notification abstractions (must work on baseline with no Redis/
Meilisearch/Reverb). Sonnet is fine for SEO output + profile-field breadth.

BUILD M4:

1) NOTIFICATIONS (data-model §7): Laravel `notifications` (UUID, data JSON) — make them MERGE-AWARE ("X and
   3 others replied in [thread]") via the data column. IN-APP via POLLING on baseline (Livewire poll; Reverb
   is Phase 4 — don't build it). EMAIL via the cron-drained queue (ADR-0011) behind a provider abstraction
   (ADR-0014) + `email_suppressions` (bounce/complaint) + a deliverability self-test + the best-effort-baseline
   note. `notification_preferences` per event_type × channel (db/mail). Events for M4: replies, @mentions (from
   the editor), and moderation/warning notices (M3). NO digest/rich notifications, NO web push (Phase 2/4).

2) SEARCH (ADR-0010): Laravel Scout on the `database` driver (MySQL FULLTEXT), indexing posts' `body_text`
   projection (built in M2); inline predictive/typeahead results; per-user unread / "what's new" watermark
   (data-model §9 hot paths). A forced-absence test: Meilisearch configured-but-down → the DB driver still
   serves results, never errors. NO filters/facets (Phase 2 trim).

3) SEO: canonical URLs; human slugs (already on forums/topics); schema.org `DiscussionForumPosting` JSON-LD;
   Open Graph tags; an XML sitemap with `noindex` of empty containers; robots. Server-rendered already — wire
   the metadata + sitemap generation (cron-refreshable).

4) THEME FOUNDATION (ADR-0009 — the deliberate part): one polished, MOBILE-FIRST default theme, plus the
   **Blade override layer** — FILESYSTEM child-theme directories that override core views WITHOUT editing core
   (per data-model §8: developer overrides live on the filesystem, not the DB). Publish a documented, versioned
   set of overridable view slots; resolve theme views ahead of core. a11y FLOOR: WCAG 2.1 AA contrast +
   keyboard operability, validated. NO dark mode, NO second example theme (Phase 2 trim), NO visual
   point-and-click configurator (Phase 3 — the DB `themes.settings` token path).

5) PROFILES: admin-defined custom profile fields; signatures (render through the M2 canonical pipeline +
   sanitizer — reuse CanonicalRenderer, never trust client HTML); cover images + avatars.

DEFINITION OF DONE: notifications deliver on the baseline tier (cron email + polling in-app, merge-aware) with
per-event prefs; Scout DB search returns results + the Meilisearch-absent forced-absence test passes; unread/
"what's new" works; SEO output validates (schema.org JSON-LD, sitemap, canonical, OG); the theme-override
layer is proven by a test (a child theme overrides a view with NO core edit); the a11y floor (contrast +
keyboard) is checked; profile fields/signatures/covers work. All CI guards green; M0–M3 suites STAY green;
the tier-graceful suite covers search + notifications. Runs on baseline (PHP 8.3 + MySQL + cron). Small
conventional DCO commits; keep PROJECT-STATE current.

SCOPE FENCE — build ONLY M4. NOT in M4: digest/rich notifications, web push, PMs (Phase 2/4); search
filters/facets, dark mode, a second theme (Phase 2 trims); the visual theme configurator (Phase 3);
Meilisearch, Reverb, badges/reactions/activity feeds, clubs/monetization (Phase 2/4). Keep the nullable
tenant_id seam; don't build tenancy. Strict clean-room; security-by-default (signatures sanitized from
canonical). When M4 lands runnable + tested, report back here.
```

---

## When M4 reports back

The Cowork session reviews M4 — with attention to (1) the **theme-override layer as a clean public contract**
(a child theme overrides without touching core; the slot set is documented/versioned), (2) **search +
notifications degrading gracefully** on baseline (Scout DB, polling — forced-absence tests), and (3) SEO
output validity. Then it updates PROJECT-STATE and preps the final milestone — **M5: operability** (the no-SSH
web installer, automated backups + restore rehearsal, demo seed + getting-started, performance budgets) —
which closes out Phase 1 and delivers the shippable MVP.
