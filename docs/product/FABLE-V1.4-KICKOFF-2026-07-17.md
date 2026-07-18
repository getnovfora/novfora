<!-- SPDX-License-Identifier: Apache-2.0 -->

# FABLE ultracode kickoff — v1.4 "The Creator Release" (2026-07-17)

> **Read this file first, cold, then run it end-to-end unattended.** It is the executable spec for one long
> Fable/ultracode session covering **all of v1.4** — Phases 4A → 4B → 4C → 4D → 4E — ending at a **locally
> tagged `v1.4.0`**. It supersedes `BUILD-PROMPTS-2026-07-02.md` Prompts 5–8 for this run (those prompts each
> say "STOP for approval"; **this document IS that approval** — see §2).
>
> **Owner approved:** scope = all of v1.4 · plan gate = this doc · merge policy = merge to local `main` + tag
> (owner pushes) · Phase 0 doc reconcile = yes.
>
> Standing context, read don't restate: `CLAUDE.md` (hard rules, model routing) · `PROJECT-STATE.md` ·
> `docs/PROJECT-BRIEF.md` · `DECISIONS.md` · `docs/product/ROADMAP-V1.3-V1.5-2026-07-02.md` ·
> `docs/product/ADMIN-API-AND-POPULATE-SPEC-2026-07-02.md` (authoritative for 4E).

---

## 1. Ground truth at kickoff (verified 2026-07-17 — trust this over PROJECT-STATE.md)

| Fact | Value |
|---|---|
| `main` HEAD | `126e5f0` — *docs: add v1.3-v1.5 roadmap, admin-API/populate spec, and build prompts* |
| `origin/main` | **in sync** at `126e5f0` (nothing to push) |
| Latest tag | **`v1.3.0`** — shipped, pushed |
| `config/app.php` version | `1.3.0` |
| Linear (team NovFora) | **0 In Progress · 0 real Todo** (the 4 Todo items are archived Linear onboarding placeholders). All of v1.4/v1.5 sits in **Backlog**. |
| ADRs used | through **0111**, plus **0120** (Populate seams). **0112–0119 are free.** |
| `modules/novfora/` | `hello`, `kudos`, `qa`, **`populate`** (NTFS junction → `D:\novfora-populate`, private, git-excluded — **never commit it here, never ship it in the release zip**) |

### ⚠ Known drift to fix in Phase 0 (do not skip)
- **`PROJECT-STATE.md` is stale.** Its newest report is the 2026-07-02 Populate session; it contains **zero
  mention of v1.3.0** even though v1.3.0 is built, merged, tagged and pushed. A cold session reading it would
  be actively misled.
- The v1.3 **Phase 3A / 3B morning reports are stranded** on unmerged doc-only branches:
  `claude/v13-phase3a-report` (`d4898a9`), `claude/v13-phase3b-report` (`027346d`).
- **Untracked files in the worktree** (leave them alone unless Phase 0 says otherwise):
  `NovFora-v1.3.0-Announcement.md`, `brand/site-preview/`, `docs/NOVFORA-multi_sonnet.md`,
  `docs/NovFora_MultiAgent_Workflow.md`, `docs/product/GPT_UIUX_HANDOFF.md`, `novfora-docs/`.
- **Stale feature branches** already merged or superseded (`claude/baseline-green`, `nov-121-*`, `nov-122-*`,
  `nov-3b/3c/3d-*`, the `echofivetech/nov-1xx-*` set). Do **not** delete them; just don't branch off them.

---

## 2. The approval model (this is what makes the session unattended)

`BUILD-PROMPTS` Prompts 5–8 each say *"Plan first; STOP for approval."* **The owner has pre-approved that gate
by approving this document.** Therefore:

1. **Still write the plan.** Before each phase, write the plan memo to `docs/product/` (e.g.
   `plan-4a-4b-style-engine.md`). It is the artifact the review reads and the report cites. Then **proceed** —
   do not idle waiting for a human.
2. **Spikes self-gate.** `NOV-124` (Registry) is the one spike inside v1.4. Write
   `docs/product/spike-registry-memo.md` with an explicit **GO / NO-GO** verdict judged against the criteria in
   §5 (4C). **On GO:** build. **On NO-GO:** do **not** halt the run — park the slice, record the reasoning in
   the memo + an ADR-worthy note, flag it in the ☀️ owner section, and **continue to the next phase**.
3. **The `Ask before` rule in `CLAUDE.md` still binds.** A genuinely ambiguous *product* call, a destructive
   operation, a stack-changing dependency, or anything that would relitigate a locked decision → **park it,
   ship around it, and flag it in the report**. Never guess and never silently change a locked decision. If a
   phase cannot proceed without such a call, park **that phase** and move on; do not park the session.
4. **A red gate is never negotiable.** Nothing merges that isn't green. A slice that can't go green gets
   parked on its branch with the failure documented — the run continues.

---

## 3. Standing rules for this run (compressed — `CLAUDE.md` is authoritative)

- **Model routing: `ultracode` default.** Start every turn at the top (**Fable @ max**) and downgrade as the
  work proves to be pattern-replication rather than correctness-load-bearing. **Fable is expected to error in
  the build env — fall back to Opus 4.8 `xhigh` for apex work; that is a known, sanctioned fallback, not a
  failure.** Do not pin a low rung in advance. Apex tells for this release: `PermissionResolver`/`acl_entries`
  (E1 scope ∩ `canDo`), the template sandbox (U11), archive/signature/untrusted input (Registry, E5, E7), and
  any transaction whose correctness depends on kill-timing (E4/E5 resumable jobs).
- **Delegate breadth.** Any "where is X / which files touch Y" sweep → `Agent(subagent_type: "Explore",
  model: "sonnet")`. Keep the main context lean; only conclusions come back.
- **Commit identity:** author **and** committer `Tommy Huynh <tommy@saturnhq.net>`, `-s` (DCO), conventional
  commits, no AI trailers. Set `git config user.name/user.email` before the first commit.
- **Clean-room is absolute** — including for the MyBB/SMF importers: read source **DB/output structure only**
  to copy *data*; never their program, templates, or docs.
- **Progressive enhancement:** nothing in v1.4 may hard-depend on Redis / a worker / WebSockets / Meilisearch.
  The Registry feed is a **static file**; backups have a **chunked PHP-native fallback**; self-upgrade must work
  cron-only on Baseline.
- **Reversible migrations only.** Every migration in this release gets the apply → `rollback(all)` → re-apply
  gate on a throwaway DB.
- **Gates before reasoning.** Write → run the gate → read the tail → fix. Cap output (`tail -n N`). Never
  re-read a file you just edited.
- **`php artisan route:clear` before every gate run** — the stale `bootstrap/cache/routes-v7.php` produces false
  subdir/PWA reds (known env finding).
- **One branch per slice, off `main`.** Commit only at a fully-green boundary.

---

## 4. Phase 0 — reconcile the handoff (do this first, ~30 min)

Branch: `claude/v14-phase0-reconcile`.

1. **Land the stranded v1.3 reports** — merge `claude/v13-phase3a-report` and `claude/v13-phase3b-report` (both
   doc-only) so the v1.3 record exists on `main`.
2. **Write the missing v1.3.0 release record** into `PROJECT-STATE.md`: what shipped, the tag, that
   `origin/main` is in sync, and the residue (Dusk CI-pending items, parked product calls).
3. **Trim `PROJECT-STATE.md` back to lean** per its own header contract — the active task, the latest run, the
   VALIDATE-BEFORE-GO-LIVE list, open follow-ups. **Move every completed milestone block to
   `PROJECT-HISTORY.md`.** The file is ~664 lines and carries six stacked morning reports; it should end this
   phase under ~150.
4. **Set the active task** to this kickoff.
5. **Reconcile Linear to reality** (the new `CLAUDE.md` convention, §7): confirm the v1.3 issues are `Done`;
   confirm **NOV-140 / NOV-143** (Populate E6a/E6b) are `Done` — PROJECT-STATE says they were left `In Progress`
   for owner review and Linear now shows nothing In Progress, so **verify rather than assume**, and post the
   completion comments if they were never written (a prior session reported blocked writes).
6. **Gate:** link-check the docs, `pint --test`. Merge to `main` (doc-only, no code risk).

---

## 5. The build — Phases 4A → 4E

> Order is **4A → 4B → 4C → 4D → 4E**. 4E is backend-only and may run parallel to 4A–4C if you want the
> headroom (the roadmap notes low collision) — but **sequential is the safe default**; only parallelize if the
> session is running long. Each slice: branch off `main`, build, gate green, commit, **apex-review if ◆**,
> then move on. **Nothing merges until §6.**

### Phase 4A — Style engine (no apex; Sonnet-rung once the design is locked)
| Slice | Linear | Branch | ADR |
|---|---|---|---|
| U9 rich style-property system — typed/grouped props, live preview, on the ADR-0037/0038 sandbox (today ~7 tokens) | **NOV-107** | `claude/v14-u9-style-props` | — |
| U10 multi-style tree — parent/child inheritance + per-user style chooser | **NOV-108** | `claude/v14-u10-style-tree` | — |

**Gate 4A (exit proof):** two shipped presets (light/dark descendants) **+ a child theme built entirely in the
ACP**, no filesystem editing. Honour the locked brand (`brand/NovFora-Brand-Guidelines.md` — Nova Blue leads,
Ember Amber signature, teal = success only).

### Phase 4B ◆ APEX — Upgrade-safe customization (the release centerpiece)
| Slice | Linear | Branch | ADR |
|---|---|---|---|
| U11 template hook layer + **Diff3 three-way merge** — targeted template mods that survive upgrades; replaces whole-file override as the only tool | **NOV-109** | `claude/v14-u11-template-hooks` | **0112** |

**This is an untrusted-template surface on the sandbox → apex rung + mandatory adversarial review (§8).**
**Gate 4B (exit proof):** a **themed + template-modded install upgrades across a simulated core release** with
mods intact; conflicts **surface in the ACP and are never fatal**. Prove the sandbox holds (escape, injection,
resource exhaustion) — the review, not the suite, is the signal.

### Phase 4C ◆ — Distribution: export + Registry + importers
**SPIKE FIRST — NOV-124** → `docs/product/spike-registry-memo.md`, explicit GO/NO-GO. Decide: feed schema;
publisher ed25519 key **enrollment / rotation / REVOCATION** (revocation is the hard part — it must propagate to
the upgrade path of already-installed packages); package types; threat model (tampering, mirror substitution,
**downgrade**, typosquatting).

> **Self-gate criteria — GO requires ALL of:** (1) a revocation story that reaches installed packages, not just
> the feed; (2) the feed stays a **static file** (nothing to run server-side, mirrorable, Baseline-servable);
> (3) the client rides `ArchiveGuard` + `PackageSignature` **untouched** — no second install path; (4) downgrade
> and mirror-substitution are both structurally refused. **Any one unmet → NO-GO → park 4C's Registry slice,
> keep U12 + the importers, continue to 4D.**

| Slice | Linear | Branch | ADR |
|---|---|---|---|
| U12 style import/export — zip + manifest (**the export format IS the registry theme format**) + global custom-CSS box | **NOV-110** | `claude/v14-u12-style-io` | — |
| Registry v1 ◆ APEX — static signed JSON feed + ACP Browse + one-click install of **signed packages only** | **NOV-125** | `claude/v14-registry-v1` | **0113** |
| Importer completion — MyBB + SMF to the phpBB/XenForo standard + "Switch to NovFora" guides | **NOV-126** | `claude/v14-importers` | — |

**Gate 4C:** export a theme → publish to a **test** feed → one-click install on a fresh install → survives an
upgrade. Importer round-trip on real MyBB/SMF dumps. **≥3 first-party packages** listed (the Q&A module, one
theme, one plugin). *Registry v1 = supply chain → apex review (§8).*

### Phase 4D — Admin at scale + staff workflow (the Invision-parity pass; mostly Sonnet-rung)
| Slice | Linear | Branch | ADR |
|---|---|---|---|
| U14 registration controls — approval queue, ToS/age gate, domain allow/deny; **includes the pending-member exit-ramp fix** (see `docs/product/pending-member-review-kickoff.md`) | **NOV-112** | `claude/v14-u14-registration` | — |
| U13 IP investigation + ban management — CIDR/range bans, IP history *(elevated review)* | **NOV-111** | `claude/v14-u13-ip-bans` | — |
| U16 maintenance/rebuild + broadened logs + mail-test ACP | **NOV-114** | `claude/v14-u16-maintenance` | — |
| U19 custom topic fields + move-with-redirect (wire the existing `moved_to_topic_id` seam) | **NOV-116** | `claude/v14-u19-topic-fields` | — |
| Staff workflow + **Hearth metrics v1** — topic/report assignment + workload view on `moderator_assignments`; health dashboard (first-response time, unanswered %, staff load, retention cohort) extending the T3 inline-SVG charts | **NOV-127** | `claude/v14-staff-workflow` | **0114** |

**Hearth metrics: REAL signals only** — no invented or estimated numbers. If a signal isn't truthfully derivable
from the data, omit the tile.
**Gate 4D:** full gates + demo soak.

### Phase 4E ◆◆ — Admin API + Self-upgrade
**Read `docs/product/ADMIN-API-AND-POPULATE-SPEC-2026-07-02.md` IN FULL — it is authoritative and overrides this
table on any conflict.** Slice order **E1 → E2 → E3 → E4 → E5 → E7 → E8**. **E6a/E6b are already shipped** as the
private Populate plugin — do **not** rebuild them; add only the **thin Populate API round-trip endpoints behind
E1's scopes** and update the script.

| Slice | Linear | Branch | Rung | ADR |
|---|---|---|---|---|
| E1 auth spine — scoped tokens (**scopes ∩ `canDo`**, no super-scope, co-owner-only restore/upgrade scopes, 2FA-gated mint, audit, `Idempotency-Key`, OpenAPI 3.1). Extends `ApiTokenService`/`AuthenticateApiToken` (ADR-0033) — same table, new scope model | **NOV-135** | `claude/v14-e1-api-auth` | **◆ APEX** | **0115** |
| E2 read surface — settings, structure, members (PII scope-gated at A1's ceiling), moderation, health | **NOV-136** | `claude/v14-e2-api-read` | Sonnet | — |
| E3 write surface — **existing domain services ONLY**, never a second code path (`StructureService`, `UserBanService` w/ the S5 owner-strand guard, `GroupManager`) | **NOV-137** | `claude/v14-e3-api-write` | **◆** | — |
| E4 backups — DB + uploads, resumable checkpointed job (cron-drained), streamed download, schedule/retention, optional encryption. **A dump contains every secret + every PM.** | **NOV-138** | `claude/v14-e4-backups` | **◆ APEX** | **0116** |
| E5 restore — staged upload → verify → dry-run → execute, auto-snapshot, rollback; introduces `StagedArtifact`. **The most dangerous endpoint in the product.** | **NOV-139** | `claude/v14-e5-restore` | **◆◆ APEX** | **0117** |
| E7 self-upgrade — core-release ed25519 signing in `build-release.sh` + `.sig` release assets; GitHub Releases channel (`getnovfora/novfora`) + version picker + manual zip; layout-aware swap on RH-4 A/B/C; hands off to the **untouched** RH-10 `UpgradeRunner`; retained-release rollback; `upgrade_runs` state machine. **ed25519 is the only trust root — GitHub is transport.** | **NOV-141** | `claude/v14-e7-self-upgrade` | **◆◆ APEX** | **0118** |
| E8 docs — OpenAPI 3.1 published + committed; `docs/api/admin.md`, `docs/api/upgrade.md`, `docs/api/populate.md` + the versioned ForumGen plan schema + example plan (**schema doc ships publicly; the plugin stays private**) | **NOV-142** | `claude/v14-e8-api-docs` | Sonnet | — |

**Gate 4E:** the spec §5 end-to-end proof — **token → structure → backup → restore → seed → drip → purge** —
plus the GitHub-mock **and** manual-zip upgrade → rollback → re-upgrade **on all three RH-4 layouts**.

> **ADR numbers 0112–0119 are proposed, not reserved.** `0112/0113/0114` come from the roadmap; `0115–0118` are
> this doc's allocation. **Confirm next-free in `DECISIONS.md` before lifting** (used: through 0111, plus 0120).
> Append-only stacking — expect the DECISIONS.md tail to conflict on every merge; keep both sides in order.

---

## 6. The release run (only after every phase above is green)

1. **Back up first:** tag `backup/pre-v14` on `main` before the first merge.
2. **Merge in phase order** — 4A → 4B → 4C → 4D → 4E — `--no-ff`, one slice at a time, **re-gating between
   merges**, not just at the end. Expected conflicts: the `DECISIONS.md` tail (append-only, keep both in order),
   `config/novfora.php` / `.env.example` (different regions, additive), `AdminNavigation` + `lang/en/admin.php` +
   `routes/web.php` (adjacent nav items), and the `AdminAccessWalkTest` sentinel (trivial both-add).
3. **Union gate, all green, no exceptions:**
   - `php artisan route:clear` **first**, then `php artisan test --parallel` → **0 failed**
   - `pint --test` clean · `phpstan` **0 errors** (app/)
   - migrate **apply + rollback(all) + re-apply** clean on a throwaway DB
   - `npm run build` → **commit the rebuilt assets**; ViteManifest / asset-budget gate green
   - a11y page gate green
   - **Dusk:** run it if Chrome is available on the box; if not, mark the specs **CI-pending** and say so
     plainly in the report (do not claim a gate you didn't run).
4. **Bump** `config/app.php` version → **`1.4.0`**.
5. **Build + verify the artifact:** `scripts/build-release.sh` → `scripts/verify-release.sh` → **must print
   `RELEASE_VERIFY=PASS`**. Reconcile the `build-release.sh` allowlist if 4A–4E added/removed shipped paths.
   **Confirm the Populate plugin is NOT in the zip** (it's a junction; it must not be shipped or committed).
6. **Tag `v1.4.0`** annotated, locally, on the release commit.
7. **STOP at the push.** Do **not** attempt `git push origin main` — the harness blocks direct pushes to the
   protected default branch even with granted authority. **Tag locally; the owner pushes `main` + the tag by
   hand.** Say so in the ☀️ section rather than reporting a failure.

---

## 7. Linear discipline (mandatory — the new `CLAUDE.md` convention)

**The cycle is not done until the tracker matches the repo.** Per phase, and again before the report:

1. **Flip state** — `In Progress` when a slice starts; `Done` on merge. (v1.4 issues all sit in **Backlog**
   today — move them, don't leave the board lying.)
2. **Completion comment per issue** — branch + head SHA, gate result, ADR number, and any finding the
   adversarial review **confirmed**. The issue must stand alone without the report.
3. **File what you discover** — new bugs, parked product calls, flagged pre-existing gaps → new issues in the
   right project/milestone. Don't bury them in prose.
4. **Report blocked writes.** If the classifier denies a Linear write, list the exact issue IDs + intended state
   in the ☀️ owner section. Silence here has bitten this project before (NOV-76, NOV-120).
5. **Reconcile `PROJECT-STATE.md` in the same breath** — a shipped release with no report is the same failure as
   an un-flipped issue.

---

## 8. Adversarial review mandate (◆ / ◆◆ slices)

Apex slices get a **verify-then-refute** review at the **highest available rung** (Fable @ max; **Opus 4.8
`xhigh` on the expected Fable fallback**). This is not optional and not a formality — on this project the apex
review has repeatedly caught real HIGH bugs that a fully green suite missed (Populate: 3 confirmed HIGH behind
83 passing tests; S5: 3 confirmed HIGH).

**Per apex slice:** run ~5–6 reviewers, one per lens (authz/permission, untrusted input, concurrency/idempotency,
data leakage, supply chain, DoS). Then run an **independent refuter on every HIGH/MEDIUM** — a finding is only
real if it survives refutation against the committed code. **Fix every confirmed HIGH/MEDIUM before the slice
merges.** Record refuted candidates and accepted LOWs with reasoning in the report; don't quietly drop them.

**Required lenses this release:**
- **U11** — sandbox escape, template injection, Diff3 merge corrupting a file, conflict path fatal-ing an upgrade.
- **Registry v1** — key revocation not reaching installed packages; mirror substitution; **downgrade attack**;
  typosquatting; any path that installs unsigned.
- **E1** — scope ∩ `canDo` (a demoted/revoked user's token **must** lose power — the resolver is authoritative);
  no super-scope; token minting behind 2FA; idempotency replay.
- **E3** — service bypass: prove **no** mutation reaches the DB outside the existing domain services.
- **E4/E5** — a dump is every secret + every PM (leakage, auth on download, encryption); resumable-job
  correctness under **mid-kill**; restore install-id/schema-version mismatch; `ArchiveGuard` never `extractTo`.
- **E7** — signature is the only trust root (a compromised/hostile GitHub response must be refused); layout-aware
  swap on all three RH-4 layouts; rollback actually rolls back.

---

## 9. Environment notes (save yourself the rediscovery)

- **Fable errors in the build env** → apex falls back to **Opus 4.8 `xhigh`**. Expected; don't fight it.
- **`route:clear` before every gate** — stale `bootstrap/cache/routes-v7.php` = false subdir/PWA reds.
- **Dusk needs Chrome**; on the VPS there is none → specs go CI-pending. Report honestly.
- **`modules/novfora/populate` is an NTFS junction** to the private repo `D:\novfora-populate` (git-excluded via
  `.git/info/exclude`). It must never enter this repo's history or the release zip.
- **Protected `main`** blocks pushes from the harness. Tag locally; the owner pushes.
- **Gemini key:** the old `F:\ForumGen\generate_forum.py` had a committed API key — the owner was asked to revoke
  it and it is **not confirmed revoked**. Re-flag it in the report if still open. The new script is env-only.

---

## 10. Definition of done (what the morning report must show)

- [ ] Phase 0 landed: `PROJECT-STATE.md` accurate + lean (< ~150 lines), v1.3.0 recorded, history moved.
- [ ] Every phase 4A–4E either **merged green** or **parked with a documented reason** (never silently dropped).
- [ ] Every ◆/◆◆ slice has an apex review with **0 open HIGH/MEDIUM**, findings + refutations recorded.
- [ ] Union gate green; assets rebuilt + committed; migrations reversible.
- [ ] `RELEASE_VERIFY=PASS`; the Populate plugin is **not** in the zip.
- [ ] Version `1.4.0`; **`v1.4.0` tagged locally**; `backup/pre-v14` exists.
- [ ] **Linear reconciled** — states flipped, completion comments posted, discoveries filed, blocked writes
      listed explicitly.
- [ ] ADRs lifted into `DECISIONS.md` at the **confirmed** next-free numbers.
- [ ] A **☀️ What the owner does next** section: the push (`main` + `v1.4.0`), the demo.novfora.com upgrade
      (this batch carries migrations → the cron auto-upgrade path, **backup-first**, not assets-only), Dusk-in-CI,
      any parked product calls, any NO-GO'd slice, and the Gemini key if still open.
