<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# `claude/phase-5-ga` → `main` — merge / conflict-resolution checklist (for Code)

> **Context.** `claude/phase-5-ga` is the 1.0.0 GA branch (auth/error i18n, `es` locale, perf + a11y gates,
> nevo→novfora rename + CI brand gate, a batch of security fixes, 1.0.0 + CHANGELOG). It **diverged from
> `main` before RH-4** (≈10 behind / 18 ahead), so this is a real 3-way merge, not a fast-forward. Resolve by
> updating the branch with `main`, then merging the PR.
>
> **Do this AFTER `git push origin main` succeeds** so `origin/main` already contains RH-4 (the 10 commits).
> Run the gate in `forum-dev`. This branch is GA + security — it gets a real review and a green gate, not a
> rubber stamp.

---

## 0. Setup + a safety net

```bash
cd /d/Forum
git fetch --all --prune
git checkout claude/phase-5-ga
git tag pre-merge-phase5ga-backup          # instant rollback point: git reset --hard pre-merge-phase5ga-backup
```

## 1. Get the AUTHORITATIVE conflict list (don't trust this doc's list blindly)

```bash
base=$(git merge-base claude/phase-5-ga origin/main)
echo "merge base: $(git log --oneline -1 $base)"
# files touched on BOTH sides = the real candidate-conflict set:
comm -12 <(git diff --name-only $base origin/main | sort) \
         <(git diff --name-only $base claude/phase-5-ga | sort)
```

Use that output as the source of truth; the sections below are the annotated expectation so you know *how*
to resolve each, not *whether* it conflicts.

## 2. Start the merge

```bash
git merge --no-ff --no-commit origin/main
git diff --name-only --diff-filter=U          # the actual conflicted files
```
(`--no-commit` lets you stage resolutions and inspect before committing. Ours = `phase-5-ga`, theirs = `main`.)

---

## 3. Expected conflicts + how to resolve each

### A. Living docs — **guaranteed conflicts. Resolution = UNION, never pick-a-side.**
`PROJECT-STATE.md`, `ROADMAP.md`, `DECISIONS.md` are append-mostly logs; both branches added different
sections. Keep **both** sides' additions, in chronological/sensible order; delete only the conflict markers.
- `PROJECT-STATE.md`: keep main's **RH-4** handoff block *and* phase-5-ga's **Phase 5 GA** block. Then add a
  fresh top note that RH-4 + Phase-5-GA are both now on `main`.
- `ROADMAP.md`: keep both the RH-4 row/line and the Phase-5 (1.0 GA) updates.

### B. **ADR-0070 numbering collision — must renumber (confirmed from commit subjects).**
`main` already owns **ADR-0070 + ADR-0071** for the subdirectory install (RH-4 `941485f`). phase-5-ga's
`ba7395a` records the **Phase-5 adversarial review as "ADR-0070"** — a duplicate. Resolve by:
1. Keep main's 0070/0071 (subdir install) as-is.
2. **Renumber the phase-5-ga Phase-5-security-review ADR** to the next free number after a fresh scan:
   ```bash
   git show origin/main:DECISIONS.md | grep -oE 'ADR-[0-9]{4}' | sort -u | tail -5   # highest taken
   grep -rno 'ADR-0070' DECISIONS.md docs/ ROADMAP.md PROJECT-STATE.md               # every reference to fix
   ```
   Assign it the next unused number (expected **ADR-0072**) and update **every** cross-reference (commit
   bodies can stay; docs must be consistent).
3. **Heads-up for the follow-on UI/UX work:** `docs/product/ui-ux-fixes-spec.md` proposes "ADR-0072" for the
   info-center. If the Phase-5 review takes 0072, bump the info-center spec to the next free number when that
   work starts. Final check (must print nothing):
   ```bash
   grep -hoE 'ADR-[0-9]{4}' DECISIONS.md | sort | uniq -d
   ```

### C. **Probable code conflicts** (both sides edited install/routing surfaces):
- `routes/web.php` — RH-4 `126b020` moved `forums.index` to `/` and 301'd `/forums`; phase-5-ga security
  commits (`794497e` locked-topic/maintenance, `994e867` attachment route) may touch routes. **Keep RH-4's
  root-routing AND phase-5-ga's new guards** — they're orthogonal; merge both hunks.
- Installer files (`app/Install/*`, `InstallRunner`, the installer controller/Livewire) — RH-4 added subpath
  awareness + the `EnvWriter` `$`-escape; phase-5-ga added the pre-install setup token + DB-test SSRF
  re-assert (`b56cb71`, `85bbf1c`). Both are security-relevant — **keep both**; verify the `EnvWriter`
  `$`-escape (RH-4 apex fix `612368f`) survives.
- `config/app.php` — RH-4 added `asset_url`; phase-5-ga's 1.0.0 bump may set version/name. Keep both keys.
- `.env.example` — RH-4 added `ASSET_URL`/`NOVFORA_PUBLIC_LINK` notes; keep alongside any phase-5-ga additions.
- `.gitignore` — main's `0256fee` ignores the forum-seed experiment; union with any phase-5-ga entries.
- `CHANGELOG.md` — phase-5-ga only (likely no conflict); ensure RH-4 (ADR-0070/0071) is represented under 1.0.0.

### D. **No-conflict, phase-5-ga-only** (take as-is): `lang/**`, the auth/error Blade views,
`docs/architecture/i18n-and-rtl.md`, `tests/Feature/I18n/LocalizationTest.php`, the perf/a11y test gates.

---

## 4. Commit the merge + verify (all must be green)

```bash
git diff --name-only --diff-filter=U          # MUST be empty before committing
git commit                                    # keep the default merge message
docker.exe exec forum-dev php artisan migrate --force
docker.exe exec forum-dev php artisan test --parallel    # expect prior GA count + RH-4 tests, 0 failed
docker.exe exec forum-dev ./vendor/bin/pint --test
docker.exe exec forum-dev ./vendor/bin/phpstan analyse   # level 5, 0 errors
grep -hoE 'ADR-[0-9]{4}' DECISIONS.md | sort | uniq -d    # MUST print nothing (no dup ADRs)
```
Also run the **CI brand gate** locally (phase-5-ga added it — it fails on stray `nevo`): confirm it passes on
the merged tree before pushing, and that it doesn't trip on RH-4's docs.

## 5. Land it

```bash
git push
gh pr merge --merge        # (PR claude/phase-5-ga -> main, opened in the sync plan)
git checkout main && git pull
```

## 6. Then the live host (fixes the login tokens)
Redeploy `main` to `dev.novfora.com` **including the `lang/` directory**, then `php artisan optimize:clear`.
Confirm `ls -l lang/en/auth.php` exists on the host afterward and `/login` renders "Sign in", not
`auth.login.title`.

---

### Rollback
If the gate is red and you want out: `git merge --abort` (mid-merge) or
`git reset --hard pre-merge-phase5ga-backup` (after committing). The backup tag makes this risk-free.
