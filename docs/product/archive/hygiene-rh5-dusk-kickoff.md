<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# Hygiene closeout (RH-5 + Dusk enforce-ON split) — Claude Code kickoff

> Small, surgical run before the theme phase: rebuild the stale committed assets with a CI guard so they can
> never drift again (RH-5), and split the Dusk harness so the installer journey runs under real pre-install
> enforcement (the RH-7 follow-up). No product features.

---

```
Close out two hygiene items: RH-5 (stale committed assets + CI freshness guard) and the Dusk enforce-ON
harness split. Investigate → fix → verify. No new product features, no theme work (that is a separate,
upcoming phase).

STEP 0: read PROJECT-STATE.md and docs/product/real-host-findings.md. main should contain the RH-7/8/9 work
(1ccf787 or later) — all three are FIXED and validated on the live host. Confirm the suite is green and the
tree is clean. COMMIT IDENTITY (mandatory, per CLAUDE.md): run
  git config user.name "Tommy Huynh" && git config user.email tommy@saturnhq.net
BEFORE your first commit — sandbox defaults such as "Claude <noreply@anthropic.com>" must never appear in
history. DCO sign-off (-s); no AI attribution.

PART 1 — RH-5: rebuild committed assets + a CI guard so drift can't recur
  CONTEXT: /public/build is committed BY DESIGN (baseline shared hosts have no Node). A P1.5 template change
  was never rebuilt, so the committed CSS hash drifted from source — it muddied the RH-4 diagnosis and would
  ship stale styling to any git-based deploy.
  • npm ci && npm run build; commit the resulting public/build diff (manifest + hashed assets) as a
    chore(assets) commit.
  • Add a freshness guard to the existing CI workflow: npm ci && npm run build, then
    `git diff --exit-code -- public/build` so CI FAILS whenever committed assets are stale relative to
    source. Keep it a cheap, separate job/step with a clear name (e.g. "assets-fresh").
  • Document the rule in CONTRIBUTING.md ("UI changes must rebuild + commit public/build; CI enforces").
  • Sanity-check a rendered page references hashes that exist on disk (there may already be a manifest test —
    extend rather than duplicate).

PART 2 — Dusk enforce-ON split (the RH-7 follow-up, currently noted in the findings doc)
  CONTEXT: docker/dusk serves ONE app with NOVFORA_INSTALL_ENFORCE=false because EditorJourneyTest needs a
  reachable installed app — so InstallerWizardTest never exercises real pre-install enforcement in a browser.
  The enforcement-ON feature tests (InstallerEnforcedLivewireTest) remain the authoritative regression; this
  adds the real-browser belt to the suspenders.
  • Split the harness into two serve passes/profiles:
      (a) INSTALLER pass — enforcement ON, no install marker, fresh DB → run InstallerWizardTest (the full
          wizard, whose every wire:click now flows through RedirectIfNotInstalled exactly like production);
      (b) APP pass — installed state → run EditorJourneyTest (unchanged behavior).
  • Wire both passes into docker/dusk/run.sh (sequential is fine) and the CI Dusk job if one exists. Each
    pass gets the correct env + DB state; no shared-state leakage between them.
  • If the container/CI cannot run browsers here, implement + document the harness change and report what
    could not be executed — do not silently skip.

DELIVER:
  • Full Pest suite + gates green (Pint / Larastan / composer audit). Dusk passes if runnable here.
  • Since public/build changed: rebuild scripts/build-release.sh + run scripts/verify-release.sh (cold boot →
    302 /install); report the new novfora-release.zip size + sha256 and surface the artifact (stays gitignored).
  • Docs: real-host-findings.md → RH-5 FIXED (and note the Dusk split landed under the RH-7 entry);
    PROJECT-STATE.md updated.
  • Small conventional commits, DCO-signed AS TOMMY (see STEP 0), pushed.

SCOPE FENCE: assets rebuild + CI guard + Dusk harness split + docs only. Nothing else.
```

---

## After this

The hygiene board is clear and the next phase begins: the **default theme / UI polish pass** — see
[theme-design-brief.md](../theme-design-brief.md) for the agreed direction. Remaining real-host item afterward:
**RH-4** (first-class subdirectory install — design spike + ADR first).
