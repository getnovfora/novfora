# NovFora — Claude Fable Build Session: **U7 + U17** (both ◆ APEX)

> Executable session brief. Read it fully, then read the source docs below **before writing any code.**
> You are Claude Fable @ max — the apex rung. The run clears two small blockers first (Phase 0), then
> builds two ◆ APEX features (U7, U17) where the apex muscle is actually required.

## 0. Read first (in this order)
1. `CLAUDE.md` (operating contract) and `@docs/PROJECT-BRIEF.md`
2. `PROJECT-STATE.md` (current handoff state)
3. `ROADMAP.md`, `docs/product/DEFINITIVE-ROADMAP-2026-06-27.md`, `docs/product/feature-list.md` (Part 2 — U-series: read the U7 and U17 entries in full)
4. `DECISIONS.md` (recent ADRs; the plugin/module API is a semver'd public contract)
5. Linear issues: **NOV-106 (U7)** and **NOV-115 (U17)** — https://linear.app/novfora (team NOV)

## 1. Where the repo is (verified 2026-07-01)
- `main` **== `origin/main`** at `658508d`; version bumped to **1.1.0**. Working tree is clean-in-sync at the commit level.
- Shipped since the June-27 roadmap: v1.x Feature Program (complete), **U5** admin nav manager (`NOV-103`, PR #50), **BETA-5** scheduled-publish fix (`NOV-89`, PR #51), #48 brand theme, #49 admin member-directory PII.
- **Ignore this working-tree scratch** (owner's call — leave alone): `docs/NOVFORA-multi_sonnet.md`, `docs/NovFora_MultiAgent_Workflow.md`, `docs/product/GPT_UIUX_HANDOFF.md`, `brand/site-preview/`, and `novfora-docs/` (a *separate nested git repo* — never stage it). There is also one stray edit trapped in a dead file — see NOV-120 below.

## 2. Model routing for this session
- **Default `ultracode`**: start at Fable @ max, downgrade only for clearly pattern-replication scaffolding once a design is locked.
- **Both U7 and U17 are ◆ APEX** — stay at **Fable @ max** for all correctness-load-bearing reasoning: untrusted-input boundaries, CSP/cross-origin, zip/signature/trust, idempotent install/rollback. Do **not** pin a lower rung in advance.

## 3. Working discipline (non-negotiable)
- **One independent branch per slice off `main`.** Use the Linear branch names: `echofivetech/nov-106-…`, `echofivetech/nov-115-…`.
- **Gate green before every commit** — Pest, Pint, Larastan, `migrate` on the Baseline MySQL tier, Dusk, a11y. Gates are the correctness signal, not the model. Cap output (`tail -n N`). Prefer *write → run gate → read tail → fix*.
- **Commit identity:** author **and** committer `Tommy Huynh <tommy@saturnhq.net>`; sign off `-s` (DCO); **no AI co-author/attribution trailers**. Small, reviewable conventional commits.
- **Nothing auto-pushed.** Stop at a green boundary; the owner reviews and merges.
- **Adversarial verify-then-refute review before proposing merge** — per-finding, for each apex feature. Record HIGH/MED findings and their fixes.
- **Guardrails always on:** progressive enhancement (no Baseline feature may hard-depend on Redis / a WebSocket server / a persistent worker / an external search engine — detect and degrade); strict clean-room (reimplement, never copy from reference forums); reversible non-destructive migrations; security-by-default (CSP, HMAC where applicable, rate-limit, audit log, sanitized rich-text).

---

## Phase 0 — "Clear the decks" (do these first)
Two small blockers, each Sonnet-tier once scoped — knock them out before the apex work so U7/U17 build on a clean tree:
- **NOV-120 — Purge orphaned `⚡`-prefixed admin view files.** `resources/views/components/admin/` holds **31** `.blade.php` files prefixed with a ⚡ (U+26A1) emoji — an unreferenced *shadow copy* of the ACP (the live panel is served by clean-named views under `resources/views/admin/*` + the non-emoji components). Verify unreferenced (`grep`, `view:cache`, `route:list`) → delete all ⚡ files (incl. `components/admin/members/`) → **re-home the trapped `BelongsToMany` type-hint fix** into the correct live view if still wanted → gate green → add a CI/lint guard that fails on non-ASCII blade filenames.
- **NOV-76 / BUG-001 (P0)** — admin section landing emits a bare gear icon. Fix, gate, commit.

---

## Phase 1 — **U7 · Embed API / SSI / web components** (`NOV-106`, ◆ APEX)
**Spike first.** Produce a GO/NO-GO memo at `docs/product/spike-u7-memo.md` (approach, threat model, Baseline-tier viability) before building. Read the U7 entry in `feature-list.md` for the product surface.

**Apex focus — the untrusted-embedding boundary:**
- Cross-origin embedding: `frame-ancestors`/CSP, `X-Frame-Options`, and a per-embed allowlist; no clickjacking or data leak through embeds.
- SSI / web-component surface: server-rendered, escaped, no injection via embed params; scoped read-only tokens, not session cookies.
- Rate-limit and cache embed endpoints; ensure they work on the **Baseline** tier (cron-only, no daemon).

**Acceptance:**
- Server-rendered embed endpoint(s) + a documented, semver'd embed contract (ADR in `DECISIONS.md`).
- CSP-safe; degrades on Baseline; no auth/data leakage.
- Tests incl. **adversarial** fixtures (forged/oversized/malformed/cross-origin embed requests); a11y on embedded widgets.
- Adversarial verify-then-refute review clean → PROJECT-STATE handoff → **stop for owner merge.**

---

## Phase 2 — **U17 · Plugin install-from-zip + signature/trust gate** (`NOV-115`, ◆ APEX)
**Spike first.** GO/NO-GO memo at `docs/product/spike-u17-memo.md`. Read the U17 entry in `feature-list.md`.

**Apex focus — untrusted archive + supply-chain trust:**
- Zip parsing hardened against zip-slip / path traversal, symlink escape, and zip-bombs (size/entry caps).
- Signature verification + trust tiers; **reject unsigned or tampered packages by default**; key management documented.
- Install is **reversible/idempotent** (clean rollback on failure), quarantines bad packages, and writes an audit trail.
- Respect the module/plugin API as a semver'd public contract; Baseline-safe (no daemon).

**Acceptance:**
- Signed-plugin verify + trust gate; unsigned/tampered rejected; reversible install with rollback; audit log; ADR in `DECISIONS.md`.
- Tests incl. **adversarial zip fixtures** (traversal, symlink, bomb, bad signature, truncated archive).
- Adversarial review clean → PROJECT-STATE handoff → **stop for owner merge.**

---

## Definition of done (per feature)
Gates green · adversarial review clean · ADR recorded · `PROJECT-STATE.md` updated · Linear issue moved (In Progress at start → Done on merge) · branch left for owner to push/merge.

## Linear bookkeeping
- Move **NOV-106** and **NOV-115** to *In Progress* when you start each; *Done* on merge.
- Phase 0: move **NOV-120** and **NOV-76** to *In Progress* → *Done* on merge.
- Board: https://linear.app/novfora (team NOV).
