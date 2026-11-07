<!-- SPDX-License-Identifier: Apache-2.0 -->

# v1.4.0 signed release and updater activation

The owner selected the latest published stable GitHub release as the update source and authorized access
through the signed-in Hostinger hPanel. This document records the published signed release, the initial
live updater bootstrap, and the owner-started upgrade. The live run completed and independent health/data
checks passed. Temporary monitor cleanup is complete, and the owner confirmed the status page reports
successful completion to 1.4.0. No acceptance follow-up remains for this latest-stable slice.

## Published signed release

The release branch is `codex/admin-stable-release`, based on the reviewed updater commit `36799e3`,
dependency fix `ac8f65f`, and transport fix `64e6aea`. The source default version is 1.4.0. The published
release includes the earlier v1.4 theme, registry, importer, moderation, administration, and reliability
changes, plus the admin release updater.
The changelog dates the published 1.4.0 release.

The preceding NF-06/07/08 hardening is already merged in PR #54 (`03e3e0f`); NOV-149/150/151 are Done.
ADR-0124 records its review and separately identifies pre-existing issues including NOV-167 (Medium).
NOV-167 remains in Backlog, with no explicit v1.4 disposition found. It is not fixed by this updater slice,
whose zero-open-HIGH/MEDIUM review applies to its own scope; release readiness must not be represented as
proof that every repository security or reliability issue has been resolved.

The public trust key ID is `core-2026-11`; its raw Ed25519 public-key SHA-256 is
`fcd9b1ae535035ee74f50569b02b6f5a73c18877b12f261117435fcc0e4d0c12`.
The matching persistent private key was generated in an operator-owned local directory outside Git and
outside the host, with directory mode 0700 and key mode 0600. A challenge-signature check proved that it
matches the public key pinned in `config/releases.php`. The private key must never be uploaded to Hostinger
or included in release assets.

The stable `v1.4.0` release publishes exactly `novfora-release.zip` and `novfora-release.zip.sig`.
The tag, source version, signed manifest, and asset bytes agree. Existing v1.3.0 assets
are unsigned and must not be replaced or relabeled to bootstrap this feature.

The adjacent artifact receipt records the final ZIP and detached-signature hashes, committed source SHA,
manifest count, and verification results. The builder excludes this document and does not embed a source
commit ID. Do not rebuild or re-zip after signing. Release artifacts and the receipt are kept outside Git
in the Windows-accessible sibling folder `NovFora-Releases/v1.4.0/`. An earlier unsigned candidate was
superseded when the release gate identified editor dependency advisories; it must not be published.

Final frozen source: `cc70a05d188f2ee19669821308ea64d3b90f2942`. Final ZIP SHA-256:
`da51fa3b920aa0dc28383a38c593fb46d96fe8b0b6cab70922def0550616c63b`. Detached signature SHA-256:
`e935ab8e6637c369477ff8dcfb9bdb6ac03139db88d30d617bd5f3e7d34ac392`.
The actual PHP 8.3 `ReleaseVerifier` accepted this persistent-key signature and all 10,464 manifest entries.
All 1,079 packaged source-controlled files match the frozen Git commit. Packaging, cold boot/i18n, and a
final canonical signed upgrade smoke passed after the dependency changes. The clean Node 20/Vite 8.1.6
asset rebuild matches all 49 committed files byte for byte. With the owner's explicit approval, Codex pushed
finalized source `9f9b0a3bd7d270d45116ff53f33158694f2fa824` to remote `main` and tagged it `v1.4.0`.
Changes after `cc70a05` are documentation only, so the signed runtime ZIP still matches the tag's
packaged source.
GitHub release `437036807` publishes the exact ZIP and signature; the public latest-stable endpoint returns
`v1.4.0` with both asset names.

## One-time bootstrap

The documented live source is `5079215a41cd78445dfa5a362687f56a2575f15b`, still declaring 1.3.0. Live checks
on November 6 matched all ten required baseline hashes and eleven new-path absences. The conditional bootstrap overlays only 19 updater-related
files, leaving the old business code, `config/app.php`, Composer lock and vendor tree in place. Thus the
subsequent Upgrade click performs a real old-to-new application upgrade.

The conditional packet is `novfora-updater-bootstrap-507-to-64e6aea.zip`, SHA-256
`def866edccd96a713dd3b9773382897bb45d22fcee1b8f2d86f408996fabe4be`.
Its 8 replacements and 11 new files derive from `64e6aea`, with the public-key pin from commit `288f46b`.
All 19 payloads match their cited committed sources. The packet contains no private key or application data.

Its read-only verifier checks old hashes for every replaced file, absence of each newly added file,
unchanged version and lock files, payload integrity, and the actual served entrypoint. A mismatch requires
reviewed adaptation; it does not authorize overwriting the differing file. Generated or customized public
scaffolds must be inspected and preserved, with their delegation to the canonical entrypoint verified.

Before applying the bootstrap, verify the live root, public layout, web and CLI PHP settings, available
space, ownership, cron, release transport and signing support, and co-owner/two-factor access. Take data
and code backups, stage the small overlay, verify its preconditions again, enter maintenance, and apply
only its declared files. Clear caches using the verified host procedure. Check the original site and
Upgrade panel before requesting the published signed release. The apex marketing site is outside this
application update.

## Verification evidence and remaining acceptance

- Remote CI run `42291976769` completed successfully on release source `9f9b0a3` with all four jobs green.
  Pest on PHP 8.3/MySQL 8: **2,586 passed, 1 skipped, 17,340 assertions**. Dusk: installer 1 passed
  (9 assertions); app journeys 37 passed, 2 skipped (94 assertions). MySQL applied and rolled back 110
  migrations; three 640-reply concurrency runs passed, as did both backup/restore paths. Pint passed
  1,133 files, Larastan had no errors, Composer audit found no advisories, and assets-fresh/budgets passed.
  Compact read-derived evidence: `NovFora-Releases/v1.4.0/evidence/remote-ci-42291976769.json`.

- Exact updater source `36799e3` passed PHP 8.3.6 Composer platform checks, full Pest (2,585 passed, one
  skipped; 17,331 assertions), Pint, Larastan, and dependency audit in an isolated Linux mirror.
- The candidate version/test-fixture changes passed 18 affected tests (101 assertions) on PHP 8.3.
- A disposable MariaDB 11.8.6 applied 110 migrations. Pure-PHP backup and restore into a separate database
  preserved 98 tables and 110 migration records; the restored schema had no pending migrations.
- Headless Chrome journey checks passed mobile navigation, administration and editor flows (10 tests).
  The installer wizard also passed on PHP 8.3 and a disposable MariaDB database (one test, nine assertions).
- The complete migration apply/reset/re-apply cycle passed on a separate disposable MariaDB database:
  110 migration records, 98 tables, zero pending. Accessibility suites passed 34 tests (83 assertions).
- Editor and build-tool dependencies were patched within existing majors after the final gate found npm
  advisories. Full npm audit is now clean, asset budgets and manifest checks pass, and the final editor
  bundle passed all four browser journey tests (13 assertions). The dependency review and bounded
  security regressions are recorded in `admin-upgrade-review-2026-11-06.md`.
- The conditional bootstrap passed 80 tests on the exact old dependency lock, with Pint and scoped
  Larastan clean. Its verifier accepted the matching baseline and refused a one-byte source drift.
- Release packaging and cold boot/i18n verification passed. The canonical signed upgrade smoke passed
  both normally and with PHP URL streams disabled; the frozen worker uses bounded cURL in that case.
- A real headless browser clicked Upgrade without first checking, starting from the old 5079215 source
  plus the minimal bootstrap. Real cron ticks completed a signed 1.4.0 installation with URL streams
  disabled. Final health returned 200/version 1.4.0 with no pending or stuck schema; the owner and upload
  sentinel survived, and the upgraded admin panel displayed completion.
- A separate browser check under `/community` passed the actual button, confirmation, scoped recovery
  cookie and standalone status redirect. The wrong root-path status URL returned 404. This separate check
  stopped before the file swap; the full browser upgrade above ran at a local domain root.

The signed release is published. The owner started an actual admin-triggered upgrade on
`novfora.com/community`, and the run completed at 21:49:09 UTC. The owner
approved the initial bootstrap, which completed at 20:20 UTC after the verified maintenance gate, cron
pause, owner-confirmed hPanel process stop, and fresh backups. All nineteen installed hashes passed;
the served wrapper, routing and links were preserved. Health and assets returned 200 on the existing
1.3.0 installation afterward. This bootstrap success is not the later signed 1.4.0 upgrade acceptance.
After the owner's restart, Hostinger MCP authenticated successfully. Account reads and
temporary read-only probes verified the live baseline hashes, new-path absences, wrapper and asset links,
PHP 8.5.12 CLI/web capabilities, web OPcache timestamp validation (two-second interval), absence of a version
override, and co-owner 2FA readiness. Both probes and the temporary cron were removed; existing application
files and scheduler jobs were preserved. The browser-control runtime still rejects the Linux workspace URI.

The live layout requires a reviewed variation of the packet's generic index instructions: run its canonical
code verifier against the app's own `public/index.php`, and separately pin the served wrapper and routing
hashes. Preserve the wrapper; replacing it with the canonical index would change `__DIR__` resolution.
Before activating, resolve exclusion of requests that began before the new activity lease existed. Enter
maintenance before establishing that exclusion and retain it through the protected work. A PHP execution
time setting or arbitrary sleep does not by itself prove old requests have finished. The local activation
runner passed focused review and was enabled only after the owner's explicit maintenance confirmation.

At 19:59 UTC a reviewed CLI helper created a private preliminary MySQL/storage backup and snapshots of the
eight replacement files. The helper loads only configuration and database services, avoiding application
and module provider boot. The 977,126-byte archive has SHA-256
`1fec8bfe30a14787974edeb33183f699fb768f02d80ddef355738f5ff249847b`; its dump and all fifteen storage files
passed validation. This live-site backup is explicitly preliminary: it does not establish cross-table or
database/storage consistency. Fresh backups after the maintenance gate and process stop were taken by the
approved bootstrap runner.
The helper and its temporary cron were removed, the original two cron jobs were preserved, and health at
20:00 UTC remained 200/1.3.0 with zero pending queue jobs or migrations. Detailed private evidence is beside
the release receipt. No application code was replaced during that preliminary backup step. The later
approved bootstrap completed as recorded above; its temporary files and jobs were cleaned up. The original
production scheduler command/timing was restored under a new job UID and its first observed run completed
all tasks successfully. The owner confirmed that the new Upgrade section rendered in the authenticated ACP
and clicked Upgrade. Live run `27ad87cdde4bf8e8cfc6ea47644b8281` was requested at 21:40:48 UTC,
passed its snapshot/drain window, and completed at 21:49:09 UTC. The 21:50:04 UTC observer found all 15
pre-existing storage files unchanged with none missing or added; `.env`, served wrapper, routing, and
build/storage links were unchanged. At 21:50:46 UTC `/community/health` returned HTTP 200, status `ok`,
version 1.4.0. Baseline database/cache/queue checks were `ok`, queue pending was zero with a 36-second
heartbeat, schema pending/upgrading/stuck were false, and restore was false. One migration succeeded at
21:49:05 UTC. Homepage and current CSS/JS returned 200; all 12 recorded forum/topic URLs returned 200
with unchanged H1 hashes. The full 21:50 scheduler tick completed its jobs. At 21:55:06 UTC the retained
976,494-byte data backup passed ZIP consistency and SQL dump hash checks (SHA-256
`44b5e467c4684f4b135dda20c2e29090f1ede0c8f276ee31437c398b822451ce`). All 10,441 old-code snapshot
files and the one existing public asset snapshot matched their pre-upgrade hashes. The temporary observer
and cleanup helpers self-removed, both temporary cron jobs were deleted, and readback confirmed the original
twelve `.ops` items plus only the production/development schedulers. Private backups and evidence remain.
One custom extension was counted after the swap; no prior fingerprint exists, so its byte-for-byte
preservation is unproven. The checked mutation plan contains only managed core/bundled paths.
The owner confirmed that the rendered status page reports successful completion to 1.4.0, closing the
final UI observation alongside the verified server completion and live acceptance. NOV-141 remains In Progress
because its original E7 umbrella also names manual ZIP, version selection, API, and automatic-update work
beyond ADR-0125's latest-stable slice. Second Brain requires reauthentication; this repository and the
artifact evidence are the current handoff source.
