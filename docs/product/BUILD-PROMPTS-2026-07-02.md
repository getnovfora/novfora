<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# NovFora — Build Prompts (2026-07-02)

> **Status ledger (validated 2026-07-03):**
> **Prompt 0 ✅ EXECUTED** — Populate plugin built + gated green (plugin suite 83/83; compressed-clock E2E;
> U17 signed-install dogfood; E6a apex review found **3 HIGH, all fixed**). Core seams branch (ADR-0120,
> Module API 1.2) **merged to local `main` (`6724a9a`)**; plugin repo `D:\novfora-populate` (11 commits);
> junction working tree git-excluded. Linear NOV-140/NOV-143 → In Review.
> **Owner residue from Prompt 0:** push `origin main` (+ `v1.2.0` tag if not yet pushed) · **revoke the
> old Gemini key in `F:\ForumGen\generate_forum.py`** · keygen + sign + distribute the plugin zip ·
> Dusk in CI for the Studio panes · flip NOV-140/143 → Done.
> **Prompts 1–10: not started.** Next: **Prompt 1** (v1.3 Phase 3A).

> **How to use.** Copy one prompt at a time into Claude Code (in the build environment), in order.
> Each prompt is self-contained but assumes the repo's standing docs. **Run Prompt 0 first** (owner
> decision: the forum generator ships first, usable on the current release). Wait for each run's
> morning report + owner review/merge before starting the next.
>
> **Standing rules every prompt inherits (do not restate per-prompt):** read `CLAUDE.md` +
> `PROJECT-STATE.md` first · plan-before-code, STOP for approval where the prompt says so · independent
> branch per slice off `main`, committed only at a fully-green gate boundary (Pest/Pint/PHPStan/migrate;
> Dusk CI-pending where no Chrome) · commit identity `Tommy Huynh <tommy@saturnhq.net>`, DCO `-s`, no AI
> trailers · **never push to protected `main`** — end at "merged locally + tagged where instructed, owner
> pushes" · apex (◆) slices get the adversarial verify-then-refute review before merge — **Fable @ max
> preferred, Opus 4.8 fallback permitted** (Fable errors in the build env) · update Linear (team NovFora)
> as you go; if the harness blocks a write, list the pending moves in the morning report · finish every
> run by updating `PROJECT-STATE.md` with a morning report.

---

## Prompt 0 — Populate plugin (E6a + E6b) — BUILD NOW, against current `main`

```
Read CLAUDE.md, PROJECT-STATE.md, docs/product/ADMIN-API-AND-POPULATE-SPEC-2026-07-02.md §0 and §4,
and F:\ForumGen (generate_forum.py is the reference for personas + pacing; ImportForumSeedCommand.php
shows the raw import we are REPLACING with the proper importer infra). Linear: NOV-140 (E6a), NOV-143 (E6b).

Build the **Populate private plugin** — engine + Studio — against current main (v1.2.x). This is a
PRIVATE plugin: it lives in its own directory `../novfora-populate` (its own git repo, NOT committed to
the novfora repo), is packaged + signed with `novfora:module:sign`, and is test-installed through the U17
ACP install-from-zip path (dogfood it). It must NEVER appear in the release zip, the public repo, or any
public feed. Plan first; STOP for my approval before writing code.

Scope (spec §4 is authoritative):
1. E6a engine ◆ APEX: module-owned schema (populate_plans, populate_users provenance map,
   populate_events; module migrations, reversible); plan import (versioned ForumGen-superset JSON,
   bounded validation, content through the canonical→sanitize pipeline, sim email domain enforced,
   backdated segment through the ADR-0034 importer infra so counters stay true); drip runner as a
   module-registered cron task (transactional claim per the digest discipline, withoutOverlapping,
   idempotent mid-kill) publishing through the real PostService/TopicService; pacing engine ported from
   generate_forum.py (diurnal curve, weekday weighting, reply:new-topic ratio, thread-decay window,
   jitter) extended with curve presets (ramp/steady/decay/S-curve), quiet periods, event spikes;
   fences default-on (daily cap, kill switch, pause/resume, HOLD-into-modqueue mode, outbound-mail
   suppression for sim users, optional bot flair via the v3-g display seam, stats-exclusion setting);
   purge = complete reversal of everything a plan created; clean uninstall leaves core schema untouched;
   simulated accounts can never log in (unusable hash + login refusal) and never hold staff capabilities.
   If core lacks a generic seam you need (e.g., an outbound-mail suppression filter hook), add it to core
   as a GENERIC hook on a separate small branch of the novfora repo, flagged in the report.
2. E6b Studio (same plugin): the four ACP panes per spec §4 — Persona Studio (CRUD archetypes seeded
   from the script's 10, persona-pack import/export), Timeline & Pacing Designer (live preview:
   projected posts/day sparkline + sample 48h schedule), Content Controls, Run Console. Studio authors a
   versioned genconfig; export/import as JSON (manual round-trip with generate_forum.py --config for
   now; the Admin-API round-trip comes later with E1). Gate the whole surface behind admin.access +
   staff-2FA. Reuse x-ui.* components; no new JS deps.
3. Update generate_forum.py in ../novfora-populate to accept --config genconfig.json (personas, lengths,
   timeline, forums from config instead of hardcoded constants) and to emit the versioned plan schema.
4. Tests: adversarial specs for the ◆ surfaces (hostile plan JSON, entity floods, drip double-claim,
   mid-kill republish, purge completeness incl. counters + search index, sim-account login/capability
   refusal) + a compressed-clock end-to-end (import → 48h drip via simulated ticks → purge → byte-equal
   counters). The plugin's suite runs green with the module installed AND the core suite stays green
   with it absent.
5. Adversarial verify-then-refute review on E6a before packaging. Sign the zip; verify install +
   clean uninstall through the ACP U17 path on a throwaway install.

Deliverables: ../novfora-populate repo (plugin + script + README + plan/genconfig schema docs), signed
zip, any small generic-seam branch on the novfora repo, Linear NOV-140/NOV-143 moves, morning report.
```

---

## Prompt 1 — v1.3 Phase 3A · Foundation & hygiene ◆

```
Read CLAUDE.md, PROJECT-STATE.md, and docs/product/ROADMAP-V1.3-V1.5-2026-07-02.md (v1.3 Phase 3A).
Linear: NOV-96, NOV-121, NOV-122. Plan first; STOP for approval.

Build three slices off main:
1. NOV-96 permission-aware UI contract ◆ APEX: one helper/component API over canDo resolving every
   action to show / show-disabled-with-reason / sign-in CTA / hide / friendly-403; adopt it on the
   topic/thread + profile surfaces this pass; friendly-403 copy through i18n. ADR (next-free).
2. NOV-121 engine hygiene ◆ APEX: last-plain-admin guard in GroupManager::removeMember (route through
   the shared ban-aware authority like the S5 siblings); resolve the ADR-0087 GroupPermissionEditor
   fan-out gap (wire cascadeForActor or formally accept + document the 30-day bound in an ADR).
3. NOV-122 CI completion: Dusk green in CI (all accumulated CI-pending specs), route:clear in the gate,
   composer-audit guzzlehttp bump, asset-budget re-baseline.
Present the two parked decisions (U8 imported-username revert; U18 Turnstile posture) with a
recommendation each in the morning report — do not implement either without my answer.
```

## Prompt 2 — v1.3 Phase 3B · Front-of-site redesign

```
Read the roadmap (v1.3 Phase 3B) + docs/product/DEFINITIVE-ROADMAP-2026-06-27.md §2 Track UX.
Linear: NOV-90, NOV-92, NOV-93, NOV-94, NOV-95. Plan first; STOP for approval.

View-layer redesign in the existing token system (warm indie web + premium SaaS clarity + classic
forum density; never a Discourse clone): UX-1 forum index cards, UX-3 nav/search, UX-4 topic-list +
thread view, UX-5 auth screens, UX-6 x-ui.* component extraction + timestamp standardization. Every
action affordance goes through the Phase-3A permission-aware contract. No new JS deps, no PHP/data
changes except where a slice explicitly allows. Grow the a11y gate over every redesigned surface and
add 390px Dusk journeys; HotPath budgets must hold. Deploy-to-demo checkpoint after UX-1+UX-3 (stop
and tell me; I deploy backup-first).
```

## Prompt 3 — v1.3 Phase 3C · Engagement core

```
Read the roadmap (v1.3 Phase 3C). Linear: NOV-100, NOV-101, NOV-102. Plan first; STOP for approval.
Three slices: U1 multi-quote (extend shipped M1 quote-reply: selection basket + attribution depth,
canonical→sanitize, no raw HTML); U2 follow forums/tags + a Watched surface (extend M2 subscriptions;
bounded+queued fan-out per ADR-0097; digest integration); U4 announcements (dismissible, criteria-
targeted, finish the half-wired type). Notification-volume budget test under follow fan-out.
```

## Prompt 4 — v1.3 Phase 3D · Community surfaces + onboarding → release v1.3.0

```
Read the roadmap (v1.3 Phase 3D). Linear: NOV-105 + NOV-91 (ONE program — profile redesign + wall),
NOV-104, NOV-123. Plan first; STOP for approval.
Slices: profile redesign (tabs/hero) + profile wall/status posts ◆-lite (permission-scoped Posts-tab
query; wall content through canonical→sanitize + moderation + ignore lists; ADR); U6 front-of-site
moderator toolset (inline mod menu + T1 stored replies, governed by the 3A contract); onboarding-lite
(welcome email flow on the T2 engine + dismissible new-member checklist wired to BadgeService; ADR).
Then the v1.3.0 release run: merge the green v1.3 slices in dependency order, re-gate the union, bump
version, build + verify the release zip, tag v1.3.0 locally. I push and deploy.
```

## Prompt 5 — v1.4 Phases 4A + 4B · Style engine + template hooks ◆

```
Read the roadmap (v1.4 Phases 4A/4B). Linear: NOV-107, NOV-108, NOV-109. Plan first; STOP for approval.
4A: U9 rich style-property system (typed/grouped props, live preview, on the ADR-0037/0038 sandbox);
U10 multi-style tree (parent/child inheritance + per-user chooser). Exit proof: a child theme built
entirely in the ACP. 4B ◆ APEX: U11 upgrade-safe template hook layer + Diff3 merge (ADR-0112) — the
apex centerpiece; exit proof: a themed + template-modded install upgrades across a simulated core
release with mods intact, conflicts surfacing in the ACP, never fatal.
```

## Prompt 6 — v1.4 Phase 4C · Export + Registry + importers ◆

```
Read the roadmap (v1.4 Phase 4C) + the U17 ADR-0104. Linear: NOV-110, NOV-124, NOV-125, NOV-126.
SPIKE FIRST (NOV-124): registry feed schema, publisher-key enrollment/rotation/REVOCATION, threat
model (tampering, mirror substitution, downgrade, typosquatting) → docs/product/spike-registry-memo.md
with GO/NO-GO. STOP for my approval of the memo before building.
Then: U12 style import/export (zip+manifest — the export format IS the registry theme format) →
Registry v1 ◆ APEX (static signed JSON feed; ACP Browse + one-click install of SIGNED packages only,
riding ArchiveGuard/PackageSignature untouched; ≥3 first-party packages: the Q&A module, one theme,
one plugin) → MyBB + SMF importer completion to the phpBB/XenForo standard (clean-room: read source
DB/output structure ONLY) + migration guides.
```

## Prompt 7 — v1.4 Phase 4D · Admin at scale + staff workflow

```
Read the roadmap (v1.4 Phase 4D) + docs/product/pending-member-review-kickoff.md. Linear: NOV-112,
NOV-111, NOV-114, NOV-116, NOV-127. Plan first; STOP for approval.
Slices: U14 registration controls (approval queue, ToS/age gate, domain allow/deny — includes the
pending-member exit-ramp fix); U13 IP investigation + ban management (elevated review); U16
maintenance/rebuild + logs + mail-test ACP; U19 custom topic fields + move-with-redirect (wire
moved_to_topic_id); staff workflow + Hearth metrics v1 (topic/report assignment + workload view on
moderator_assignments; health dashboard: first-response time, unanswered %, staff load, retention
cohort — extends T3 charts, REAL signals only; ADR).
```

## Prompt 8 — v1.4 Phase 4E · Admin API + Self-upgrade → release v1.4.0

```
Read docs/product/ADMIN-API-AND-POPULATE-SPEC-2026-07-02.md IN FULL (it is authoritative) +
PROJECT-STATE.md. Linear: NOV-135..NOV-139, NOV-141, NOV-142. Plan first; STOP for approval.
Build in slice order E1 → E2 → E3 → E4 → E5 → E7 → E8 (E6a/E6b already shipped as the private plugin —
now add the thin Populate API round-trip endpoints behind E1's scopes and update the script):
E1 ◆ scoped tokens (scopes ∩ canDo, no super-scope, co-owner-only restore/upgrade scopes, 2FA-gated
mint, audit, Idempotency-Key, OpenAPI 3.1); E2 read surface; E3 ◆ write surface (existing domain
services ONLY — review proves no bypass); E4 ◆ backups (DB+uploads, resumable, streamed download,
schedule/retention, optional encryption); E5 ◆◆ restore (staged upload→verify→dry-run→execute,
auto-snapshot, rollback — introduces StagedArtifact); E7 ◆◆ self-upgrade (core-release ed25519 signing
in build-release.sh + .sig release assets; GitHub Releases channel + version picker + manual zip;
layout-aware swap on RH-4 A/B/C; hand-off to the untouched RH-10 UpgradeRunner; retained-release
rollback; upgrade_runs state machine); E8 docs.
Exit: the spec §5 end-to-end proof (including GitHub-mock + manual-zip upgrade → rollback → re-upgrade
on all three layouts). Then the v1.4.0 release run: merge all green v1.4 slices in order, re-gate,
bump, build + verify the zip, tag v1.4.0 locally. I push and deploy.
```

## Prompt 9 — v1.5 Phase 5A · Federation (ActivityPub) ◆◆

```
Read the roadmap (v1.5 Phase 5A). Linear: NOV-128 (spike), NOV-129/130/131 (F1→F2→F3).
SPIKE FIRST (NOV-128): protocol surface (ForumWG/NodeBB conventions, Group actors, minimum interop =
Mastodon follow+reply and NodeBB thread exchange), HTTP-signature approach under Apache-2.0-compatible
licensing, cron-baseline delivery model, moderation/privacy fences, F1–F3 effort map →
docs/product/spike-federation-memo.md with GO/NO-GO. STOP — I decide GO.
On GO, build F1 → F2 → F3 per the issue specs, one adversarial review PER SUB-PHASE (not per release):
F1 read-side actors/WebFinger/outbox (U7 no-leak fence verbatim, 404-indistinguishable, off by
default); F2 remote follows + signature-verified inbox + bounded/idempotent cron-drained delivery
fan-out; F3 inbound replies HOLD-ONLY through the moderation queue + per-instance allow/deny +
defederation controls. Exit: two NovFora instances federate topic+reply end-to-end; a Mastodon account
follows a forum and replies; every fence proven adversarially.
```

## Prompt 10 — v1.5 Phases 5B + 5C · Nova Assist + scale/completion → release v1.5.0

```
Read the roadmap (v1.5 Phases 5B/5C). Linear: NOV-132, NOV-133, NOV-113, NOV-117, NOV-134.
SPIKE FIRST (NOV-132): Nova Assist provider abstraction (OpenAI/Anthropic/local Ollama, BYO-key),
privacy-fence design (per-feature content-egress opt-in, ADR-0069 generalized), v1 feature cut,
prompt-injection threat model → docs/product/spike-nova-assist-memo.md, GO/NO-GO. STOP — I decide GO.
On GO: NOV-133 ◆ Nova Assist v1 as a first-party SIGNED module (summaries · related topics with Meili
vectors on Enhanced + keyword fallback on Baseline · HOLD-only report triage scoring · moderator
draft-assist; every feature independently toggleable with a non-AI fallback; privacy matrix in docs).
Populate Phase B rides the same drivers INSIDE the private plugin (native generation from the Studio's
genconfig; reactive mode behind the egress opt-in, HOLD default) — separate branch in ../novfora-populate.
Then 5C: U15 ◆ mass member ops + bulk-mail (bounded+queued fan-out + suppression lists), U21 i18n
sweep finish + locale-pack format, U20 SEO residue (ADR-0108 list).
Then the v1.5.0 release run: merge, re-gate, bump, build + verify, tag v1.5.0 locally. Report the
remaining VALIDATE-BEFORE-GO-LIVE burn-down items (load test, live Stripe, OAuth/SAML, Web Push,
manual a11y) as my checklist — they gate go-live, not the tag.
```

---

*Sequencing: Prompt 0 now (generator first, per owner). Prompts 1–4 = v1.3 (tag v1.3.0). Prompts 5–8 =
v1.4 (tag v1.4.0; Prompt 8 also wires Populate's API round-trip). Prompts 9–10 = v1.5 (tag v1.5.0).
Each run ends with owner review, owner push, backup-first demo deploy per
`docs/product/live-deploy-kickoff.md`.*
