<!-- SPDX-License-Identifier: Apache-2.0 -->

# v1.4.0 signed release and updater activation

The owner selected the latest published stable GitHub release as the update source and authorized access
through the signed-in Hostinger hPanel. This document records release preparation; it is not evidence of
publication or a live deployment.

## Release candidate

The release branch is `codex/admin-stable-release`, based on the reviewed updater commit `36799e3`,
dependency fix `ac8f65f`, and transport fix `64e6aea`. The source default version is 1.4.0. The candidate includes the earlier v1.4 theme,
registry, importer, moderation, administration, and reliability changes, plus the admin release updater.
The changelog distinguishes this candidate from a published release.

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

Publish exactly `novfora-release.zip` and `novfora-release.zip.sig` on the stable `v1.4.0` release after final
verification. The tag, source version, signed manifest, and asset bytes must agree. Existing v1.3.0 assets
are unsigned and must not be replaced or relabeled to bootstrap this feature.

The adjacent artifact receipt records the final ZIP and detached-signature hashes, committed source SHA,
manifest count, and verification results. The builder excludes this document and does not embed a source
commit ID. Do not rebuild or re-zip after signing. Release artifacts and the receipt are kept outside Git
in the Windows-accessible sibling folder `NovFora-Releases/v1.4.0/`. An earlier unsigned candidate was
superseded when the release gate identified editor dependency advisories; it must not be published.

## One-time bootstrap

The documented live source is `5079215a41cd78445dfa5a362687f56a2575f15b`, still declaring 1.3.0. This is
historical evidence, not a live fingerprint. The conditional bootstrap overlays only 19 updater-related
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

Publication, initial live bootstrap, and an actual admin-triggered upgrade on `novfora.com/community`
remain required. Hostinger's MCP connection is configured for OAuth, but its tools are absent from the
current chat. Browser control fails before opening a window because the chat supplies a Linux workspace URI.
The latest retry explicitly rejected the managed `/home/tommy/...` worktree path. The owner will reopen the
native Windows project and restart Codex. Second Brain also requires reauthentication; this repository is
the current handoff source.
