# Phase 1 Plan — Core MVP (plan-before-code)

> **Project:** Hearth (working codename). **Status: DRAFT for owner approval — NO application code is written
> until this plan is signed off.** **Date:** 2026-06-01.
> **Stack (locked, ADR-0001/0002):** Laravel 13 · Livewire 4 · Alpine · Blade · PHP 8.3 · MySQL 8/MariaDB.
> **MVP framing (gate decision):** **Full MVP** — the Lean descopes are *not* applied (see §3).
> **Grounding:** [roadmap](roadmap.md) Phase 1 · [mvp-scope](mvp-scope.md) · [feature-prioritization](feature-prioritization.md)
> · ADRs in [DECISIONS.md](../../DECISIONS.md). This plan is the brief's "plan before each phase" gate.

---

## 1. Goal & definition of done

**Goal:** a genuinely usable forum that **installs and runs on the baseline tier** (a shared PHP host: PHP 8.3
+ MySQL + cron, no SSH required, no daemons) and already embodies Hearth's differentiators — phpBB-grade
permissions, a modern WYSIWYG editor, first-class anti-spam, and no-core-edit theming.

**Definition of done (Phase 1 exit criteria):**
1. **Runs on the baseline tier** end-to-end via the **no-SSH web installer**; one cron line drives everything.
2. **Identical code runs on the enhanced tier** (Redis/Meilisearch/Reverb/S3 present) with no code change —
   service-tier detection wired from the start (ADR-0003).
3. **Tests green**, including the two non-negotiable suites: **permission-mask truth-tables** and
   **service-tier forced-absence/fallback** ([testing-strategy](../architecture/testing-strategy.md)).
4. **CI guards pass:** Pint, PHPStan/Larastan, `composer audit`, Pest, Dusk (editor + core journeys), and the
   **query/asset/performance budgets** (system-architecture §7).
5. **Demo seed + getting-started guide** produce a working community; `.env.example` is current.
6. **Upgrade/restore path proven** on the baseline tier (reversible migrations + a backup/restore rehearsal).

---

## 2. Sequencing — spike first, then build

```
Spike 0  ──►  M0 Skeleton & guardrails  ──►  M1 Identity & access
(editor)        (tier detection, CI,           (auth + 2FA + the
 GO/NO-GO        installer skeleton)             permission-mask engine)
   │                                                   │
   └── gates the editor work in M2                     ▼
                                          M2 Forum structure & content ──► M3 Anti-spam & moderation
                                          (CRUD + canonical storage +        (trust levels on the ACL +
                                           the WYSIWYG editor)                queue + ACP/MCP)
                                                   │                                  │
                                                   ▼                                  ▼
                                          M4 Notifications · search · SEO · theme ──► M5 Operability + runnable milestone
```

**Why this order:** Spike 0 de-risks the #1 technical risk *before* anything depends on it. The
permission-mask engine (M1) is the spine everything else authorizes against, so it precedes forum CRUD (M2).
Anti-spam (M3) reuses the engine (trust levels are ACL groups), so it follows M1/M2. Operability (M5) wraps
the runnable milestone. Milestones overlap where dependencies allow; each lands runnable + tested.

---

## 3. Scope — Full MVP (what's in, explicitly)

You chose **Full MVP**, so the Lean cut-list items are **folded back into Phase 1**. Phase 1 = the roadmap's
core MVP **plus** these un-cut items:

| Lean would have cut… | Full MVP keeps it in Phase 1 |
|---|---|
| #1 Reduced editor node set | **Richer editor:** slash commands, tables, code blocks, spoilers, quotes, advanced embeds |
| #2 Markdown mode → P2 | **Markdown input mode** included (toggle alongside WYSIWYG) |
| #3 One theme, no dark | **→ Deferred to Phase 2 (your trim):** P1 ships **one polished default theme + the Blade override layer**; dark-mode tokens + a 2nd example theme land in P2 |
| #4 Images-only attachments | **Arbitrary file attachments** (typed allowlist) + thumbnailing |
| #5 Basic profiles | **Custom profile fields, signatures, covers** included |
| #8 Keyword-only search | **→ Deferred to Phase 2 (your trim):** P1 ships **keyword search (Scout DB) + unread/“what's new”**; filters/facets land in P2 |
| #9 Cached blocklist only | **Live StopForumSpam API** + cached fallback both included |
| #10 CLI backups only | **Scheduled + admin-UI backups** included |
| #6 Minimal inspector | **Fuller "why can/can't X" inspector UI** included |

**Core Phase 1 surface (in regardless):** auth (register/verify/login/sessions) + **2FA/TOTP for admin/mod
(Must)**; the **permission-mask engine** + roles + groups + trust levels; categories→forums→topics→posts
(server-rendered) with soft-delete/recycle-bin/audit-log; the **WYSIWYG editor** + canonical storage; the
**anti-spam baseline**; moderation queue + ACP/MCP + bans/word-filters/approval workflows; **email + in-app
(polling) notifications**; **Scout DB search** + unread/"what's new"; **SEO basics** (canonical URLs, slugs,
schema.org, OG, sitemap); **mobile-first theme + Blade override layer** + a11y floor; **service-tier
detection**; **no-SSH installer**; **automated backups + reversible migrations**.

**Explicitly NOT in Phase 1 (Phase 2+, per [roadmap](roadmap.md)):** reactions; PMs; rich/digest
notifications; reports; warnings/infractions; inline moderation + cross-page bulk; activity feeds; oEmbed;
drafts/autosave; edit history; the visual point-and-click theme configurator; module/plugin API; REST
API/webhooks; importers; analytics; SSO; monetization; Meilisearch/Reverb/PWA. *(general-user opt-in 2FA is
Should/Phase 2.)*

**Trimmed from Full MVP at your request (→ Phase 2):** (a) the **dark-mode token set + a second example
theme** — Phase 1 ships one polished default theme + the Blade override layer; (b) **search filters/facets** —
Phase 1 ships keyword search (Scout DB) + unread/“what's new”. Everything else in §3 stays in Phase 1.

> **Scope note:** even after the two trims, Phase 1 keeps the richer editor and most of Full MVP — so the
> Spike 0 surface is large, which is exactly why Spike 0 runs first. If timeline pressure appears, the
> remaining §3 items are the first descope lever (surfaced to you, never silent).

---

## 4. Spike 0 — WYSIWYG ↔ Livewire 4 integration (the #1 risk) — **do this first**

> **Build handoff:** [spike-0-handoff.md](spike-0-handoff.md) packages this as deterministic scaffold commands
> + reference code + the six-criteria validation checklist + a GO/NO-GO memo template, for execution in a
> PHP-equipped build environment.

**Objective:** prove the **TipTap (ProseMirror, MIT core) editor integrates cleanly with Livewire 4 via the `wire:ignore` + Alpine
island pattern** before any feature depends on it. This is a focused, throwaway-friendly spike, not production
code; its output is a **GO/NO-GO memo + a reference integration pattern**.

**Intended mechanism (validate this):** mount TipTap inside a **`wire:ignore` boundary** as an **Alpine island**
(the editor DOM is owned by Alpine and excluded from Livewire's morph/diff via `wire:ignore` — this is *not*
Livewire 4's separate "islands" partial-re-render feature), with the **canonical document (TipTap JSON)** synced to
the Livewire component via **explicit, debounced events** — never via Livewire re-rendering the editor.
Server renders canonical → **sanitized HTML** (ADR-0005); the browser never supplies HTML.

**Acceptance / GO criteria — all six must pass:**
1. **State survival:** editor content + cursor/selection survive Livewire round-trips. **(1a, GO-blocker)**
   validation errors + sibling-component updates = zero loss; **(1b, best-effort/documented)** `wire:navigate`
   cursor restoration may require `@persist` and does **not** block GO (drafts/autosave is Phase 2).
2. **Uploads:** drag-drop + paste-to-upload images/files work inside the island, wired to the attachment
   pipeline (chunked, progress, server-side type/size validation).
3. **Editor features:** `@mentions` and slash-commands function within the island.
4. **Round-trip safety:** canonical JSON → server-sanitized HTML → re-edit is **lossless and XSS-safe** (a
   battery of injection payloads is neutralized at render; sanitizer is server-side and allowlist-based).
5. **A11y + mobile:** fully **keyboard-operable** (focus management, visible focus, ARIA), usable on touch.
6. **Budget:** editor island JS **≤ ~180 KB gz**, **lazy-loaded** (not on the critical path); no input jank.

**GO** = all six pass → adopt the **`wire:ignore` + Alpine island** pattern as the standard editor pattern; M2 builds on it.

**Fallback path (NO-GO on the `wire:ignore` + Alpine island pattern), in order of preference:**
1. **Livewire 4 JS-component bridge:** wrap TipTap in a dedicated **Vue or React** component mounted via
   Livewire 4's component bridge, syncing canonical JSON through the bridge's prop/event channel.
2. **Decoupled Alpine island** fully outside Livewire's DOM, syncing canonical JSON to a hidden input the
   Livewire component reads on submit.
3. **Last resort:** a standalone JS editor posting canonical JSON to a dedicated endpoint (still
   server-sanitized). Each fallback keeps the canonical-storage + server-sanitize boundary intact.

**Spike exit:** a short written memo records which mechanism passed, the reference pattern (a documented
component + the sync contract), and any constraints it imposes on M2. **No feature work in M2's editor track
starts until this memo says GO on a specific mechanism.**

**Spike 0 result — GO (2026-06-02).** All six criteria passed with executed evidence (Pest 10 tests / 82
assertions incl. the #4 XSS battery; Playwright 6/6 incl. the #1a state-survival GO-blocker, both paths). No
fallback needed; **ADR-0012 stands.** Memo: [spike-0-memo.md](spike-0-memo.md); reference scaffold under
`hearth-spike/`. **Binding M2 editor implementation notes (carry these forward):**

1. **The editor lives in per-instance closure state — never a reactive Alpine property.** A reactive proxy wraps
   ProseMirror's state and makes programmatic commands throw *"Applying a mismatched transaction."* This is the
   #1 rule for the editor and any self-managing JS widget embedded in Livewire.
2. **Livewire 4 = single-file components** (`⚡`-prefixed `new class extends Component` + Blade in one file under
   `resources/views/components/`), not class-based `app/Livewire/`. Method injection + `$this->validate()` work.
3. **Sync via deferred `$wire.set('canonicalJson', json, false)` with no debounce** — it's JS-only (no network);
   debouncing it caused a stale doc on an immediate save. Debounce only a future *network* autosave/draft.
4. **Async (post-upload) inserts must defer one tick + use `insertContent`**, not a synchronous command after
   `await` (same mismatched-transaction trap).
5. **TipTap 3 StarterKit bundles Link** (and more) — do not re-register it; Placeholder/Mention/Image are
   separate MIT packages.
6. **`CanonicalRenderer` is the security boundary** — JSON→HTML mapper with per-value escaping + a
   symfony/html-sanitizer allowlist backstop; **port it from the spike**
   (`hearth-spike/app/Support/CanonicalRenderer.php`, proven by `CanonicalRendererTest`).
7. **Drag-drop + paste both call one `uploadAndInsert`;** automate the upload→insert pipeline via the file picker
   (synthetic native file-drops are unreliable headless — a test-harness limitation, not an integration gap).

---

## 5. Milestones (each lands runnable + tested on the baseline tier)

**M0 — Skeleton & guardrails.** Laravel 13 + Livewire 4 + Alpine + Blade app; Vite with **prebuilt assets
committed** (no host Node); baseline config (DB cache/session/queue, Scout `database`, `smtp` mail);
**service-tier detection** contracts + detectors + an `Admin → System → Service Tier` panel (ADR-0003);
**reversible-migration baseline** + backup command skeleton; **CI** (Pint, PHPStan, Pest, Dusk, `composer
audit`, query/asset budgets); SPDX headers, conventional commits, DCO; `.env.example`.

**M1 — Identity & access.** Register / email-verify / login / sessions (argon2id), password reset; **2FA/TOTP
for admin & moderator accounts**. The **permission-mask engine** (ADR-0006): `groups` / `acl_entries` /
`roles` schema, three-state **ALLOW/NO/NEVER** resolution across global→category→forum→thread, group merge,
**resolved-mask caching** (>95% hit target) with event-driven invalidation, and the **"why can/can't X"
inspector**. Seeded role presets (admin/mod/member/guest) + **trust-level groups (TL0…)**. **Dedicated
truth-table tests** are part of this milestone's done.

**M2 — Forum structure & content.** Categories→forums→topics→posts, server-rendered, ordered, **per-node
permissions via the engine**; soft-delete + recycle bin + restore; **audit log**; sticky/announcement/
locked/moved. **Canonical content storage** (ADR-0005): canonical + server-sanitized HTML cache + text
projection; the **server JSON→HTML renderer + allowlist sanitizer** (license vetted in DECISIONS/ADR-0015).
The **WYSIWYG editor** integrated via the Spike-0 pattern (+ **Markdown input mode**, richer node set).
**Attachments** (typed allowlist) + thumbnailing (sync/cron). Fragment/response caching for thread views.

**M3 — Anti-spam baseline & moderation (ADR-0007).** Trust levels enforced **through the ACL** (NEVER = hard
gate on true spam vectors, NO = soft gate); **registration blocklist** (live StopForumSpam API + cron-cached
fallback) with confidence thresholds; **CAPTCHA provider abstraction** (Q&A baseline + pluggable); honeypot/
timing; **rate limiting** (DB-backed); disposable-email blocking; **new-user moderation queue** + approval
workflows; **content-scanning contract** (local heuristics now; Akismet provider in Phase 2); **Spam
Cleaner**; user/IP/email/range **bans** + word filters; **ACP + MCP** baseline. Anti-spam + permission tests.

**M4 — Notifications · search · SEO · theme.** **Email notifications** (queued via cron; provider abstraction
+ self-test + best-effort-baseline note, ADR-0014) and **in-app notifications** (polling, merge-aware). **Scout
DB search** (MySQL FULLTEXT) + inline predictive results + **unread/"what's new"**
watermark. **SEO:** canonical URLs + human slugs + **schema.org `DiscussionForumPosting`** + OG + XML sitemap
with `noindex` of empty containers. **Theme foundation:** mobile-first default theme + **Blade override layer** (ADR-0009) + **a11y floor**
(contrast + keyboard validation) *(dark mode + a 2nd example theme → Phase 2)*.
Custom profile fields, signatures, covers.

**M5 — Operability & the runnable milestone.** **No-SSH web installer** (tier detection, DB setup, admin
account, writable-path checks) + Composer path; **automated backups** (scheduled + admin UI) + restore;
health checks; **reversible-migration upgrade rehearsal** on the baseline tier; **demo seed** + **getting-
started guide**; finalize `.env.example`; enforce **performance budgets** (system-architecture §7) in CI.

---

## 6. Testing & quality gates (per [testing-strategy](../architecture/testing-strategy.md))

- **Permission-mask truth-tables** (ALLOW/NO/NEVER × scope chain × group merge) — exhaustive.
- **Service-tier forced-absence tests** — Redis refused, Meilisearch 503, Reverb unreachable, S3 error →
  assert graceful fallback to baseline drivers, never an error.
- **Dusk** journeys: the editor (the Spike-0 acceptance battery, automated), register→post→moderate,
  install-wizard happy path.
- **Security:** XSS payload battery on render; authorization tests on every gated action; `composer audit`.
- **Budgets in CI:** query counts per page (no N+1), asset sizes, p95 render targets.
- **"No feature is done without tests"** — enforced by the PR checklist (GOVERNANCE §3).

---

## 7. Risks & mitigations

| Risk | Mitigation |
|---|---|
| Editor ↔ Livewire integration (the #1 risk) | **Spike 0 first** with a hard GO/NO-GO + three documented fallbacks before M2 builds on it |
| Permission-engine correctness | Truth-table tests + the inspector as a debugging surface, built **with** the engine in M1 |
| No-SSH installer across diverse shared hosts | Writable-path/permission probes; a host-compatibility checklist; test on ≥2 real shared hosts |
| Coarse-cron correctness | Every async job **idempotent + correct within one cron interval**; no sub-minute assumptions |
| Sanitizer/JSON-renderer licensing | Prefer MIT (`symfony/html-sanitizer`); vet + record in DECISIONS.md before merge (ADR-0015) |
| Full-MVP scope creep | Milestones land independently runnable; if timeline slips, §3 un-cut items are the first descope lever (re-surface to you, never silent) |

---

## 8. Approval

This plan needs your sign-off before any code. On approval I will:
1. Execute **Spike 0** and return its **GO/NO-GO memo** before building the editor into M2.
2. Proceed through M0→M5, each landing **runnable + tested on the baseline tier**, with small reviewable
   conventional commits.
3. Keep `.env.example`, seeds, and the getting-started guide current at every milestone.

**Open question for you (optional):** Full MVP is the larger scope — happy to proceed as written, or tell me
if any §3 un-cut items should move to Phase 2 before we start.
