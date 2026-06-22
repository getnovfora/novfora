# Unattended batch — 2026-06-21 — Master program (run cold, run long)

> Handoff spec. This is the **single entry point** for a long unattended Code session. It runs **5
> independent branches off `main`** — each a self-contained branch → gates → PR — so nothing here
> conflicts with anything else, and one branch failing never blocks the rest. Branches 1–3 have their
> own detail specs (linked below); Branches 4–5 are fully inline here. Built on the demo findings from
> the live `demo.novfora.com` shakeout + two carried-over nits. Git on the VPS (`~/novfora`), native gates.
>
> **Heads-up that shaped this batch:** OAuth/social login and StopForumSpam registration screening are
> **already fully implemented** on `main` (Socialite + Google/GitHub/Discord + `social_accounts` +
> `SocialLogin`; `RegistrationGuard` + `StopForumSpamClient` + honeypot/timing/CAPTCHA). **Do not
> rebuild them.** Branch 4 only hardens their edges. The live-key cutover (real Google/Discord/SFS
> credentials) is a separate human task — out of scope for unattended Code.

## How to run this batch (unattended discipline — read first)

1. **One branch at a time, each off the latest `main`:**
   ```bash
   cd ~/novfora && git checkout main && git pull --ff-only
   git checkout -b claude/<branch-name>
   ```
   The branches are independent (all off `main`), so there are **no inter-branch dependencies** — but
   do them in the numbered order below.
2. **Gate every branch before its PR** (deterministic, free — the correctness signal):
   ```bash
   ./vendor/bin/pest ; ./vendor/bin/pint --test ; ./vendor/bin/phpstan
   ```
   Cap output (`tail -n 40`). Prefer "write → run gate → read tail → fix" over reasoning to perfection.
3. **One PR per branch to `main`. Do NOT merge** — Tommy / the Cowork side reviews and merges (the
   apex-sensitive ones get an adversarial review there). Push the branch and open the PR.
4. **Park-and-continue rule (critical for an unattended run):** if a branch can't reach green after a
   genuine effort, **commit the WIP, push, open a *draft* PR with a `## BLOCKED:` note explaining
   exactly where it stuck, then move on to the next branch.** Never let one stuck branch end the
   session — the whole point is to bank the branches that *do* land.
5. **Commit hygiene:** small conventional commits, one logical change each; `-s` (DCO); authored **and**
   committed as `Tommy Huynh <tommy@saturnhq.net>`; **no AI co-author/attribution trailers**. Clean-room;
   Apache-2.0-compatible deps only (none expected in this batch).
6. After each PR opens, append a line to `PROJECT-STATE.md` under a new
   `## Unattended batch 2026-06-21` heading: branch, PR #, status (landed / blocked + why).

## Model routing (ultracode default; per-branch floor)

| Branch | Work | Floor |
|---|---|---|
| 1 — admin & perm mgmt | group/role **clone** writes `acl_entries` → apex; add-admin links + UX are Sonnet | **Fable @ max** for `clone()` + the `AclVersion` bump reasoning; Sonnet for the link/UX scaffolding |
| 2 — post-approval / trust | moderation-layer correctness, no `acl_entries` | **Opus `high`** (correctness-load-bearing, not apex) |
| 3 — Recent Activity | a **permission-visibility** leak (`scope_forum_id` null) → apex-adjacent | **xhigh** for the visibility filter; Sonnet for the limit/UX bits |
| 4 — OAuth/SFS hardening | untrusted-input boundaries (registration guard, OAuth callback) | **xhigh** |
| 5 — release tooling | shell/CI scaffolding, no correctness seam | **Sonnet** |

Start each turn at the top of the stack and downgrade as the work proves to be pattern-replication.

## The branches

### Branch 1 — Admin & permission management → `claude/admin-perm-mgmt`
Add-admin discoverability fix + group/role **clone** + member/group UX cleanup. **Detail spec:**
`docs/product/admin-perm-mgmt-kickoff.md` — read and execute that, then PR. (The clone is the apex
seam; the rest is UX.)

### Branch 2 — Post approval / trust promotion ("Dan") → `claude/post-approval-promotion`
A long-time poster stays in the manual-approval queue. **Detail spec:**
`docs/product/post-approval-promotion-kickoff.md` — read and execute, then PR.

### Branch 3 — Recent Activity fixes → `claude/activity-feed-fixes`
Empty/sparse feed for restricted viewers, a hard-delete visibility leak, profile-tab limit. **Detail
spec:** `docs/product/activity-feed-fixes-kickoff.md` — read and execute, then PR.

### Branch 4 — OAuth / SFS hardening nits (inline) → `claude/oauth-sfs-hardening`
**xhigh — these are untrusted-input boundaries. Small, surgical changes only; do NOT redesign the
working features.**

1. **Rate-limit the OAuth redirect.** Confirm whether `GET /auth/{provider}/redirect` (route name
   `oauth.redirect`, `routes/web.php` ~L81–86) is inside a `throttle:` group. If it is **not**
   throttled, add `throttle:30,1` (match `oauth.link`/the callback). If it already is, leave it and
   note so in the PR. A bot hammering `/auth/google/redirect` should hit a limiter.
2. **Make the ACP SFS toggle actually control the live API.** `RegistrationGuard` (`app/AntiSpam/
   RegistrationGuard.php`) currently decides whether to call StopForumSpam from
   `config('novfora.antispam.registration.stopforumspam.enabled')`, while `ExternalSignalPolicy::
   apiEnabled()` reads the DB setting `antispam.sfs_use_api` (the operator-facing knob). Route the
   live-API enablement decision through `ExternalSignalPolicy::apiEnabled()` so the documented setting
   is authoritative. **Preserve the fail-safe**: API disabled or down → the guard still FLAGs via cache/
   degrade, never silently ALLOWs. It already uses `ExternalSignalPolicy::confidenceThreshold()`, so this
   only aligns the enable-check with the threshold-check. Add/extend a test: setting OFF → no live call
   but disposable/ban/cache checks still run; setting ON → live call path exercised (HTTP faked).
3. **Confirm provider registration.** Verify `App\Providers\SocialiteServiceProvider` is listed in
   `bootstrap/providers.php` (it registers the Discord driver via `Socialite::extend`). If missing, add
   it; if present, no-op. A quick feature test that `SocialProviders::driver('discord')` resolves
   without error (credentials faked) guards this.

Gates green; small commits; PR. If item 2's intent is at all ambiguous (two knobs that might be
*deliberately* separate), implement the alignment but call it out explicitly in the PR for review
rather than guessing silently.

### Branch 5 — Release-tooling cleanup (inline) → `claude/release-tooling`
**Sonnet.** First: `git branch -a | grep release-script-exec-bit` — a `chore/release-script-exec-bit`
branch may already exist with some of this. If so, base the work on it / finish it; otherwise start
fresh off `main`.

1. **`verify-release.sh` exits 143 on PASS.** The `EXIT` trap does `kill ${SV:-0}` → when `SV` is unset
   this is `kill 0`, which SIGTERMs the whole process group (the script itself) → exit 143 even on a
   clean PASS. Fix: capture the preview-server child PID into `SV` immediately after launching it,
   guard the trap (`[ -n "${SV:-}" ] && kill "$SV" 2>/dev/null || true`), and ensure the script
   **`exit 0` on PASS**. Verify: `bash scripts/verify-release.sh novfora-release.zip; echo "rc=$?"`
   prints `RELEASE_VERIFY=PASS` and `rc=0`.
2. **Exec bits.** Ensure `scripts/build-release.sh` and `scripts/verify-release.sh` are tracked
   executable (`git update-index --chmod=+x scripts/*.sh`); confirm `git ls-files -s scripts/` shows
   mode `100755`.
3. **`.gitignore` the build artifact.** Add `novfora-release.zip` (and any `release-staging/` temp dir)
   to `.gitignore` so a local build never gets committed.
4. **Asset-drift CI guard (RH-5).** Add a CI job/step (GitHub Actions, `.github/workflows/`) that
   rebuilds front-end assets and **fails if `public/build` differs from what's committed** — the stale-
   prebuilt-asset trap. Minimal form: `npm ci && npm run build && git diff --exit-code public/build`.
   Keep it a separate job so it doesn't gate the PHP suite. If a Node toolchain isn't already in CI,
   add the smallest `actions/setup-node` step needed; don't restructure existing workflows.

Gates green (PHP suite unaffected); small commits; PR.

## Global verification / done

Each branch: its own gates green (or a clear `BLOCKED:` draft PR); each fix re-verified against the
*current* code, not the spec's description; the apex items (Branch 1 clone, Branch 3 visibility) have
explicit tests proving they don't mis-grant / don't leak. One PR per branch to `main`, **none merged by
Code**. `PROJECT-STATE.md` updated with the batch outcome. Branches that land are independent, so Tommy
can merge them in any order on the Cowork side.

Read docs/product/batch-2026-06-21-kickoff.md and execute it (following the per-branch detail specs for Branches 1–3).
