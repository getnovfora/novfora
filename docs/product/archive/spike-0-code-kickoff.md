<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# Spike 0 — Claude Code kickoff prompt

> Paste the block below into the **Claude Code** session to execute Spike 0. It pairs with the deterministic
> spec in [spike-0-handoff.md](../spike-0-handoff.md) (§4 of [phase-1-plan.md](../phase-1-plan.md)).
> **Pressure-tested + corrected 2026-06-02.** The repo baseline (`git init` + planning-doc commit) was
> established by the Cowork session on 2026-06-02, so the build session starts from a clean tree.

---

```
Execute Spike 0 — the WYSIWYG <-> Livewire 4 editor spike.

START OF SESSION: read PROJECT-STATE.md, CLAUDE.md, docs/PROJECT-BRIEF.md (standing rule),
then the spec: docs/product/spike-0-handoff.md and §4 of docs/product/phase-1-plan.md.
Both were pressure-tested and corrected on 2026-06-02 — use the current versions.

MODEL/EFFORT: Opus 4.8 at xhigh. Think hard on the server-side canonical->HTML renderer +
sanitizer — it's the security boundary (criterion #4).

BASELINE: the repo is already initialized and the planning docs are committed (Cowork,
2026-06-02). Run `git status` to confirm a clean tree before you start. The empty NovForaBB/
dir is a stray — reconcile it (use as the app dir or delete it); the spike scaffolds into a
separate scratch dir `nevo-spike/`, and M0 (later) builds the app at the repo root per the
handoff. Keep commits small + conventional (CLAUDE.md).

THEN run Spike 0 exactly per spike-0-handoff.md. Three things the pressure-test flagged that
are easy to miss:
  • Mechanism under test = wire:ignore + Alpine island, canonical TipTap JSON synced via
    $wire.set. This is NOT Livewire 4's "islands" partial-render feature. Verify both against
    current LW4 docs and adjust syntax.
  • Implement CanonicalRenderer::nodesToHtml() for the defined spike node set (paragraph,
    h1-h3, bold, italic, lists, blockquote, code block, link, mention) — it ships as an empty
    stub and criterion #4 cannot be evaluated without it.
  • Dynamic-import the editor so @tiptap/* is code-split OUT of the main bundle — criterion #6
    (<=180 KB gz, lazy-loaded) fails by construction otherwise.

Scaffold: Laravel 13 (GA, PHP 8.3) + Livewire 4 + TipTap MIT-core only (never @tiptap-pro/*,
ADR-0015) + symfony/html-sanitizer + Dusk, SQLite DB. License-check every npm dep
(MIT/BSD/Apache); record anything non-obvious in DECISIONS.md.

Validate all six GO criteria. Note criterion #1 is split: 1a (validation error + sibling
update) is the GO-blocker; 1b (wire:navigate cursor restoration) is best-effort/documented,
not a blocker.

DELIVERABLE: fill the GO/NO-GO memo template (§4) and commit it as docs/product/spike-0-memo.md
with the validated reference pattern. Report the filled memo back to the Cowork session — do
NOT start M0 on GO, and do NOT start M2 editor work on NO-GO until the fallback is folded in.

CONSTRAINTS: throwaway spike, keep it in nevo-spike/ until GO; no production code at the repo
root until the memo says GO. Time-box ~1 focused day — if wire:ignore + Alpine island isn't
clearly GO, record the failing criteria and drop to the §5 fallback chain rather than polishing.
```

---

## After the memo comes back

The Cowork session folds the confirmed pattern into the M0->M5 plan, updates **ADR-0012** + the architecture
docs (and [phase-1-plan.md](../phase-1-plan.md) §4 if a fallback is chosen), and keeps **PROJECT-STATE.md** current.
Leave those edits to Cowork so the two tools never edit the same files at once.
