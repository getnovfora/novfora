<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# NovFora — Competitive Review & Roadmap v1.3 → v1.5 (2026-07-02)

> **What this is.** A full project review + competitive gap analysis against the 2026 state of the art
> (Discourse, XenForo 2.4, Invision Community 5, NodeBB 4, Flarum 2.0), and the forward roadmap for the
> **v1.3, v1.4, v1.5** releases with phases and milestones. Grounded in `main` @ `4ef4d24` (v1.2.0 tagged,
> awaiting owner push), the Linear plan-of-record (team **NovFora**: "UI/UX" + "Phase 6 — U-series"), and
> `DEFINITIVE-ROADMAP-2026-06-27.md`. Supersedes that document's §3 sequencing; everything else there stands.
>
> **Conventions carried forward:** phases scoped by deliverable, not calendar · every phase ends runnable +
> tested on the Baseline tier · plan-before-code with approval at each gate · apex surfaces flagged ◆ get the
> adversarial verify-then-refute review · no locked decision is relitigated here.

---

## Part 0 — Project review (state of the platform, 2026-07-02)

### Where NovFora stands

The functional surface is **far deeper than the marketing story currently tells**. A codebase sweep confirms
NovFora already ships, at parity or better with the commercial leaders: the permission-mask engine (with
expiry, delegation, rank guards, and the inspector — deeper than XenForo's or Invision's), ACP v3 (Invision-
class IA), clubs, paid memberships, trust levels + reputation + **badges** + warnings with decay, **polls**,
**bookmarks**, topic **prefixes** + tags, **ignore lists**, a first-party **Q&A module with accepted
answers**, quote-reply, subscriptions + digests, canned replies, drafts + edit history + scheduled posts,
phpBB/XenForo importers, REST API + webhooks, a semver'd plugin API with **signed zip install**, an **embed
API/web-components layer no competitor has**, PWA + web push, i18n + RTL, a CI-enforced WCAG 2.1 AA gate,
SEO (JSON-LD/OG, dynamic robots, slug 301s), multi-driver CAPTCHA + StopForumSpam + HOLD-only spam
intelligence, and the two-tier deploy story (shared PHP host → Docker/VPS with Meilisearch/Reverb/Redis).

**2 251 passing tests, PHPStan level-max clean, reversible migrations proven, and six shipped apex programs
with adversarial reviews on record.** For a project this young, the correctness posture is exceptional.

### Structural strengths to protect

1. **The Baseline tier is the moat.** Discourse needs Docker + ~2 GB; NodeBB needs Node; Flarum is closest
   but far shallower. *A modern forum that installs on any PHP host with no SSH* is a position nobody else
   holds. Every roadmap item below preserves it (progressive enhancement, cron-only fallback).
2. **The permission engine + apex review discipline** — the ALLOW/NO/NEVER masks, the one-engine rule
   (every grant surface projects into `acl_entries`), and the verify-then-refute reviews caught real HIGHs a
   green suite missed. This is the platform's engineering signature; new features must keep riding it.
3. **The extensibility pair is complete** (embeds out via U7, signed plugins in via U17) but **undistributed**
   — there is no registry, directory, or one-click discovery. That's the cheapest large win available (v1.4).

### Risks / debt to burn down (fold into release gates below)

- **Dusk never runs in the build environment** — browser regressions are structurally invisible until CI.
  Standing "run Dusk in CI" notes have carried across four morning reports. → v1.3 Phase A exit criterion.
- **Parked product decisions:** U8 imported-username revert vs. the format rule (ADR-0106); U18 Turnstile
  fail-open vs. the new drivers' fail-closed (ADR-0107). → decide at the v1.3 gate.
- **Known engine doors:** `GroupManager::removeMember` guards the co-owner tier but not the last *plain*
  admin (ADR-0086 gap); the `GroupPermissionEditor` group-key fan-out is a documented bounded gap under
  delegation (ADR-0087). → v1.3 Phase A hygiene slice.
- **Maintenance debt:** transitive `guzzlehttp` composer-audit advisories; asset-budget drift; the
  design-polish-era branch bookkeeping (confirm all six 2026-06-22 branches are merged or retired).
- **MyBB/SMF importers are scaffolds** (ADR-0034) — an adoption lever left half-pulled. → v1.4.
- **VALIDATE-BEFORE-GO-LIVE residue** (live Stripe, OAuth/SAML, Web Push, SFS submission, at-scale load,
  manual a11y) — owner-gated; scheduled as v1.5 release-gate items rather than left floating.

---

## Part 1 — Competitive gap analysis (July 2026)

### The field

| Platform | 2025–26 direction | What it holds over NovFora today |
|---|---|---|
| **Discourse** | The AI leader: topic summaries, semantic related-topics/search, AI helper, sentiment/emotion scoring for admins, AI spam triage, translations, AI personas; Discourse Discover directory; built-in chat | AI suite; discovery/relevance; ecosystem scale |
| **Invision Community 5** | UX + community-management: new UI/dark mode/theme editor, drag-drop page builder with rollback, topic summaries, expert badges, **topic assignment for mod teams**, **community-health metrics (first-response time)**, **Quests** gamification, welcome emails | Front-of-site polish; staff workflow; health analytics; onboarding gamification |
| **XenForo 2.4** (in beta, slipped from Q4 '24–Q1 '25) | QoL release: TipTap rich editor, PDO layer, featured-content curation + API | Theming ecosystem depth; resource manager marketplace; featured content |
| **NodeBB 4** | **ActivityPub federation shipped, on by default**; leads ForumWG standardization | Federation |
| **Flarum 2.0** | Federation planned (NLnet-funded); lightweight PHP | Nothing material — but it is the closest baseline-tier rival, and federation would leapfrog NovFora |
| Trends (industry) | AI personalization + moderation-as-strategy; gamification beyond points (story/collaboration); "emotional safety" as a design pillar | — |

### Verdict: five real gaps

1. **Front-of-site cohesion** — the audits' consistent finding. Function is ahead of feel; UX-1…UX-7 +
   the permission-aware UI contract are diagnosed and specced but unbuilt. *This is the #1 perceived gap
   for any evaluator comparing demo-to-demo against Invision 5.*
2. **Engagement loop depth** — multi-quote (U1), follow forums/tags + a watched-content surface (U2),
   announcements (U4), profile walls (U3), front-of-site mod tools (U6). All table stakes at XenForo/
   Invision; all already scoped in the U-series.
3. **Discovery & relevance** — no related-topics, no "because you follow X" surfaces, search UX is
   utilitarian. Discourse's semantic layer is its stickiest retention feature.
4. **Distribution & ecosystem** — signed install exists with **no registry, no directory, no theme
   gallery**; XenForo and Invision both monetize marketplaces. Also: importers half-finished, and the
   embeds story (unique!) is unmarketed.
5. **Intelligence & staff leverage** — zero AI assist; no community-health analytics (first-response
   time, staff workload, topic assignment); no onboarding lifecycle (welcome flows, quests).

### What sets NovFora apart — the four flagship bets

- **Bet 1 — "Runs anywhere" stays sacred** (existing; market it, never break it).
- **Bet 2 — The Registry** (v1.4): first-party signed plugin/theme directory with one-click install. The
  ed25519 trust chain from U17 already exists; the feed can be static JSON (baseline-friendly to *serve* and
  to *consume*). This turns the plugin API into an ecosystem — the thing that made phpBB durable and
  XenForo profitable — while staying open and self-hostable.
- **Bet 3 — Federation** (v1.5 flagship): **the first PHP forum to ship ActivityPub**, ahead of Flarum's
  funded plan, aligned with ForumWG. Perfect brand fit ("Indie Web Hearth"): follow a NovFora forum from
  Mastodon, replies federate in through the moderation queue. NodeBB proved demand; PHP hosting reach ×
  federation is a combination nobody occupies.
- **Bet 4 — Nova Assist, privacy-first AI** (v1.5): optional, **BYO-key, provider-agnostic (incl. local
  Ollama), off by default, per-feature content-privacy opt-in** (the ADR-0069 fence generalized). Summaries,
  related topics, report-queue triage scoring (HOLD-only, mirroring ADR-0067's posture). The contrast story
  writes itself: Discourse's AI pushes you to their hosting; NovFora's AI is yours.

Supporting differentiators woven through the releases: **community-health & recognition** ("Hearth
metrics": first-response time, expert surfacing, quest-lite onboarding) and **the embeds story** (grow U7
into a "your community across your whole site" pitch — WordPress embed plugin as a registry showcase).

---

## Part 2 — The releases

> Sizing: S ≈ days, M ≈ 1–2 weeks, L ≈ multi-week program. ◆ = apex (adversarial review before merge;
> model routing per `CLAUDE.md` — ultracode, don't pin rungs in specs). ADR numbers: next-free is **0109**;
> allocations below are suggestions — confirm next-free at each lift.

---

## v1.3 — "The Member Release" (Polish & Presence)

**Goal:** demo-to-demo competitive with Invision 5 on front-of-site feel; engagement loops complete.
**Closes gaps 1 + 2.** Sources: UI/UX project (UX-1…7, NOV-90…96) + U-series Tier 1 (NOV-100/101/102/104/105).

### Phase 3A — Foundation & hygiene ◆ (the correctness spine — do first)
- **Permission-aware UI contract** (UX-7 / NOV-96) ◆ — every action resolves to show / show-disabled-with-
  reason / sign-in CTA / hide / friendly-403 through one helper/component API over `canDo`. Kills the
  ghost-UI bug class (BETA-4's family) permanently. *ADR-0109.*
- **Engine hygiene slice** ◆ — close the two known doors: last-plain-admin guard in
  `GroupManager::removeMember`; decide + implement the `GroupPermissionEditor` fan-out cascade or formally
  accept the 30-day bound. *Extends ADR-0086/0087.*
- **CI completion** — Dusk green in CI (the four morning-report specs + mobile-header journeys);
  `route:clear` in the gate; composer-audit bump; asset-budget re-baseline.
- **Gate 3A (exit):** contract adopted on topic/thread + profile surfaces; Dusk in CI green; both parked
  decisions (U8 revert rule, U18 Turnstile posture) decided by owner.

### Phase 3B — Front-of-site redesign (Cursor-led per the multi-agent model)
- UX-1 forum index (card layout) · UX-3 nav/search polish · UX-4 topic-list & thread-view ·
  UX-5 auth/register · UX-6 component-system refinement (`x-ui.*` extraction, timestamp standardization).
- All view-layer; the contract from 3A governs every action affordance. No new JS deps; token system only.
- **Milestone M3B-1:** index + nav shipped to demo (backup-first) → beta feedback round.
- **Gate 3B (exit):** a11y gate grown over every redesigned surface (30 → ~36); 390px Dusk journeys green;
  HotPath budgets hold.

### Phase 3C — Engagement core
- **U1 multi-quote** (M, NOV-100) — extends shipped quote-reply with selection basket + attribution depth.
- **U2 follow forums/tags + Watched surface** (M, NOV-101) — extends M2 subscriptions; "Watched" tab
  becomes the member home loop. Fan-out stays bounded + queued (the ADR-0097 fence).
- **U4 announcements/notices** (M, NOV-102) — dismissible, criteria-targeted banners.
- **Gate 3C (exit):** notification volume sane under follow fan-out (budget test); digest includes watched
  content; demo upgraded.

### Phase 3D — Community surfaces & onboarding seed
- **U3 profile wall / status posts** (L, NOV-105) **merged with UX-2 profile redesign** (NOV-91) — one
  program, one surface: tabs + hero stats + wall. The wall is a new content type → rides the canonical→
  sanitize pipeline + moderation queue + ignore lists. ◆-lite (permission-scoped Posts tab query). *ADR-0110.*
- **U6 front-of-site moderator toolset** (L, NOV-104) — inline mod menu + stored replies (T1 engine),
  governed by the 3A contract.
- **Onboarding lite** (S–M, differentiator seed) — welcome email flow (T2 template engine) + a dismissible
  new-member checklist (first post, follow a forum, complete profile → first badges). Quest-lite, no new
  gamification engine. *ADR-0111.*
- **Gate 3D / v1.3 release gate:** full suite + Dusk + a11y green; reversible-migration check (wall,
  follows); release zip verified; demo soak; **tag v1.3.0**.

---

## v1.4 — "The Creator Release" (Make It Yours)

**Goal:** theming depth to XenForo class + the ecosystem flywheel + admin at scale. **Closes gap 4, half of
gap 5.** Sources: U-series Tier 3 (NOV-107…110) + Tier 4 (NOV-111/112/114/116) + net-new Registry.

### Phase 4A — Style engine
- **U9 rich style-property system** (L, NOV-107) — grouped, typed style props with live preview on the
  ADR-0037/0038 sandbox (today ~7 tokens).
- **U10 multi-style tree** (L, NOV-108) — parent/child inheritance + per-user style chooser.
- **Gate 4A:** two shipped presets (light/dark descendants) + a child theme built entirely in the ACP.

### Phase 4B — Upgrade-safe customization ◆ (the release's apex centerpiece)
- **U11 template hook layer + Diff3 merge** (L, NOV-109) ◆ — targeted template modifications that survive
  upgrades (three-way merge, conflict surfacing), replacing whole-file override as the only tool. On the
  sandbox = untrusted-template surface → full adversarial review. *ADR-0112.*
- **Gate 4B:** proof: a themed + template-modded install upgrades across a simulated core release with
  mods intact; conflicts surface in ACP, never fatal.

### Phase 4C — Distribution: import/export + **the NovFora Registry** ◆
- **U12 style import/export** (M, NOV-110) — zip + manifest + global custom-CSS box. The export format *is*
  the registry's theme format.
- **Registry v1** (L, net-new) ◆ supply-chain surface — static signed JSON feed (novfora.com; mirrors
  allowed) listing plugins + themes with ed25519 publisher keys chaining into the U17 `module_trust_keys`
  registry; ACP "Browse" surface with one-click install of **signed** packages; unsigned = never listed.
  Server side is a static file — nothing to run; client side rides `ArchiveGuard`/`PackageSignature`
  untouched. *ADR-0113.* **Spike first** (feed schema + key-revocation story) per spike discipline.
- **Importer completion** (M) — finish MyBB + SMF importers (ADR-0034 scaffolds) + "Switch to NovFora"
  migration guides. The adoption lever for the registry era.
- **Gate 4C:** end-to-end: export a theme → publish to a test feed → one-click install on a fresh install →
  survives upgrade. Importer round-trip on real MyBB/SMF dumps.

### Phase 4D — Admin at scale + staff workflow (the Invision-parity pass)
- **U14 registration controls** (M, NOV-112) — approval queue, ToS/age gate, domain allow/deny; **includes
  the pending-member exit-ramp fix** (the parked "Dan" false-flag spec).
- **U13 IP investigation + ban management** (M, NOV-111) · **U16 maintenance/logs/mail-test ACP** (M,
  NOV-114) · **U19 custom topic fields + move-with-redirect** (M, NOV-116).
- **Staff workflow & Hearth metrics v1** (M–L, net-new differentiator) — topic assignment (assign a topic/
  report to a moderator or team, workload view) + community-health dashboard: first-response time, unanswered-
  topics %, staff response load, member retention cohort — extends the T3 sparkline analytics, real signals
  only. *ADR-0114.*
- **Gate 4D:** full gates + demo soak; registry feed live with ≥ 3 first-party packages
  (Q&A module, one theme, one plugin).

### Phase 4E — Admin API + Populate ◆◆ (added 2026-07-02 — owner-approved)
- **The Admin API** — scoped admin tokens (scopes ∩ `canDo`, no super-scope) over settings, structure,
  members, moderation, maintenance, **full backup (DB + uploads) + staged restore** ◆◆, with OpenAPI 3.1,
  idempotency keys, and full audit. Backend-only; may run parallel to 4A–4C (low collision).
- **Self-upgrade** ◆◆ — one-click **"Upgrade" pulling from GitHub Releases** (`getnovfora/novfora`; daily
  cached check + version picker over tags), or **manual signed-zip upload** to pin a specific version:
  staged verify (ed25519 core-release signature is the only trust root — GitHub is transport) →
  layout-aware swap → the existing RH-10 `UpgradeRunner` (backup-first, migrate, resume-on-kill) → health
  check, with retained-release rollback. Auto-apply policy off/security-only/all-patches. Closes the last
  no-SSH gap — code deployment itself.
- **Populate** (**private plugin — NOT in mainstream releases**) — the ForumGen integration lives in a
  separate private repo, owner-signed, installed via U17 only where the owner personally grants it: plan
  import (backdated segment via the ADR-0034 importer infra) + a **drip engine** publishing future-dated
  content through the real domain services on real-forum pacing (diurnal curve, reply ratio, thread decay).
  Reversible by design (plugin-owned provenance tables + full purge + clean uninstall), live-board fences
  (caps, kill switch, HOLD mode, optional bot flair). Core gains only generic seams. Native LLM generation
  follows in v1.5 inside the same private plugin (shared Nova Assist providers + egress fence).
- Full spec: [`ADMIN-API-AND-POPULATE-SPEC-2026-07-02.md`](ADMIN-API-AND-POPULATE-SPEC-2026-07-02.md)
  (slices E1–E7, apex ledger, exit gate).
- **Gate 4E / v1.4 release gate:** the spec's end-to-end proof (token → structure → backup → restore →
  seed → drip → purge) + all 4A–4D gates; **tag v1.4.0**.

---

## v1.5 — "The Network Release" (Reach & Intelligence)

**Goal:** the two moonshot differentiators, shipped behind flags, plus the last scale items. **Closes gaps
3 + 5; makes federation + AI the headline.** Everything off-by-default, progressively enhanced.

### Phase 5A — Federation (ActivityPub) ◆◆ — the flagship
**Spike first — GO/NO-GO gate** (protocol surface, ForumWG alignment, HTTP-signature library choice under
Apache-2.0, baseline-tier delivery model). Then, if GO, phased:
1. **F1 Read-side identity** — actors for forums + users (WebFinger, actor documents, outbox of
   guest-visible topics). Reuses the U7 no-leak fence verbatim: `User::guest()` + `VisibleForumIds`,
   404-indistinguishable denials, guest-visible content only.
2. **F2 Follow + fan-out** — remote follows; new-topic Create activities delivered via the cron-drained
   queue (bounded + idempotent — the digest transactional-claim discipline; Redis worker on Enhanced).
3. **F3 Inbound replies** — federated replies land **HOLD-only in the moderation queue** (the ADR-0067
   posture: intelligence suggests, humans decide), full spam-pipeline pass, per-instance allow/deny +
   defederation controls in ACP.
- ◆◆ apex throughout: untrusted signed HTTP from the internet, forgery, spoofed actors, queue idempotency.
  Adversarial review per sub-phase, not per release. *ADR-0115 (program) + children.*
- **Gate 5A:** two NovFora instances federate topic + reply end-to-end; a Mastodon account can follow a
  forum and reply; the moderation fence proven adversarially; **off by default**, one-toggle enable.

### Phase 5B — Nova Assist (AI module) ◆
- First-party **module** (proves the plugin API at depth), BYO-key, provider-agnostic drivers (OpenAI /
  Anthropic / **local Ollama**), per-feature content-privacy opt-in (ADR-0069 fence generalized), every
  feature with a non-AI fallback:
  - **Topic summaries** (on-demand, cached, labeled).
  - **Related topics** — embeddings via Meilisearch vector store on Enhanced; keyword/tag fallback on
    Baseline (the progressive-enhancement rule applied to relevance).
  - **Report-queue triage scoring** — HOLD-only ranking assist for the mod queue; never auto-acts.
  - **Moderator draft-assist** — suggested replies seeded from canned replies; human sends.
- ◆ apex: prompt-injection into mod surfaces, PII egress fences, key handling. *ADR-0116.*
- **Gate 5B:** module installs from the Registry; all features function-off degrade cleanly; privacy matrix
  documented per feature.

### Phase 5C — Scale & completion
- **U15 mass member ops + bulk mail/newsletter** (L, NOV-113) ◆ — the last apex U-series item; reuses the
  bounded+queued fan-out + suppression lists.
- **U21 i18n sweep finish** (L, NOV-117) + **community locale kits** — pair with a translation
  call-to-community (registry distributes language packs).
- **U20 parked SEO residue** — social-card asset, tag/club OG, `site_name` unification (ADR-0108 list).
- **Gate 5C / v1.5 release gate — the VALIDATE-BEFORE-GO-LIVE burn-down:** at-scale load test (k6 vs the
  ADR-0074 SLOs), live Stripe (if commerce enabled), OAuth/SAML live validation, Web Push live, manual a11y
  residue. Federation + AI stay optional so none of these block the tag if the owner defers a service.
  **Tag v1.5.0.**

---

## Part 3 — Sequencing rationale & operating notes

**Why this order.** v1.3 fixes the perception gap first (an evaluator's first five minutes), on surfaces the
audits already root-caused — lowest risk, highest visible payoff, and the permission-aware contract must
precede any redesign or we repaint ghost UI. v1.4 builds the moat economics: theming depth makes NovFora
*adoptable by communities that care how they look*, the Registry makes every future module/theme/locale/AI
distribution channel real, and importers open the migration funnel — all prerequisites for v1.5's headline
features to land with an audience. v1.5 then spends the two big differentiator bets (federation, AI) when
there's a polished product and a distribution channel to receive them — and ships them as optional modules so
the Baseline promise and the security posture never bend.

**Linear mapping — MIRRORED 2026-07-02.** Three projects created on team NovFora with milestones per phase:
**"v1.3 — Member Release"** (3A–3D), **"v1.4 — Creator Release"** (4A–4D), **"v1.5 — Network Release"**
(5A–5C). All 22 remaining backlog issues moved in (UX-1…7 = NOV-90…96; U-series per phase above).
Net-new issues created: **NOV-121** engine hygiene ◆, **NOV-122** CI completion (3A), **NOV-123**
onboarding-lite (3D), **NOV-124** Registry spike → **NOV-125** Registry v1 ◆, **NOV-126** importer
completion (4C), **NOV-127** staff workflow + Hearth metrics (4D), **NOV-128** federation spike →
**NOV-129/130/131** F1→F2→F3 ◆◆ (5A, dependency-chained), **NOV-132** Nova Assist spike → **NOV-133**
Nova Assist v1 ◆ (5B), **NOV-134** U20 residue (5C). "Phase 6 — U-series" and "UI/UX" projects marked
Completed/retired with pointers here.

**Apex ledger (Fable-tier adversarial review before merge):** UX-7 contract · engine-hygiene doors · U11
Diff3 hooks · Registry supply chain · **Admin API token scopes (E1) · restore pipeline (E5) · Populate
drip/purge (E6) · self-upgrade swap + core-release signing (E7)** · Federation (every sub-phase) ·
Nova Assist privacy/injection fences · U15 fan-out.
Everything else routes down per `ultracode`.

**Locked decisions — untouched.** Nothing here adds a Baseline hard-dependency (federation delivery and AI
both degrade to cron/DB or off); multi-tenant SaaS, native apps, in-core chat stay out of scope; the module
and theme APIs stay semver'd public contracts (the Registry *strengthens* that promise); strict clean-room
holds — federation is built to the public ActivityPub/ForumWG specs, and no competitor code is ever read.

### Competitive sources (July 2026)
[Discourse AI](https://www.discourse.org/ai) · [Discourse Discover](https://blog.discourse.org/2025/10/discourse-discover-ai-communities-edition/) ·
[XenForo 2.4 preview](https://xenforo.com/community/threads/coming-soon-xenforo-2-4.225302/) · [XF 2.4 status](https://xenforo.com/community/threads/xenforo-2-4-status-and-whats-new-under-the-hood.231562/) ·
[Invision Community 5](https://invisioncommunity.com/features/whats-new-in-5/) · [Invision 2025 year in review](https://invisioncommunity.com/news/invision-community/2025-a-year-in-review-r1326/) ·
[NodeBB 4 federation](https://community.nodebb.org/topic/18545/nodebb-v4.0.0-federate-good-times-come-on) · [NodeBB joins the fediverse](https://wedistribute.org/2025/01/nodebb-officially-joins-fediverse/) ·
[Flarum 2.0 federation plan](https://socialhub.activitypub.rocks/t/flarum-forum-software-2-0-will-have-federation-support/3246) ·
[2026 community-platform trend surveys](https://www.grazitti.com/resource/articles/top-community-engagement-trends-and-why-they-matter/)
