<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# Reevaluation Synthesis — three external reviews vs. shipped NovFora

> **Date:** 2026-06-18 · **Author:** owner-requested aggregation (Cowork) · **Status:** analysis only — no code written.
> **Inputs:** four uploaded reviews (one Claude, the Codex/Gemini set of three) measured against the **actual
> v1.0.0 GA codebase** + `ROADMAP.md` / `PROJECT-STATE.md` / `CHANGELOG.md`.
> **Goal (owner's words):** *"aggregate all of these and make NovFora the best of the best of the best."*
> **Scope chosen:** **Core forum parity + admin/ops breadth.** Standalone suite apps stay deferred as modules.

---

## TL;DR — the verdict

The single most important finding is that **all three reviews are partly stale, in proportion to how deeply they
read the code.** "Best of the best" is therefore **not the union of the three** — it is a much shorter, sharper list
once you subtract everything that already shipped.

1. **The Claude review** (*Feature Gap Analysis & Granular Build Roadmap*) is by far the strongest: it inventoried
   real code, mapped each gap onto NovFora's actual architecture (TipTap island, `ContentSanitizer`, the Filesystem
   capability contract, `ScopeChain`, `AccentPalette`, the ADR-0037/0038 evaluator), respected the locked decisions,
   and phased the work with apex-test discipline. **But even it overstates the top of its own stack:** it lists the
   two biggest P0 items — **first-class attachments (G1, "XL")** and **read/unread tracking (G2)** — as missing when
   **both are fully shipped.** It also undercounts leaderboard, cover photos, and the Turnstile CAPTCHA (all shipped).
   Net: ~70–80% of its roadmap is sound and adoptable; its headline P0 is already done.

2. **The Codex / Gemini set** (*platform comparison*, *implementation plan*, *legacy forum comparison*) is good as
   **strategy and north star** — "Invision's admin UX + XenForo's Style Properties & SEO, on Laravel's foundation,
   with bulletproof upgrade-safe extensibility." **But operationally it is the weakest:** it (a) points at the wrong
   working directory (`D:\NovFora_DEV` — the repo is `D:\Forum`), (b) proposes building **~6 systems NovFora already
   ships** (ACP global search / `⌘K`, the permission "why" inspector, the system-health dashboard, the
   warning/infraction engine, inline bulk moderation, split/merge), and (c) one headline proposal **conflicts with a
   locked security decision** (a Monaco-backed, DB-stored **raw-Blade** template editor vs. ADR-0037/0038's
   *data-only sandboxed evaluator — never raw Blade/PHP*). Its genuinely net-new, not-yet-built contribution is
   essentially **one strong idea: the Embed API / SSI / web-components**, plus one minor one (username history).

**So the aggregation is:** take the Claude review's architecture-aware roadmap, **subtract the ~5 items that have
shipped since it was written**, **add the one real net-new idea** the others surfaced (Embed/SSI), and **route the
theming-depth work through the locked sandboxed-evaluator path** rather than the conflicting raw-Blade proposal.

---

## Methodology — why this differs from the three reviews

Every claimed gap was checked against the **actual code**, not against the reviews' own assertions. A read-only sweep
of `app/`, `database/migrations/`, `resources/views/`, `routes/`, `modules/`, `config/`, `lang/` confirmed
present/partial/absent with file-level evidence. That step is the whole value here: it caught that the **single
largest item in the best review (attachments) was already built**, and that **five of the Codex/Gemini proposals
were re-proposing shipped systems.**

Effort scale (from the Claude review): **S** ≤2d · **M** ≤1wk · **L** 1–3wk · **XL** >3wk (one experienced dev).
**APEX** marks a permission / untrusted-input / concurrency surface that, per `CLAUDE.md` routing, gets the apex rung
+ a verify-then-refute adversarial review before merge.

---

## Scorecard — the three reviews

| Review | Read the real code? | Accuracy vs. shipped | Net-new value | Conflicts w/ locked decisions | Best used as |
|---|---|---|---|---|---|
| **Claude** — Feature Gap Analysis & Build Roadmap | **Yes**, deeply | **High** (but calls 2 shipped P0s "missing") | **High** — most of the real gap list + correct designs | **None** | The **spine** of the unified roadmap |
| **Codex/Gemini** — Implementation Plan (*Enterprise Tier*) | No (wrong dir `D:\NovFora_DEV`) | **Low** — ~6 proposals already shipped | **Low–Med** — Embed/SSI + username history are the only net-new | **Yes** — Monaco/DB **raw-Blade** editor; `spatie/activitylog` dep | Mine for the **2 net-new ideas**; discard the rest |
| **Codex/Gemini** — Platform Comparison | No | N/A (landscape, not gaps) | **Strategic** — the north-star framing | No (no concrete build) | **Positioning narrative** for the README / pitch |
| **Codex/Gemini** — Legacy Forum Comparison | No | Low — re-proposes the shipped inspector | **Low** — restates Embed/SSI | No | A second vote for **Embed/SSI** |

> Attribution note: the first review is unmistakably the Claude one (exact ADR numbers, internal class names, the
> `forum-dev` gate, locked-decision awareness). The other three share one voice, the wrong working directory, and the
> "User Review Required → approve this plan" footer; they are the Codex and Gemini outputs. Which of those two wrote
> which of the three isn't reliably separable and doesn't change the conclusions.

---

## Stale claims — already shipped (drop from any "to build" list)

This is the heart of the reconciliation. Each row was proposed as a gap or build item by at least one review but is
**already in the v1.0.0 codebase.**

| Proposed by | Claimed as | Actual status | Evidence |
|---|---|---|---|
| Claude (G1, P0/XL) | "no user upload pipeline" | **SHIPPED** | `attachments` table · `AttachmentController` (store/show) · `AttachmentService` · TipTap drag-drop/paste, off-web-root storage, IDOR guard |
| Claude (G2, P0) | "no surfaced read-marker" | **SHIPPED** | `topic_reads` + `TopicRead` · `WhatsNewController` `last_read_at` watermark |
| Claude (E4) | "no leaderboard pages" | **SHIPPED** | `members/top.blade.php` (`members.top`) · `⚡leaderboard` · rep/posts, all-time/30d/7d |
| Claude (G12) | "cover photo ❌" | **SHIPPED** | `profiles/edit` cover input · `ProfileController` → `covers/` disk (avatar upload + letter fallback too) |
| Claude (G14) | "no CAPTCHA provider" | **SHIPPED (Turnstile)** | `TurnstileCaptchaProvider` + `CaptchaManager` + `QaCaptchaProvider` (only hCaptcha/reCAPTCHA missing) |
| Codex/Gemini (Impl §1.1) | "build ACP global search (`⌘K`)" | **SHIPPED** | `Admin/SearchController` · `admin.search` (pages/settings/members) + sidebar quick-filter |
| Codex/Gemini (Impl §4.3; Legacy §A) | "build the permission Why-inspector" | **SHIPPED** | `PermissionInspector` · `⚡permission-inspector` · `novfora:why` (CLI + ACP, scope-chain + verdict) |
| Codex/Gemini (Impl §1.3) | "build system-health dashboard" | **SHIPPED** | `admin/dashboard.blade` — 7 status indicators + approval-queue + open-reports + recent audit |
| Codex/Gemini (Impl §1.2) | "build audit logging (`spatie/activitylog`)" | **SHIPPED** | `AuditLog` model + `⚡audit-log`; surfaced on the dashboard (no new dep needed) |
| Codex/Gemini (Impl §3.3) | "build warning/infraction system" | **SHIPPED** | CHANGELOG Phase 2 — warnings/infractions (decay, auto-consequences, ack) |
| Codex/Gemini (Impl §3.1) | "build inline bulk actions" | **SHIPPED** | CHANGELOG Phase 2 — inline + bulk moderation · `BulkModerationService` |
| Codex/Gemini (Impl §3.2) | "build split/merge" | **SHIPPED** | CHANGELOG + Claude review (merge/split ✅) |

**Read:** the Claude review is stale on the **top of its own P0** (attachments + read/unread were its #1 and #2);
the Codex/Gemini "Enterprise Tier" plan is **largely a description of features that already exist.**

---

## Conflicts & do-not-adopt (flag, don't merge as written)

1. **Monaco + DB-stored *raw Blade* template editor** (Codex/Gemini Impl §2.1–2.2) — a custom view compiler that
   loads DB templates *before* the disk `.blade.php`. This **directly conflicts with the locked decision** ADR-0037 /
   ADR-0038: the template editor is a **data-only sandboxed evaluator — never raw Blade/PHP eval** (threat model in
   `docs/architecture/sandbox-template-threat-model.md`). **Adopt the *intent*** (visual properties + revertable,
   diffable customization) **via Tier 3 below**, which extends the sandboxed evaluator + a hook layer — not raw eval.
2. **`spatie/laravel-activitylog`** for audit (Impl §1.2) — `AuditLog` already exists; adding a dependency must go
   through `DECISIONS.md` and would duplicate shipped functionality. **Reject; broaden the existing model instead.**
3. **Wrong working directory** `D:\NovFora_DEV` across the Codex/Gemini set — the repo is **`D:\Forum`**. Any
   "execute in `D:\NovFora_DEV`" instruction should be ignored.
4. **"Build the inspector / ACP search / health dashboard"** — all shipped (table above). **No-ops.**

---

## Genuinely net-new (the parts of the other reviews worth keeping)

After subtracting the stale and the conflicting, the Codex/Gemini set contributes exactly two things the Claude
review did **not** already cover and the code does **not** already have:

- **Embed API / SSI / web components** — JSON endpoints + distributable `<novfora-…>` custom elements so external
  sites (WordPress, static HTML) can render "latest posts / who's online" — SMF's legendary `SSI.php`, modernised.
  **Confirmed absent** in code. This is the standout addition and earns a tier of its own.
- **Username history + name-change revert** — track prior usernames, let staff revert. **Confirmed absent.** Small,
  high-value for moderation/forensics; pairs naturally with the existing IP/ban tooling.

---

# The unified "best of the best" roadmap

Reconciled against shipped reality, ordered by user-visible impact. Each phase ends **runnable + tested on the
baseline tier** (PHP 8.3 + MySQL + cron) and follows existing discipline (Pest/PHPStan L5/Pint gates; ADR per
non-obvious decision; APEX surfaces get a verify-then-refute pass). IDs reference the Claude review where applicable.

## Tier 1 — Real core gaps (every incumbent has these; verified missing/partial)

| ID | Item | Status today | Effort | APEX? | Source |
|---|---|---|---|---|---|
| U1 | **Quoting & multi-quote** — per-post Quote action, attribution + backlink, multi-quote accumulate/insert | PARTIAL (plain blockquote button only) | M | – | Claude G3 |
| U2 | **Following → forums/tags + "Watched/Followed content" surface** + auto-follow-on-reply | PARTIAL (user→user only; no watched surface) | M | – | Claude G5 |
| U3 | **Profile wall / status posts + comments** (+ reactions, reporting, privacy controls) | ABSENT | L | std+ | Claude G4/E1 |
| U4 | **Announcements / notices** — dismissible, display criteria (group/page/device/date); site-wide + per-forum | PARTIAL (half-wired topic-type col + plain banner) | M | – | Claude G6 |
| U5 | **Navigation / menu manager** — admin-editable top nav (links, order, nesting, per-group, icons) | ABSENT (public nav hardcoded) | M | – | Claude G7 |
| U6 | **Front-of-site moderator toolset** — inline post/thread mod menu, public-side bulk-select, stored replies | PARTIAL (ACP queue + `BulkModerationService` exist; no public inline UX) | L | – | Claude G11 |

## Tier 2 — Net-new (the keepers from the Codex/Gemini set)

| ID | Item | Status today | Effort | APEX? | Source |
|---|---|---|---|---|---|
| U7 | **Embed API / SSI / web components** — JSON endpoints + `<novfora-…>` widgets for external sites | ABSENT | M–L | **APEX** (untrusted-origin boundary; CORS; never leak private/club content; cache) | Codex/Gemini |
| U8 | **Username history + name-change revert** | ABSENT | S | – | Codex/Gemini |

## Tier 3 — Theming depth (the shared north star — routed through the LOCKED path)

Theme Studio exists (≈7 tokens, AA-derived dark palette, child themes, per-theme logo/favicon, the **sandboxed**
template editor). The depth gaps below are real and are what both the Claude T-series and the Codex/Gemini "Style
Properties + template editor" ask for — **but built on ADR-0037/0038, not raw Blade.**

| ID | Item | Status today | Effort | APEX? | Source |
|---|---|---|---|---|---|
| U9 | **Rich Style-Property system** — dozens of grouped, typed visual props → CSS custom properties, live preview, AA guard | PARTIAL (~7 tokens) | L | – | Claude T1 = their "Style Properties" |
| U10 | **Multi-style tree + parent/child inheritance + user style chooser** (`SiteStyle.parent_id`, `user_selectable`) | PARTIAL (filesystem child themes; no DB tree/chooser) | L | – | Claude T2 |
| U11 | **Upgrade-surviving template hook/modification layer + outdated-template detection + Diff3 merge** — extends the sandboxed evaluator; **never raw Blade** | PARTIAL (whole-file override + email/notif sandbox) | L | **APEX** (the evaluator boundary) | Claude T3/T4 = their "template editor + revert", de-conflicted |
| U12 | **Style import/export (zip+manifest) + install-from-file**; **global custom-CSS box** (sanitized) per style | PARTIAL (filesystem only) | M | – | Claude T5/T8 |

## Tier 4 — Admin / ops & SEO breadth (the chosen scope add)

| ID | Item | Status today | Effort | APEX? | Source |
|---|---|---|---|---|---|
| U13 | **IP investigation + ban-management UI** — search-by-IP, per-user IP history, CIDR/range bans, temp/permanent, ban list/lift | PARTIAL (`BanChecker` engine; thin UI; no CIDR) | M | elevated | Claude A1/A2 |
| U14 | **Registration controls** — approval queue, ToS gate, age/COPPA gate, email-domain allow/deny, custom reg fields | PARTIAL (open/closed + verify + anti-spam; group request-queue exists from v3-e) | M | elevated | Claude A3 |
| U15 | **Mass member ops + bulk-mail/newsletter** — group/ban/prune/merge-accounts; audience criteria, schedule, suppression-aware (drains via cron) | PARTIAL (dir "bulk seams"; no campaign tool/merge) | L | **APEX** (deliverability/abuse/VERP) | Claude G13 |
| U16 | **Maintenance/rebuild tools UI** (recount, rebuild index, prune, clear caches) + **broadened log surfaces** + **mail test** | PARTIAL (self-heal crons + dashboard; no one-click UI) | M | – | Claude A5/A6/A7 |
| U17 | **Plugin install-from-zip + signature/trust gate + marketplace hooks** | PARTIAL (enable/disable + trust guardrails; no zip install) | M | **APEX** (untrusted package) | Claude A9 |
| U18 | **Finish CAPTCHA providers** (hCaptcha + reCAPTCHA drivers alongside Turnstile) + **Gravatar** (finish avatars) | PARTIAL | S each | – | Claude G14/G12 |
| U19 | **Custom thread/topic fields** (per-forum) + **move-with-301-redirect** (wire the dead `moved_to_topic_id` seam) | ABSENT / seam-only | M | – | Claude G8/G10 |
| U20 | **SEO/discovery polish** — JSON-LD (`DiscussionForumPosting`/`QAPage`/breadcrumbs) + OG/Twitter cards; **social share**; **"find content by user"** | PARTIAL (sitemap/SEO basics; share ❌) | S–M | – | Claude S1/S2/S4 |
| U21 | **i18n string sweep** — finish the documented residue (components → admin → clubs/settings/notifications/tags/pm) | PARTIAL (framework + `es` proof; ~600+ strings) | L | – | Claude S6 / ADR-0079 |

## Explicitly deferred (out of chosen scope — record only)

Standalone suite apps as **modules** on the existing module API: Media Gallery, Blog, Calendar/Events,
Downloads/Resource Manager, Pages/CMS, Commerce. Also GraphQL API, advertising system, post-by-email. The
architecture precludes none of them; the recommendation (shared with the Claude review) is to **harden the
module-author experience** so these ship as first-party/community modules rather than core weight.

---

## Recommended sequencing

1. **Quick wins first (S):** U8 username history, U18 Gravatar + hCaptcha/reCAPTCHA, U20 JSON-LD/OG + social share.
   Each is ≤2d, high visible polish, no APEX risk.
2. **Tier 1 core gaps (the "feels complete" set):** U1 quoting → U2 watched-content → U4 announcements → U5 nav
   manager → U6 front-of-site mod → U3 profile wall. These are what a user *immediately* misses; all are
   non-APEX except the standard new-content-surface care on U3.
3. **U7 Embed/SSI (APEX):** schedule deliberately — it is an untrusted-origin boundary and must reuse the existing
   SSRF/visibility posture (the same no-leak fence proven for clubs/search/Reverb). Highest net-new payoff.
4. **Tier 3 theming depth:** U9 style properties → U10 style tree + chooser → U11 hook layer + Diff3 (APEX, stays in
   the sandboxed evaluator) → U12 import/export + CSS box. This is the founding "core-edit theming" thesis; do it
   right, on the locked path.
5. **Tier 4 breadth:** U13/U14 (admin trust surfaces) → U15 bulk mail (APEX) → U16 maintenance UI → U17 plugin
   install (APEX) → U19 topic fields + move-redirect → U21 i18n sweep (mechanical, community-contributable).

**Verification per item (non-negotiable, matches house discipline):** `php artisan test --parallel` · PHPStan L5 ·
Pint · `migrate` apply+rollback+re-apply, committed only at green boundaries. APEX items (U7, U11, U15, U17) get a
dedicated verify-then-refute adversarial pass and apex-level tests; every new surface joins the `novfora:a11y:audit`
page gate; each phase proves out on the baseline tier with the enhanced-tier drivers degrading gracefully.

---

## Appendix — per-review notes

**Claude — Feature Gap Analysis & Granular Build Roadmap.** The reference document. Correctly identifies that the
*spine* (permissions, anti-spam, deliverability, extensibility) already leads, and that the real gaps cluster in core
UX fundamentals, community surfaces, and theming depth. Designs are architecture-correct (temp-upload+claim mirroring
`PostDraft`; read-state via sparse markers + `reads_reset_at`; style tree paralleling `ScopeChain`). **Caveat:** treat
its status column as a *snapshot that has drifted* — re-verify each "❌/🟡" against code (as done here) before
building; its two flagship P0s are already shipped.

**Codex/Gemini — Implementation Plan (Enterprise Tier).** Granular and well-structured, but written without reading
the repo: ~6 of its phases describe shipped systems, it targets the wrong directory, and its theming centerpiece
conflicts with the locked sandbox decision. **Keep:** the Embed/SSI idea (its §5) and username history (§4.1). The
ACP-dashboard framing (§1.3) is a *nice articulation* of what's already on `admin/dashboard` — use it as a polish
checklist, not a build.

**Codex/Gemini — Platform Comparison.** No concrete gaps; valuable as the **positioning narrative** ("modern tech,
zero upgrade anxiety; Invision admin UX + XenForo speed/SEO on Laravel"). Good source material for the README,
landing page, and pitch — not for the build plan.

**Codex/Gemini — Legacy Forum Comparison.** Re-proposes the shipped permission inspector (a no-op) but provides a
**second, independent vote for Embed/SSI**, which strengthens the case for U7. Its phpBB/MyBB/SMF framing is accurate
and reusable in marketing.

### Evidence index (verified present, so excluded from the build list)
Attachments (`AttachmentController`/`AttachmentService`/`attachments`) · read-state (`topic_reads`/`WhatsNewController`)
· leaderboard (`members.top`/`⚡leaderboard`) · ACP search (`Admin/SearchController`) · permission inspector
(`PermissionInspector`/`novfora:why`) · health dashboard (`admin/dashboard`) · audit (`AuditLog`) · Turnstile
(`TurnstileCaptchaProvider`) · avatar+cover (`ProfileController`) · Theme Studio + sandboxed editor (ADR-0037/0038) ·
trending/related/RSS/sitemap (mega-build 3.x) · clubs/SSO/PWA/memberships/anti-spam (Phase 4) · ACP v3 IA + card
permissions + groups (v3-h/c/e).
