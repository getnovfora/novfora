# PROJECT-STATE.md — NovFora (session resume / handoff)

> **Purpose:** single source of truth for where this project stands right now. Read this **first**, every
> session — both Claude Code and Claude Cowork. Keep it at the repo root. Whoever is working keeps it updated.
>
> **Completed milestone history → [`PROJECT-HISTORY.md`](PROJECT-HISTORY.md)** (reference-only; do not load every
> session). This file is kept lean: the active task, the latest run, the VALIDATE-BEFORE-GO-LIVE list, and open
> follow-ups. Everything below those lives in history.
>
> **Standing detail lives in the folder — read, don't restate:** `docs/PROJECT-BRIEF.md` (full spec) ·
> `CLAUDE.md` (rules, model/effort routing) · `DECISIONS.md` (ADR log) · `ARCHITECTURE.md` ·
> `docs/architecture/`, `docs/product/`, `docs/research/` (Stage A set).

---

## 🌅 Morning report — FABLE session: v1.2.0 (UI-audit reconcile + 4 BETA fixes + U8/U18/U20) — MERGED to local `main`, gated GREEN, TAGGED; owner pushes (2026-07-02)

Ran [`docs/product/FABLE-V1.2-KICKOFF.md`](docs/product/FABLE-V1.2-KICKOFF.md) end-to-end, unattended. Built the four
live-beta fixes + the three Phase-6 quick wins as independent branches off `main`, verified each against `main` first,
gated green at every boundary, ran the apex verify-then-refute review over all six seam-touching diffs, merged the
seven green slices into `main`, re-gated the union green, bumped to **1.2.0**, cut + verified the release artifact, and
**tagged `v1.2.0`**. Committed as `Tommy Huynh` (DCO `-s`, no AI trailers). **Everything is local — the `git push
origin main` + `git push origin v1.2.0` was BLOCKED by the harness protected-branch guard and is the owner's one
remaining git step** (see ☀️ below). Pre-merge backup: tag **`backup/pre-v12` (`0fa01a6`)**.

**`v1.2.0` annotated tag on `4ef4d24`** (the release commit — fast-forward-safe over `origin/main` `ab013c8`); this
morning-report doc commit sits on top. The owner pushes both `main` and the tag (see ☀️).

### Step 0 — baseline reconcile (before any feature work)
The consolidated `main` (`ab013c8`) was NOT green as-received: **two leftover `=======` merge-conflict markers** from
the owner's U7/U17 consolidation (in `.env.example` and `DECISIONS.md`) — the `.env.example` one broke
`EnvWriterSecurityTest` and would corrupt any real install's generated `.env` (dotenv parse error). Fixed (`a8eabb7`).
Also re-baselined **`HotPathQueryTest`**: on this VPS the tree carries `storage/installed`, so the first request pays
`SchemaState`'s one-time pending-migrations probe (+2 queries) that the markerless `forum-dev` never pays — the
topic-page budget read 42 here vs 40 in the container. Primed the cached flag in `beforeEach` so every host measures
the same steady state (`8a6d230`). Rebuilt assets for the U7+U17 views (`0fa01a6`). Baseline then **2189/0** green.

### Branch topology (7 independent slices off `main` `0fa01a6`)
```
main 0fa01a6 (baseline-green)
├─ nov-86 BETA-2  4a936b8   mobile nav spillover (header brand min-w-0)
├─ nov-85 BETA-1  63e8033   notification read-state (open-route + un-latch) [+harden]
├─ nov-87 BETA-3  b1695b5   DM 403 → friendly explainer (◆ apex, no widening)
├─ nov-88 BETA-4  11816b1   ghost-UI mod controls + bulk-lock semantics (◆ apex, ADR-0105) [+harden]
├─ nov-97 U8      7b18646   username history + admin revert (ADR-0106) [+harden]
├─ nov-98 U18     98d7555   hCaptcha/reCAPTCHA fail-closed + Gravatar (◆ apex, ADR-0107)
└─ nov-99 U20     3de26c3   SEO polish + pending-topic leak fence (◆-lite apex, ADR-0108)
```
Merged in order BETA-2 → BETA-1 → BETA-3 → BETA-4 → U8 → U18 → U20 (7 `--no-ff` merges + version bump + asset
rebuild). Conflicts were **DECISIONS.md tail** (append-only ADR stacking — kept 0105→0106→0107→0108 in order) and a
clean **auto-merge** of `TopicController`/`layouts`/`topic.blade` where BETA-4 and U20 both edit the topic-show path
(verified coherent by hand: U20's fence hoists `$user/$scope/$canModerate`, BETA-4's `$canMergeTopic` + `author.groups`
land after — no duplicate, no lost change). The `build-release.sh` allowlist was reconciled to drop the now-deleted
static `public/robots.txt` (U20 serves it dynamically).

### Union gate (merged `main`, all GREEN)
- `php artisan test --parallel` → **2251 passed / 1 skipped** (the Dusk CI-only spec) · **15 532 assertions**
- `pint --test` clean · `phpstan` **0** (app/) · migrate **apply + rollback(all) + re-apply** clean on a throwaway
  SQLite (2 new reversible migrations land this release: `module_trust_keys` from U17, `username_history` from U8)
- `npm run build` OK → assets committed (`6d8932d`); ViteManifest / asset-budget gate green
- **Release artifact:** `scripts/build-release.sh` → `novfora-release.zip` (16 MB), `scripts/verify-release.sh` →
  **RELEASE_VERIFY=PASS** (cold-boot 302→/install, i18n resolves, `packages.php` ships, no env caches)
- **Dusk = CI-only** (no Chrome on the VPS) — 3 new specs added CI-pending (`MobileHeaderJourneyTest` at 390/360/768px)

| Slice | Branch | Head | Gate | Notes |
|---|---|---|---|---|
| BETA-2 | `nov-86-...` | `4a936b8` | Pest✓ Pint✓ | brand `min-w-0`; markup + Dusk-CI 390px specs |
| BETA-1 | `nov-85-...` | `63e8033` | Pest✓ PHPStan0 | `notifications.open` click-through; dropdown un-latched; +open-redirect harden |
| BETA-3 | `nov-87-...` | `b1695b5` | Pest✓ PHPStan0 | ◆ apex GO/0-open; explainer not 403; service enforcement untouched |
| BETA-4 | `nov-88-...` | `11816b1` | Pest✓ PHPStan0 | ◆ apex GO/0-confirmed; ADR-0105; +eager-load & flash harden |
| U8 | `nov-97-...` | `7b18646` | Pest✓ PHPStan0 migrate✓ | ADR-0106; +uniqueness-race→field-error harden |
| U18 | `nov-98-...` | `98d7555` | Pest✓ PHPStan0 | ◆ apex GO/0-confirmed; ADR-0107; fixed latent Turnstile CSP gap |
| U20 | `nov-99-...` | `3de26c3` | Pest✓ PHPStan0 | ◆-lite apex GO/0; ADR-0108; +pre-existing pending-topic leak fence |

### Apex adversarial review (verify-then-refute, 6 reviewers × per-finding refutation) — **GO, 0 confirmed HIGH/MEDIUM**
Ran one apex reviewer per seam-touching diff (BETA-1/3/4, U8/U18/U20), then an independent refuter on every HIGH/MEDIUM.
**Both HIGH/MEDIUM candidates were REFUTED** on verification (not exploitable in the committed code): the BETA-1
open-redirect (all notification URLs are `route()`-generated — no injection path) and the U18 hCaptcha `connect-src`
(the checkbox widget doesn't touch the parent page's `connect-src`). I still applied **three cheap LOW hardenings** on
my own new code because they're correct regardless:
- **BETA-1** — the same-origin redirect check was a bare prefix match (`str_starts_with($url, url('/'))` admits
  `host.evil`, `host@evil`, `hostevil.com`); replaced with a host-boundary match (`base+'/'`) rejecting `//host`/`/\host`.
- **BETA-4** — `$canMergeTopic` lazy-loaded `topic.author->groups` (+1 query on the boundary-flaky moderator budget) →
  eager-loaded; the bulk-skip flash cited "rank" for lock, which no longer rank-gates → reworded.
- **U8** — a rare uniqueness race surfaced as a 500 → caught the integrity violation (SQLSTATE 23000/23505) → field error.

Accepted-and-noted LOWs (not fixed — reasoned): BETA-1 GET-side-effect (idempotent, owner-scoped); BETA-3 TL0 reaching
the read-only username autocomplete (usernames are already public via the members directory); U18 global CSP host
allowlist (fail-safe); U8 imported usernames that violate `alpha_dash/min:3/max:30` are un-revertable (a product call —
should revert bypass the format rule for a historical value? — **parked** for the owner).

### Verified-and-skipped (Track UA — the whole 21-finding UI-audit spec)
**Every Track-UA bug was already fixed on `main` before this session** — by **PR #41** (`b437462`, "UI-audit reconcile",
merged 2026-06-27), NOT PR #49. A spot-check confirmed all 21 findings intact at HEAD and all 12 named test files green
(40/40). So BUG-001/002/003/004/005/007/008/009/010/011/012/013/014/016/017/018/019/020/021 are **shipped, not
rebuilt**; BUG-006 stays reclassified (not a defect). **PR-#49 overlap explained:** PR #49 (`011cf6c`) was the ACP
members-directory PII/gating change (A1) — it does **not** touch BUG-007/008/015 or BUG-019; the kickoff's "likely PR
#49" hint was mistaken (those are PR #41's `d9b7e79` and `d09e657`). **BETA-5** (scheduled-reply) was already fixed by
**NOV-89 / PR #51** (`b83c1db`) → skipped. The v1.2 release therefore carries the UA fixes by virtue of PR #41 already
being in `main`.

### Parked (nothing shipped broken; owner follow-ups)
- **U8 imported-username revert** — a legacy/imported handle violating the modern `alpha_dash/min:3/max:30` rule can't
  be reverted (fails validation). ADR-0106 flags it; needs a product decision on relaxing the rule for historical values.
- **U18 Turnstile fail-open** — the shipped Turnstile driver stays fail-**open** (its reasoned prior decision); the two
  new drivers are fail-**closed** per the kickoff. Whether to unify posture is an owner call (ADR-0107).
- **U20 out-of-scope (ADR-0108):** tag/club OpenGraph, a purpose-built 1200×630 social-card asset, JSON-LD beyond the
  existing topic block, and the `<title>`/PWA-manifest reading `config('app.name')` instead of the ACP `general.site_name`.

### New ADRs (0105–0108, lifted into DECISIONS.md; next-free was 0105 after 0104)
0105 permission-aware moderation UI + bulk-lock semantics (BETA-4, apex); 0106 username history + admin revert (U8);
0107 CAPTCHA driver completion fail-closed + opt-in Gravatar (U18); 0108 SEO polish + pending-topic fence (U20).

### Linear (team NovFora) — all moves succeeded
**→ Done:** NOV-85/86/87/88 (BETA-1..4), NOV-97/98/99 (U8/U18/U20), and the UA cluster NOV-77..NOV-84 (verified-shipped
via PR #41). NOV-89 (BETA-5) + NOV-76 (BUG-001) were already Done; NOV-106/115 (U7/U17) already Done. No blocked writes
this session.

### ☀️ What the owner does next
1. **Push (the one blocked git step):** `git push origin main` (fast-forwards `origin/main` `ab013c8` → `4ef4d24`) and
   `git push origin v1.2.0`. The harness auto-mode classifier denied the direct push to the protected default branch;
   nothing is wrong with the commits (tree clean, tag on HEAD, ff-safe). Add a Bash permission rule or push by hand.
2. **Go live (backup-first):** upgrade **demo.novfora.com** via the **cron auto-upgrade** path — this batch carries
   **new migrations** (`module_trust_keys`, `username_history`) + new capability keys, so it is NOT assets-only. The
   verified `novfora-release.zip` (gitignored at repo root; SHA in `scripts/verify-release.sh` output) is ready, or
   rebuild with `scripts/build-release.sh` on a Node host.
3. **Run Dusk in CI** (no Chrome on the VPS) — 3 new mobile-header specs are CI-pending.
4. **Decide the parked items** (U8 imported-username revert; U18 Turnstile posture) when convenient.

---

## 🌅 Morning report — FABLE session: Phase-0 clear-decks + U7 + U17 (2026-07-01) — 4 branches off `main`, NONE pushed/merged

Ran [`docs/product/FABLE-U7-U17-KICKOFF.md`](docs/product/FABLE-U7-U17-KICKOFF.md) end-to-end, unattended. Built the two
◆ APEX features (**U7** embeds, **U17** plugin install-from-zip) after clearing the Phase-0 decks. Each feature is an
independent branch; both **share a small prerequisite base** (see topology). Gated green in `forum-dev` at every
boundary; committed as `Tommy Huynh` (DCO `-s`, no AI trailers). **Nothing pushed, nothing merged** — the owner reviews.

### Branch topology (all off `main` `658508d`)
```
main 658508d
└─ claude/baseline-green 04e3ec6        3 commits: green-gate prerequisites (see below)
   └─ nov-120 bed6f96                    +1: the trapped BelongsToMany fix (NOV-120)
      ├─ nov-106 U7  b193699             +2: U7 embeds build + review fixes  (ADR-0103)
      └─ nov-115 U17 0d1eb7b             +1: U17 zip-install + trust gate    (ADR-0104)
```
Both feature branches contain `baseline-green` + `nov-120`, so they gate green standalone. **Suggested merge order:**
`baseline-green` → `nov-120` → `U7` → `U17`. Cross-branch overlap is trivial + additive: `DECISIONS.md` (U7 appends
ADR-0103, U17 appends ADR-0104 — both after 0102; keep both), `config/novfora.php` (U7 adds a top-level `embeds` block,
U17 adds a `zip` block inside `modules` — different regions), `.env.example` (both append after the oEmbed block). No
shared code file conflicts.

### Phase 0 — clear the decks
- **`claude/baseline-green` (`04e3ec6`)** — the working tree carried **21 pre-existing reds** unrelated to this work;
  fixed so the gate is trustworthy: **(1)** `forum-dev` image lacked **ext-gd** → 12 attachment specs LogicException'd
  (added GD to `docker/dev/Dockerfile`; hot-installed in the running container). **(2)** `NavigationManager::tree()`
  ran an **uncached `navigation_items` read per surface on every page** (+2 queries/page from PR #50) → 4 query budgets
  blew; cached the enabled rows (plain arrays, RH-9) with model-event busting, and re-baselined two topic budgets in
  the canonical `forum-dev` gate (prior ceilings were VPS-measured). **(3)** `MemberDirectoryTest` asserted 403 for an
  admins-without-2FA user where the designed route behavior is a **302 bounce to 2FA** (the hard 403 is the
  Livewire-internal guard, asserted separately). Full suite **2140 passed / 0 failed** at `04e3ec6`.
- **NOV-120 — REFUTED, not purged (`bed6f96`).** The 45 `⚡`-prefixed files under `components/admin/` are **NOT a dead
  shadow copy — they are the LIVE ACP** (Livewire 4 single-file components; each maps 1:1 to a `<livewire:admin.*>` tag
  in the wrapper views; there is no `app/Livewire/` class twin). Evidence posted to the issue. The "trapped"
  `BelongsToMany` type-hint fix was **already in the right file** (`⚡members.blade.php` IS the live members directory) —
  committed in place. The proposed non-ASCII-blade CI guard was **rejected** (it would fail every live Livewire SFC). The
  ⚡ files are load-bearing: without this fix, `MembersTableTest` TypeErrors, which is why both feature branches are based
  on `bed6f96`.
- **NOV-76 — already fixed on `main`.** `admin/section.blade.php` already carries the `@extends` envelope the issue
  prescribes (shipped by the ACP v3 program after the UI-audit spec was written); verified by `PerSectionRailTest` +
  `AcpSectionNavTest` (14 tests). No code change; the issue was simply never closed.

### Phase 1 — U7 · Embed API / SSI / web components (`NOV-106`, ◆ APEX, ADR-0103) — GO
Spike memo `docs/product/spike-u7-memo.md` (GO). Server-rendered iframe/SSI widgets (`/embed/v1/w/{widget}`) + versioned
JSON (`/embed/v1/d/{widget}.json`) + a dependency-free `<novfora-*>` web-component bundle (`public/embed/embed.js`), all
**guest-visible content only**, **OFF by default** (`embeds.enabled`). Stateless route group OUTSIDE `web` (no
session/cookies/CSRF), per-IP rate limiter, 60s fragment cache. Per-embed allowlist (`embed_sites`, audited
`EmbedManager`): a public rotatable site key bound to an exact origin; the HTML response owns its
`frame-ancestors <origin>` CSP and the JSON response grants CORS to exactly that origin (no `*`, no credentials, no
global CORS layer). No-leak fence = `User::guest()` + `VisibleForumIds` + club gates, **404 on every denial** (feature
off / bad key / unknown widget / invisible forum indistinguishable). ACP Plugins→Embeds SFC (admin.access + staff-2FA).
**Tests:** 25 (incl. adversarial: forged origins, oversized/malformed params, cross-origin JSON, private forum + private
club probes, rotated keys, escaping, statelessness, 429) + 2 WCAG surfaces. One additive reversible migration
(`embed_sites`).

**U7 adversarial review (verify-then-refute, 5 lenses × per-finding verify) — GO, 0 open HIGH/MEDIUM after fixes
(`b193699`).** One substantive finding (rated up to MEDIUM), fixed: the *board-wide* topics widget resolved the
guest-visible forum set INSIDE the cache closure and keyed on a constant, so a just-restricted forum's topic titles
served for the rest of the 60s TTL — inconsistent with the live-fenced scoped path. Fixed: resolve `VisibleForumIds`
live and fold a signature of the visible set into the cache key (regression test proves the drop happens within the TTL
without flushing the cache). Two LOW hardening items applied (`normalizeOrigin` strict host charset rejecting `;`/`,`/
control/raw-UTF-8 bytes; `embed` limiter IPv6 /64 bucketing). 5 candidates refuted (array-param 400 not 500;
control-char origins neutralized by `parse_url`; IDN/trailing-dot fail closed; web-component href is `route()`-generated;
the `Cache-Control: public` window caches only guest-public bytes). Full suite **2186 / 0**.

### Phase 2 — U17 · Plugin install-from-zip + signature/trust gate (`NOV-115`, ◆ APEX, ADR-0104) — GO
Spike memo `docs/product/spike-u17-memo.md` (GO). Closes the ADR-0031/H3 flag that a package signature + distribution
channel were "not built". Upload a signed module `.zip` in ACP → Plugins; installs **disabled** only if safe to extract
AND authentic. **`ArchiveGuard`** never `extractTo`s an untrusted zip — it iterates entries and writes each via a
bounded streamed copy to a path proven inside staging (traversal/absolute/backslash/null rejected; **symlink escape
structurally impossible** — only regular files written; entry-count/per-file/total/ratio caps enforced on the ACTUAL
streamed bytes so a lying header can't slip a bomb; extension allowlist). **`PackageSignature`** = detached **ed25519**
(bundled `ext-sodium`, constant-time) over the package's canonical digest, verified against the admin-managed trusted-key
registry (`module_trust_keys`); a present-but-invalid signature is ALWAYS rejected, unsigned rejected unless the loud dev
`allow_unsigned` policy, fail-closed if `ext-sodium` absent. **`ModuleInstaller`** = stage → validate manifest (slug from
`module.json`, never the filename) → verify sig → atomic rename into `modules/<slug>` → `ModuleManager::install/upgrade`;
any failure rolls back + quarantines the archive with an audited reason. ACP module-install SFC (admin.access +
staff-2FA); `novfora:module:sign` is the author recipe. **Tests:** 19 adversarial specs (traversal, absolute, symlink,
bomb, bad sig, untrusted key, too-many-entries, disallowed type, invalid/absent manifest, install-over-existing, ACP
authz + end-to-end signed upload). One additive reversible migration (`module_trust_keys`). Full suite **2159 / 0**.

**U17 adversarial review (verify-then-refute, 5 lenses × per-finding verify) — GO, 0 HIGH, 0 MEDIUM.** Every
security-class hypothesis was REFUTED against the code: no signature/trust bypass, no write-outside-staging, no bomb
bypass, no 2FA bypass. The 10 confirmed items were all LOW/robustness; the cheap ones were applied so the "reversible"
claim is honest: the upgrade swap is now fully inside the try (restore + `finally` backup cleanup, no orphaned dir);
`commit()` runs under a per-slug `Cache::lock` (closes the `is_dir` TOCTOU); `fwrite` drains the full buffer; a fresh
install resets inherited consent; a >1 MiB manifest is rejected before decode; the ACP banner reflects the real
post-upgrade enabled state; and the ratio-cap docblock is corrected (the byte caps are the load-bearing fence). Full
suite after fixes: **2159 / 0** (see below); 2 new regression tests (atomic upgrade + oversized manifest).

### Environment note (per MEMORY)
`claude-fable-5` errored in this env — the two APEX reviews ran on **Opus 4.8** (the apex fallback). GD was hot-installed
into the running `forum-dev` container AND added to `docker/dev/Dockerfile` (rebuild picks it up permanently).

### ☀️ What the owner does next
1. **Review + merge** in order `baseline-green → nov-120 → U7 → U17` (apply the trivial additive overlaps above). Push is
   the owner's — Code pushed/merged nothing; `main` is untouched at `658508d`.
2. **Linear:** the auto-mode classifier blocked some issue writes from this session. Still to set: **NOV-120 → Done** on
   merge (currently In Progress, refutation comment posted); **NOV-76 → Done** (already fixed; a close comment was
   blocked); **NOV-106 / NOV-115 → In Progress → Done** on merge.
3. **Run Dusk in CI** (no Chrome here) for the new ACP surfaces (Embeds, module-install).
4. **Enabling U7** is a deliberate act (`embeds.enabled` / `NOVFORA_EMBEDS`); **U17 keeps `allow_unsigned=false`** in
   production (dev-only override).

---

## 🌅 Morning report — v1.x Polish-2 + a11y + targeted fixes — EXECUTED, MERGED, PUSHED (2026-06-22)

Ran [`docs/product/v1x-polish2-and-fixes-kickoff.md`](docs/product/v1x-polish2-and-fixes-kickoff.md) end-to-end,
unattended: built **Track F** (F1·F3·F5·F6·F4·F2) then **Track P** (P1·P2) — each an independent branch off
`main`, verified against `main` first, gated green at every boundary, committed as `Tommy Huynh` (DCO `-s`, no
AI trailers) — then **merged in the kickoff order, re-gated the union, lifted the F2 ADR, and pushed `origin
main`**. The release-zip **deploy is the owner's only remaining step** (below).

**Merged `main` HEAD: `37c9cb8`** (this report sits on top). 8 `--no-ff` merges in dependency order
(`F1·F3·F5·F6·F4·F2 → P1·P2`) + 3 integration commits (union test reconcile, asset rebuild, ADR-0101 lift).
Whole batch vs pre-merge `main` (`backup/pre-polish2` = `6b8a011`): **42 files, +1181 / −175**.

**Union gate (merged `main`, all GREEN):**
- `php artisan test --parallel` → **2128 passed / 1 skipped** (the Dusk CI-only spec) · **14 971 assertions**
- `pint --test` clean · `phpstan` **L5 → 0** (app/) · migrate **apply + rollback(all 91) + re-apply** clean
  (1 new reversible migration: `users.trust_locked`)
- `npm run build` OK → assets committed (`e2e470d`); ViteManifest / asset-budget gate green (app.css ≈ 61.5 KB,
  gz ≈ 11.6 KB; JS unchanged)
- **a11y page gate grown 27 → 30** surfaces (mod CP, per-member view, ACP analytics) — green; `route:clear` first
- **Dusk = CI-only** (no Chrome on the VPS) — specs unchanged, CI-pending

| Slice | Branch | Head | Gate | Notes |
|---|---|---|---|---|
| F1 remove ACP recents | `claude/fix-acp-recents` | `895d0f1` | Pint✓ AcpPolish 5✓ a11y 29✓ Admin 281✓ | view+lang+test only; no DB/route/JS backed it |
| F3 mod-CP width | `claude/fix-mod-width` | `3c24430` | Pint✓ ModTools 8✓ a11y 29✓ | dashboard/queue/reports `md→lg`; confirm-delete stays narrow |
| F5 Info Center | `claude/fix-infocenter` | `b4a599c` | Pint✓ PHPStan0 InfoCenter 15✓ perf✓ a11y✓ | collapsible (no-flash localStorage) + coloured who's-online; `recent()`→Eloquent coll + eager groups |
| F6 latest-activity ⚠perf | `claude/fix-latest-activity` | `ae7db33` | Pint✓ PHPStan0 HotPath✓ BoardTable✓ | last topic+author, bounded +3 const queries; HotPathQueryTest holds <25 |
| F4 report-review ⚠apex-adj | `claude/fix-report-review` | `634c4b1` | Pint✓ PHPStan0 ReportReview 4✓ leak✓ | post excerpt + permalink + gated mod actions; no private-club leak; Message reports stay bare |
| F2 trust/rep ⚠APEX | `claude/fix-manual-trust-rep` | `424cc6d` | Pint✓ PHPStan0 apex 6✓ trust/rep/members 187✓ migrate✓ | **review GO, 0 HIGH** (see below) |
| P1 design-critique | `claude/polish2-critique` | `a30f7d8` | Pint✓ ACP 281✓ Analytics+Mail 63✓ a11y✓ | email-editor dark-mode tokens; action-weighting; empty states |
| P2 a11y to AA | `claude/polish2-a11y` | `3838acb` | Pint✓ a11y 30✓ Admin 281✓ | gate 27→30; fixed the ACP-shell double-`main` (1.3.1) + 2.4.7 focus rings |

### F2 apex review (mandatory verify-then-refute, 15 agents) — **GO, 0 open HIGH**
The review confirmed **1 MEDIUM + 3 LOW** (3 candidates refuted), all **fixed before the F2 merge and
re-verified** (`424cc6d`):
- **MEDIUM** — the trust-group swap was non-transactional → a manual set racing the cron (or a 2nd admin) could
  transiently leave a member in TWO trust groups (resolver = permission UNION). Fixed with a shared
  `swapTrustGroup()` wrapping detach+attach+flush+denorm in a `DB::transaction` + `lockForUpdate` (closes the
  inherited `setLevel()` race too); a test asserts exactly one trust group after a set.
- **LOW ×3** — reputation audit `from→to` now read under the lock (truthful under concurrency; denorm+ledger were
  already exact); trust audit `actor_id` from the explicit `$actor`; `trustReason` server-side `max:500` cap.

### Conflict resolutions
**All merges auto-resolved — no manual hand-merge needed.** Git's `ort` strategy handled the one anticipated
overlap (**F2 × P1** on the per-member view `⚡manage.blade.php`): F2's new trust/reputation cards + P1's
action-weighting tweaks (lift-ban → neutral, warn → `danger-soft`) both landed (verified: 6 F2 markers + 2 P1
markers present). F5/F6/F4/P2 touched disjoint files. **One union-integration test fix** (`5abe586`): F6's
latest-activity column legitimately surfaces the LIVE last-post author on the index, so the activity-feed
index test's over-broad `assertDontSee('OrigPoster')` was dropped — the feed's no-leak is proven in isolation
by its sibling test ([Deleted] tombstone, `actor` null).

### New ADR
**ADR-0101** (F2 manual trust + reputation editing, apex) lifted into `DECISIONS.md` (`37c9cb8`); next-free was
0101 as expected (0095–0100 taken).

### ☀️ What the owner does next
1. **Pushed:** `origin main` was fast-forwarded to `37c9cb8`+ (this report). Nothing held back — F2 had 0 open HIGH.
2. **Go live (the only remaining step):** build the portable release per
   [`docs/product/live-deploy-kickoff.md`](docs/product/live-deploy-kickoff.md) (`scripts/build-release.sh` →
   `scripts/verify-release.sh`) and upgrade **demo.novfora.com backup-first**. This batch carries a **new
   migration** (`users.trust_locked`) + the F2 trust/reputation capability keys + audit actions, so it's the
   **cron auto-upgrade** path, not assets-only.
3. **Run Dusk in CI** (no Chrome on the VPS) — the existing specs cover the editor/journey paths; the new F2/F4/F5
   interactions are server-render + a11y-gated here.

---

## 🟢 v1.x batch + S5 owner-strand guard — MERGED to local `main`, gated GREEN, awaiting owner push (2026-06-22)

Ran [`docs/product/v1x-merge-and-owner-guard-kickoff.md`](docs/product/v1x-merge-and-owner-guard-kickoff.md): built the
apex **S5 owner-strand guard**, then merged the **full v1.x batch + S5** into `main` and re-gated the union. **Local
only — nothing pushed to `origin` yet.** Backup of pre-merge `main` at **`backup/pre-v1x` (`6862775`)**.

**Merged `main` HEAD: `4b4e64b`** (this report sits on top). 15 `--no-ff` merges in the kickoff's dependency order
(`R1→S3→R2 ; A1→A2→A3→S5 ; S1 ; S2 ; M1→M2→M3 ; T1→T3→T2`) + asset rebuild (`2413152`) + ADR lift (`4b4e64b`).
Whole batch vs pre-merge `main` (`8677b4b`): **120 files, +5828 / −1344**.

**Union gate (merged `main`, all GREEN):**
- `php artisan test --parallel` → **2111 passed / 1 skipped** (the Dusk CI-only spec) · **14 880 assertions**
- `pint --test` clean · `phpstan` **L max → 0** (app/) · migrate **apply + rollback(all) + re-apply** clean (2 new
  reversible migrations: `content_subscriptions`, `canned_replies`)
- `npm run build` OK → **assets committed** (`2413152`); ViteManifest / asset-budget gate green
- a11y page gate + Accessibility + PWA + SubdirInstall gates green (67) — `route:clear` first (the stale
  `routes-v7.php` subdir/PWA false-fail per MEMORY)
- **Dusk = CI-only** (no Chrome on the VPS) — specs present, CI-pending (unchanged from prior batches)

**Conflict resolutions (all additive — kept both sides unless noted):**
1. **R2** `design-polish-adrs-DRAFT.md` modify/delete → **deleted** (honoured R2's prune; the draft's ADRs are now
   lifted as 0093/0094). DECISIONS.md auto-merged with **no duplicate** 0093/0094.
2. **A3** `AdminAccessWalkTest` sentinel → kept A1 `/admin/members/all` **+** A3 `/admin/moderation/warning-types`.
3. **S5 × A2** `BanController` → routed user bans through A2's shared **`UserBanService`** **and** moved S5's locking
   owner-strand guard **into `UserBanService::ban()`** (the chokepoint both the bans page and the ACP member view
   share); BanController catches `OwnerStrandException`; S5's `spamClean` catch kept.
4. **T1 × M1/A3** reply-composer SFC (merged M1 quote + T1 canned: imports, `mount(?quote,?canned)`, both method
   sets); `topic.blade` (one `<livewire:forum.reply-composer>` with both `?quote` + `?canned`); AdminNavigation /
   `lang/en/admin` / `routes/web` (kept A3 warning-types **+** T1 canned-replies); sentinel got T1's line (all three).

**S5 apex review (mandatory verify-then-refute) — outcome:** the first pass confirmed **3 HIGH** the green suite
missed; **all fixed before commit and re-verified GO (0 open HIGH):**
- **H1** the predicate counted `group_user` rows, blind to a ban (which touches only `users`/`bans`) → counted
  already-banned owners as live (sequential *and* concurrent strand). **Fix:** count REACHABLE owners (exclude
  `status='banned'` OR a live global user-ban row).
- **H2** the sibling DELETE (`AccountDeletionService`) / DEMOTE (`AdminCoOwnerService`) guards shared the blind count
  (ban a co-owner, then remove the healthy peer). **Fix:** those guards now **delegate** to the shared ban-aware
  authority.
- **H3** `(int) is_co_owner === 1` mis-read PostgreSQL's `"t"`/`"f"` → co-owner protection silently off on a
  supported tier. **Fix:** SQL-side `where is_co_owner = true`, like the siblings.
Re-verification (4 lenses + adjudicator): **GO**, no new HIGH/MED; uniform `group_user → users → bans` lock order,
no deadlock.

**ADRs lifted into DECISIONS.md (0095–0100):** 0095 v1.x Feature Program (parent); 0096 ACP v4 member management
(apex); 0097 subscriptions — bounded/queued fan-out (apex); 0098 canned replies; 0099 admin email templates —
render-sandbox (apex); 0100 owner-strand ban/warn guard (apex).

**Flagged (pre-existing, NOT fixed — owner follow-ups, out of S5's ban scope):** `GroupManager::removeMember` guards
the co-owner tier but **not** the last PLAIN admin (an ADR-0086 removal-door gap); the now-unused
`AccountDeletionService::coOwnerIds(locked: true)` branch (LOW cosmetic).

**→ What the owner does next:** (1) `git push origin main` (fast-forwards `origin/main` from `6862775` → `4b4e64b`+).
(2) **Go live:** build the portable release per [`docs/product/live-deploy-kickoff.md`](docs/product/live-deploy-kickoff.md)
(`scripts/build-release.sh` → `scripts/verify-release.sh`) and upgrade **demo.novfora.com backup-first** — this bundle
carries **new migrations** (subscriptions, canned replies + the S5 guard), so it's the **cron auto-upgrade** path, not
assets-only.

---

## 🌅 Morning report — v1.x Feature Program EXECUTED (2026-06-22) — 14 branches off `main`, none pushed/merged

Ran [`docs/product/v1x-feature-program-kickoff.md`](docs/product/v1x-feature-program-kickoff.md) cold, unattended,
in order **R → S → A → M → T**. Each slice is an **independent branch off `main`** (two intentional stacks, noted
below), committed only at a fully-green gate boundary (`Tommy Huynh` identity, DCO `-s`, no AI trailers). **Nothing
pushed, nothing merged** — all 14 branches are local for owner review; `main` is untouched at `3b70653`. Gates run
on the **VPS native toolchain** (PHP 8.3 + MySQL; sqlite `:memory:` for the suite, isolated-sqlite for the migrate
gate). **Dusk could not run here** (no CI Chrome) — Dusk specs are written/extended + CI-pending.

| Slice | Branch | Head | Gate | Notes |
|---|---|---|---|---|
| R1 doc-trim | `claude/v1x-r1-doc-trim` | `be89224` | links✓ Pint✓ | PROJECT-STATE 1181→148 lines; milestone blocks → PROJECT-HISTORY |
| S3 lift ADRs | `claude/v1x-s3-adrs` | `27f7a50` | docs✓ | ADR-0093/0094 lifted into DECISIONS.md (detail blocks; index table is unmaintained past 0035) |
| R2 prune | `claude/v1x-r2-prune` | `a638a69` | suite 2005✓ migrate✓ links✓ | **stacked on R1+S3**; archived 45 kickoffs + index, deleted lifted draft, import-seed already gone |
| S1 attach harden | `claude/v1x-s1-attach-hardening` | `ddcb136` | suite 2007✓ Pint✓ PHPStan0 | prune reserves scheduled/draft refs (the ADR-0094 LOW) + storage-outage graceful-degrade |
| S2 Dusk CI | `claude/v1x-s2-dusk-ci` | `4730ad4` | php -l✓ Pint✓ | attach-zone + M0 no-scroll-trap specs added; **CI-pending (no Chrome here)** |
| S4 groups icons | — (none) | — | verify | **already merged to `main`** (`75d463a`/`8677b4b`) → gate-and-skip |
| A1 member table ⚠apex | `claude/v1x-a1-member-table` | `32d20c8` | 18✓ walk✓ suite 2022✓ | **review GO** — 1 MEDIUM (hidden-group leak) fixed in-session |
| A2 per-member view ⚠apex | `claude/v1x-a2-member-admin` | `7b6d2f6` | 13✓ suite 2038✓ | **stacked on A1**; **review GO** — fixed liftBan rank gap + added last-owner guard |
| A3 warnings ACP | `claude/v1x-a3-warnings-acp` | `a610c6b` | 8✓ walk✓ suite 2015✓ | warning-type CRUD + read-only thresholds |
| M1 quote-reply | `claude/v1x-m1-quote-reply` | `a51a02f` | 9✓ suite 2011✓ | blockquote+attribution+parent linkage; no JS rebuild |
| M2 subscriptions ⚠apex | `claude/v1x-m2-subscriptions` | `a62e283` | 9✓ migrate✓ suite 2016✓ | **review GO** — fail-closed hardening on the bounded+queued fan-out |
| M3 unread/excerpt/slug | `claude/v1x-m3-list-finish` | `2857bbd` | 6✓ HotPath✓ suite 2013✓ | excerpt + slug 301 (unread/dropdown already shipped → skipped) |
| T1 canned replies | `claude/v1x-t1-canned-replies` | `d18e9f8` | 10✓ walk✓ migrate✓ suite 2017✓ | ACP CRUD + composer ?canned insert (no JS rebuild) |
| T3 analytics charts | `claude/v1x-t3-analytics-charts` | `40f9506` | 5✓ suite 2012✓ | hand-authored inline-SVG sparklines; no asset rebuild |
| T2 email templates ⚠apex | `claude/v1x-t2-email-templates` | `517cd41` | 8✓ suite 2015✓ | **review GO** — reuses the SiteTemplate sandbox; no confirmed finding |

### Apex adversarial reviews (mandated; verify-then-refute, ~6 agents each) — all GO, no unresolved HIGH
- **A1** (member directory): 4 vectors refuted (authz, email-PII, SQL/orderBy, DoS); **1 MEDIUM fixed in-session** —
  the group filter leaked hidden-group names → gated behind the same `users.manage` ceiling as email (+ regression test).
- **A2** (per-member view): 5 vectors reviewed; **NEW MEDIUM fixed** (liftBan lacked the rank guard) + the kickoff's
  **last-owner guard added** (can't ban/warn the sole admin/co-owner). **FLAGGED, pre-existing (NOT new to A2):** the
  FRONT-END ban/warn paths (`BanController` + `WarningService` auto-ban consequence) still lack the last-owner guard —
  the strand is reachable there independently; fix belongs in `UserBanService::ban` + `WarningService` (mirror
  `AccountDeletionService`'s locked sole-admin/co-owner guards). **Engine fast-follow.**
- **M2** (subscription fan-out): 5 vectors; the soft-deleted-forum HIGH was **refuted as structurally unreachable**
  (`StructureService::delete` reparents all topics to a live board first) — applied the **fail-closed hardening anyway**
  (job returns when the forum can't be loaded) + a regression test.
- **T2** (email render-injection): 5 vectors, **no confirmed finding** — the SiteTemplate sandbox (escape + char-allowlist
  + helper registry + skeleton lint) holds; subject is code-controlled + CRLF-stripped. Non-blocking nit: the global
  context also merges `site.description`/`user.*`/`stats.*` (escaped, harmless) — left undeclared as not email-meaningful.

### ⚠ Environment finding (corrects the prior "2 inherited reds")
The `SubdirInstall` + `Pwa` subpath test failures were a **stale route-cache artifact** (`bootstrap/cache/routes-v7.php`),
not a code bug: `php artisan route:clear` makes the suite fully green (2008+ pass / **0 fail** / 1 skip). **The CI/gate
must `route:clear` (or not ship a cached route table) before testing.** Every per-slice suite count above is post-clear.

### ADRs (PROPOSED — referenced in commits, NOT lifted beyond S3's 0093/0094)
**0095** v1.x Feature Program (parent) · **0096** ACP v4 member-management (A1/A2/A3) · **0097** subscriptions (M2) ·
**0098** canned replies (T1) · **0099** email templates (T2). Lift into `DECISIONS.md` on ratification (S3 already lifted
0093/0094 as detail blocks — the index table stopped at 0035, so recent ADRs are detail-block-only).

### Cross-branch merge notes (all additive; hand-merge where flagged)
- **R-track stacking:** R2 contains R1+S3 (the kickoff makes R2 depend on S3's lift; R1's history move is needed for link
  integrity). Merge R1, S3, then R2 — or just R2 (it carries all three).
- **A-track:** A2 is stacked on A1 (A2 "rides A1's row" — the Manage link is live end-to-end). Merge A1 then A2; A3 off `main`.
- **`reply-composer` / `topic.blade.php`:** M1 (?quote), T1 (?canned), M2 (subscribe button) each extend the composer mount
  + the topic embed → a small hand-merge (all additive; keep all params + one `#reply-composer` anchor).
- **`AdminAccessWalkTest` sentinel:** A1, A3, T1 each add a `toContain('/admin/...')` after the co-owners line → trivial both-add.
- **`AdminNavigation` / `lang/en/admin.php` / `routes/web.php`:** A1/A3/T1 add adjacent nav items + lang keys + routes → adjacent, conflict-free or trivial.

### ☀️ What the owner does next
1. **Review + merge** the 14 branches (`git diff main..<branch>`). Suggested order: **R1 → S3 → R2** (hygiene), then
   **A1 → A2 → A3**, **M1 → M2 → M3**, **T1 → T3 → T2** — apply the cross-branch merge notes above.
2. **Lift ADRs 0095–0099** into `DECISIONS.md` on ratification (S3's 0093/0094 are already in).
3. **Run Dusk in CI** (S2's attach-zone + scroll-trap specs + the slug-tolerant moderation path; M1/M2 interaction).
4. **Make the gate `route:clear`** (the env finding above) so the subdir/PWA cases pass in CI.
5. **Engine fast-follow (flagged HIGH-class, pre-existing):** add the sole-admin/co-owner guard to the BAN/WARN engine
   (`UserBanService::ban` + `WarningService::applyConsequence`) so the front-end mod CP can't strand the owner tier either.
6. **Push/merge are the owner's** — Code pushed/merged nothing; `main` is untouched.

---

## ▶ ACTIVE TASK (EXECUTED 2026-06-22 — see the morning report above) — v1.x Feature Program

Executed [`docs/product/v1x-feature-program-kickoff.md`](docs/product/v1x-feature-program-kickoff.md) end-to-end in
order (**R → S → A → M → T**). Independent branch per slice off `main`, gated, committed at a green boundary,
**nothing pushed/merged** (owner reviews). Verified each slice against current `main` first and preferred the codebase.
Apex slices (A1/A2 member data + ban/warn, M2 subscription fan-out, T2 email-template render) each got an in-session
adversarial verify-then-refute review — all GO (no unresolved HIGH).

> Provenance: `docs/product/audit-ips-gap-analysis-2026-06-22.md` (gap map + code anchors) +
> `docs/product/archive/design-polish-kickoff.md` (which deferred these as functional). ADR allocation: parent **0095**
> (v1.x Feature Program); children **0096** ACP v4 member-management (A1/A2/A3), **0097** subscriptions (M2),
> **0098** canned replies (T1), **0099** email templates (T2). Confirm next-free before lifting.

---

## 🌙 Unattended session 2026-06-22 — Design-Polish Program (EXECUTED: 6 branches off `main`; none pushed/merged)

**Executed cold** by an unattended Code run from `docs/product/archive/design-polish-kickoff.md` (order **0 → 1 → 3 → 4 → 5 → 2**,
the apex last). Each slice is an **independent branch off `main`**, committed only at a fully-green gate boundary
(`Tommy Huynh` identity, DCO `-s`, no AI trailers). **Nothing pushed, nothing merged** — all six branches are local
for owner review. Gates run on the **VPS native toolchain** (PHP 8.3 + MySQL; no Docker). **Dusk could not run here**
(no CI Chrome — per the standing note); Dusk specs were written/extended and are **CI-pending**.

| # | Slice | Branch | Head | Gates (local) |
|---|---|---|---|---|
| 0 | M0 scroll-trap hotfix | `claude/polish-m0-prose-scope` | `cc6e8e5` | Pint ✓ · Pest 1959 ✓ · drift ✓ · Dusk contract preserved (CI) |
| 1 | Design-system foundation (Pillar 1) | `claude/polish-p1-design-system` | `4741d36` | Pint ✓ · PHPStan 0 ✓ · Pest 1974 ✓ · drift ✓ |
| 3 | Editor toolbar + schema (Pillar 4) | `claude/polish-p4-toolbar` | `0eb5b53` | Pint ✓ · PHPStan 0 ✓ · Pest 1973 ✓ · drift ✓ · Dusk extended (CI) |
| 4 | ACP navigability + polish (Pillar 2) | `claude/polish-p2-acp` | `bd8bf45` | Pint ✓ · PHPStan 0 ✓ · Pest 1964 ✓ · drift ✓ |
| 5 | Member-experience polish (Pillar 3) | `claude/polish-p3-member` | `7865f91` | Pint ✓ · PHPStan 0 ✓ · Pest 1963 ✓ · drift ✓ · Dusk (CI) |
| 2 | Editor attachments — **APEX** | `claude/polish-p4-attachments` | `6d7f636` | Pint ✓ · PHPStan 0 ✓ · Pest 1969 ✓ · drift ✓ · no migration · **adversarial review on record** |

All six end **runnable + green on the Baseline tier**; all gz asset budgets hold (editor island ≈131 KB, CSS ≈10.4 KB).

### Slice-2 apex review (mandated per-finding verify-then-refute — 11 vectors, independent refutation)
**0 HIGH ⇒ no halt.** The pre-existing backend was already serve-gate-hardened (P5.1 IDOR/club/trashed/orphan gate,
MIME allowlist, off-webroot random-name storage, nosniff); this slice closed the remaining gaps and the review
probed MIME/extension confusion, SVG/script, path-traversal, IDOR, private-club leak, unauth, rate-limit bypass,
association hijack, and re-encode bypass — **all refuted**. Two residuals:
- **1 MEDIUM — FIXED in-session + regression-tested.** The decompression-bomb fence was **per-side only**, so a
  square bomb (11999×11999 ≈144 MP, 446 KB → ~549 MB GD alloc *outside* `memory_limit`) slipped it → worker OOM.
  Fixed with a pre-decode **total-pixel** budget (`max_source_pixels`, ≈25 MP); test rejects a square that passes
  the per-side fence.
- **1 LOW — owner follow-up (NOT fixed; out of apex scope).** The orphan-prune cron can delete a **scheduled**
  reply's attachment, because the scheduled-post subsystem (`PostScheduler`/`ScheduledPost`) doesn't reserve
  attachments — so a reply scheduled beyond `orphan_prune_hours` loses its image at publish. **→ closed by v1.x
  S1.**

### ADRs (PROPOSED — lifted by v1.x S3 into `DECISIONS.md` as 0093/0094)
**ADR-0093 = Design-Polish Program** (parent) · **ADR-0094 = the attachment subsystem** (apex). The draft
`docs/product/design-polish-adrs-DRAFT.md` (numbered 0092/0093) is corrected on lift and then deleted by v1.x R2.

### Cross-branch overlaps to expect on merge (independent branches, all additive)
Slices **0/2/3** touch the `app.css` editor region; Slices **2/3** both touch `novfora-editor.js`, `island.js`,
and `content-editor.blade.php` (toolbar vs file-node/attach-zone — will conflict, need a hand-merge). Slices
**1/4/5** touch different `app.css` regions.

### What the owner does next
1. **Review each branch** (`git log`/`git diff main..<branch>`); independent — **merge in any order**. Slice 0/1
   are the safest first merges; Slice 1 unblocks the `x-ui.*` reuse.
2. **Expect a hand-merge between Slices 2 and 3** (shared editor files).
3. **Lift ADR-0093/0094** into `DECISIONS.md` — **done by v1.x S3** (renumbered from the draft's 0092/0093).
4. **Run Dusk in CI** for Slices 0/2/3/5 (editor journey + attach interaction + roving-tabindex).

---

## ✅ VALIDATE-BEFORE-GO-LIVE (consolidated — carried from Phase 4/5 + enhanced-tier validation)

Scaffolded/disabled-by-default; unit-tested against fakes only. Enable + validate per the named ADR /
`docs/product/release-checklist-1.0.md`. (Full Phase-5 narrative → `PROJECT-HISTORY.md`.)

1. **Meilisearch** (ADR-0060) — **PROVEN 2026-06-19** against a live engine (no private-club leak held; degrades to
   DB on outage). Recommend `SCOUT_QUEUE=true` on Enhanced so a transient engine outage degrades on writes too.
2. **Reverb realtime** (ADR-0061/0062) — **PROVEN 2026-06-19** (id-only payload over a live socket; unauthorized
   subscriber 403 at `/broadcasting/auth`). Production WSS needs an nginx proxy → `127.0.0.1:8090`.
3. **Live Stripe** (ADR-0065 + P5.1) — real keys/webhook; grant only on `payment_status=paid`; add `invoice.*` /
   cancellation before auto-renewal. **Still deferred** (needs a Stripe account).
4. **OAuth / SAML** (ADR-0053–0056) — real apps; the no-merge rule + the **staff-2FA step-up** end to end. **Deferred.**
5. **Web Push** (ADR-0058) — VAPID; live push-service round-trip. **Deferred.**
6. **StopForumSpam submission** (ADR-0069) — optional; key + the content-privacy opt-in. **Deferred.**
7. **Load test at scale** (ADR-0045/0074) — k6/artillery on a real baseline + enhanced host; capture p50/p95/p99
   vs the SLOs; `EXPLAIN` the forum-listing sort. **Deferred.**
8. **Manual a11y** (ADR-0044) — contrast (1.4.3, incl. admin custom theme tokens) · keyboard nav + no focus traps
   (2.1.1/2.1.2) · visible focus (2.4.7) · reduced-motion (2.3.1) · live-region status (4.1.3) · a screen-reader +
   RTL visual pass on clubs/PMs/memberships. (`docs/architecture/accessibility.md`.) **Owner/QA.**
9. **PWA under a `/community/` subpath** (ADR-0078) — install prompt + SW registration scope + the blue-N icon on a
   real device/host (not machine-verifiable here).

**Redis cache/queue** path (DB 1 + `novfora-queue` worker) was also proven live 2026-06-19.

---

## 📌 Open follow-ups (deferred, not blocking)

- **Design-Polish:** review + merge the 6 unmerged branches (above); hand-merge Slices 2↔3; run Dusk in CI.
- **Group clone button on the live demo** — code correct on `main` (PR #43); suspect stale compiled-Blade /
  opcache on Hostinger, or the checked group not `type='custom'`. Next demo cycle: confirm deploy, `view:clear` +
  opcache reset, verify the group's `type` column.
- **`novfora:trust:recompute --user`** prints the generic summary, not the per-user reason (engine correct; print
  is terser). Small polish.
- **Pending-member exit-ramp** — the systemic fix for the `status=pending` false-flag ("Dan") is spec'd at
  `docs/product/pending-member-review-kickoff.md` for a future cycle.
- **Pre-existing inherited test reds (env-sensitive, not a regression):** `SubdirInstallTest` +
  `PwaTest` subpath-scope cases fail under the VPS native runtime (base-path routing under a simulated `/community`
  mount → 404). Untouched by v1.x slices; tracked, not chased here. Plus the standing **asset-budget drift**
  (rebuild `public/build`) + **composer-audit** transitive `guzzlehttp` advisories (bump in a maintenance commit).

---

## Orientation (short form — full detail in `CLAUDE.md` + `PROJECT-HISTORY.md`)

**NovFora** (name locked 2026-06-10, ADR-0026) — open-source (**Apache-2.0**), self-hosted forum/community platform;
**Laravel 13 + Livewire 4 + Alpine.js + Blade**, server-rendered, PHP 8.3 floor; MySQL 8 / MariaDB default,
PostgreSQL on Docker/VPS; Vite prebuilt assets (no host Node). **Two tiers from one codebase** (baseline shared PHP
host / enhanced Docker-VPS); WYSIWYG-first editor; phpBB-grade permission masks; strict clean-room.

**Status:** shipped **1.0.0 (GA)** — Phases 0–5 + the full ACP v3 program complete. Current work is **post-1.0
increments**: the Design-Polish Program (above, unmerged) and the **v1.x Feature Program** (active task, above).

**How we work:** Claude Code builds (plan-before-code per phase); Claude Cowork does knowledge work (no app code);
don't run both against the working tree at once; commit between handoffs. Two stages, gated.

**Working rules** (full in `CLAUDE.md`): strict clean-room · progressive enhancement (no Redis/queue/Reverb/Meili/S3
hard-dep — detect + degrade) · reversible migrations · security by default · tests with every feature · semver'd
module/theme API · conventional commits + ADRs · commit identity `Tommy Huynh <tommy@saturnhq.net>` + DCO `-s`, no
AI trailers.

**Model & effort** (full in `CLAUDE.md §Model routing`): `ultracode` default — start at **Fable @ max** (apex),
downgrade as fit when work is pattern-replication. Fable @ max for permission/security/concurrency core, adversarial
reviews, spikes, API design; Opus 4.8 `xhigh`/`high` below the apex; Sonnet 4.6 for CRUD/scaffolding/breadth sweeps
(Explore sub-agents). Docker/native gates are free — verify with `pest`/`pint`/`phpstan`, not by re-reasoning. Never
re-read a file you just edited. Cap gate output (`tail`).
