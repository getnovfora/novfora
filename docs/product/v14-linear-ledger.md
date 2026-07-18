# Linear ledger — v1.4 run (2026-07-17)

**Environment finding:** NO Linear MCP server is available in this session (MCP catalog
returns zero matches for linear/issue-tracker/project-management). ALL Linear reads and
writes for this run are BLOCKED. Everything below is the intended state for the owner to
apply by hand, per kickoff §7.4.

## Blocked reads (verification the run could not perform)
- Confirm v1.3 issues are `Done` (Phase 0 step 5).
- Confirm NOV-140 / NOV-143 (Populate E6a/E6b) are `Done` — kickoff §1 says Linear shows
  0 In Progress, so they are presumed Done; completion comments may never have been posted
  (prior session reported blocked writes). Owner: verify + backfill comments if missing.

## Intended state flips (owner applies)
- Phase 0 (2026-07-17): verify v1.3 issues NOV-90/91/92/93/94/95/96/100/101/102/104/121/122/123
  are `Done` (v1.3.0 merged, tagged, pushed — merge = Done per convention).
- NOV-140 / NOV-143 (Populate E6a/E6b): seams merged to main (6724a9a) + plugin shipped → `Done`;
  backfill completion comments if the prior session's were blocked.

## Intended completion comments (owner posts)
- **NOV-107 (U9)** → In Progress at 4A start, Done on v1.4 merge. Comment: branch
  `claude/v14-u9-style-props`, ThemeApi 7→21 typed/grouped tokens + per-token dark layer
  (tokens_dark), grouped ACP editor w/ dual-mode AA preview; gate Theme 124/124 + full suite
  2311/0; no ADR (extends ADR-0029/0037 posture, ThemeApi MINOR → 1.3.0).
- **NOV-108 (U10)** → In Progress at start, Done on merge. Comment: branch
  `claude/v14-u10-style-tree` (stacked on U9), style tree (parent_id, depth 5, cycle-guarded),
  per-user chooser (users.style_theme_id, no-JS form), generation-keyed cache (zero warm-path
  queries), NovFora/Daylight/Midnight presets; gate Theme 136/136 incl. Gate-4A ACP-built child
  theme proof + full suite 2323/0.
- **NOV-109 (U11)** → In Progress at start, Done on merge. Comment: branch
  `claude/v14-u11-template-hooks`, ADR-0112; template-hook fragments on 8 named anchors +
  Diff3 three-way merge for overrides (in-house bounded Myers; conflicts never fatal, never
  marker-emitting; merged output re-linted); TemplateSync on the upgrade path. Apex
  verify-then-refute review (6 lenses): 1 HIGH + 4 MEDIUM confirmed (0 refuted), ALL
  FIXED + regression-tested before merge — HIGH: lint skeleton unsound (regex→AST text-node
  scan); MED: slash-separated handlers/data:text-html; Diff3 duplicate-collapse corruption;
  Diff3 O(D²) OOM; ACP sync write-amplification. 0 open HIGH/MEDIUM. ADR-0112 records the ledger.

- **NOV-124 (Registry spike)** → Done (spike complete). Comment: `docs/product/spike-registry-memo.md`,
  GO on all four self-gate criteria (revocation reaches installed packages via registry-managed
  trust-key disable; static signed feed; untouched ArchiveGuard/PackageSignature/StylePackage path;
  downgrade + mirror-substitution structurally refused). Branch `claude/v14-u12-style-io` (memo commit).
- **NOV-110 (U12)** → In Progress at start, Done on merge. Comment: branch `claude/v14-u12-style-io`,
  StylePackage export/import (manifest = registry theme format, rides ArchiveGuard) + global custom-CSS
  box; gate StylePackage 8/8 + full suite 2313/0.
- **NOV-125 (Registry v1)** → In Progress at start, Done on merge. Comment: branch
  `claude/v14-registry-v1` (stacked on U12), ADR-0113; signed static feed client (ed25519 root-key
  verify, durable monotonic sequence, sha256 content-address, publisher-active, downgrade refusal),
  one-click install via untouched paths, revocation → trust-key disable + ACP alert, ops signing
  command, ACP Browse. Apex verify-then-refute review: **1 HIGH + 1 MEDIUM confirmed, BOTH FIXED +
  regression-tested** — HIGH: revocation fingerprint decoupling (now derived from key); MEDIUM:
  rollback floor moved cache→durable table. 0 open HIGH/MEDIUM. Full suite 2326/0.
- **NOV-126 (Importer completion)** → PARKED this session (capacity). Recon-documented plan below.

## Discovered issues to file
- **NOV-126 importer completion** (4C, PARKED): recon (this session) mapped the deltas — all four
  drivers (phpBB/XenForo/MyBB/SMF) already share the full SourceDriver+ProvidesAttachments contract;
  the real work is (1) source-side exclusion filtering for MyBB/SMF mirrored into counts(), (2) SMF
  attachment author_source_id resolution (SmfDriver.php:127 hardcoded 0), (3) per-driver path-traversal
  regression tests (phpBB has one, MyBB/SMF don't — structurally protected by ImportRunner::safeLegacyPath),
  (4) flip the "SCAFFOLD" docstrings + refresh docs/architecture/phase3-extensibility/importers.md (stale
  re: XenForo) + novfora-docs migrating guide, (5) the ACP import surface the migrating doc references
  doesn't exist. "Verified against a live board" stays an honest owner/CI item (no real dumps in-env).
- **NOV-124 Gemini key** (carried): the old F:\ForumGen committed Gemini API key revocation is still
  UNCONFIRMED (flagged since 2026-07-02). Owner: confirm revoked.
- **v1.4 merge-time reconciliations** to file/track: (a) U12 StylePackage export should wire
  effective()+tokens_dark once U10 merges (reserved manifest slot); (b) DECISIONS.md append-tail
  conflicts expected (0112 U11, 0113 Registry) — keep in order; (c) themes SFC 3-way conflict
  (U9 grouped tokens / U10 tree / U12 export-import) — hand-merge, all additive.
