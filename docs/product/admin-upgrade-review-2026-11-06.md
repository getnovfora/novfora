<!-- SPDX-License-Identifier: Apache-2.0 -->

# Self-upgrade adversarial review — 2026-11-06

**Status:** local verify-then-refute review complete; no confirmed HIGH or MEDIUM findings remain open in this slice. Root reviewed the load-bearing lock, snapshot, and recovery changes after independent review. PHP 8.3.6 full Pest passed 2,585 tests with one skipped (17,331 assertions); Pint, Larastan, platform checks, dependency audit, and asset build passed. The later transport delta passed 23 tests (146 assertions), and version fixtures passed 18 tests (101 assertions). Isolated SQLite packaged upgrades, MariaDB backup/restore, and the actual browser Upgrade/cron/health flow passed. A separate `/community` entry check passed its cookie and status redirect. Remote CI and live Hostinger acceptance remain outstanding.

## Confirmed findings and fixes

| Finding | Impact and evidence | Fix and verification |
|---|---|---|
| `APP_VERSION` pinned to the old version | A real packaged 1.3.0 → signed synthetic 1.4.0 smoke run applied files and its migration, then stayed `held` at health because `/health` still reported 1.3.0. | `ReleaseCoordinator::request` now rejects a conflicting process, loaded, cached, or host environment-file override before it creates state, with an actionable message. `ReleaseVersionOverrideTest`: 2 tests, 5 assertions. The real override smoke now stops at preflight. |
| In-flight request can outlive the timed drain | A fixed `snapshot_after` delay alone cannot prove that an admitted HTTP/CLI request has exited, especially with `max_execution_time=0`. It could overlap the data/code snapshot or code swap. | Preboot admits normal processes under a shared `activity.lock`. The direct cron preparer promotes its own lease to exclusive under `run.lock` before the database backup and code/public snapshots, deferring when old work is still active; it downgrades to shared through process exit. The frozen worker also needs an exclusive nonblocking lease before apply/migrate/rollback. Restore/schema operations share the release lock. Snapshot/worker/status focused tests passed (27 tests, 158 assertions), as did a final canonical signed packaged smoke after this change. The four public-layout smokes had passed before the snapshot lease refinement. |
| Recovery capability expires while a run is held | The two-hour cookie expires while the preboot gate keeps Laravel routes unavailable. The existing status test confirms expiry denies access; a Baseline owner then has no panel rollback action. | An owner with host File Manager access can place an empty, regular, non-symlink `storage/framework/release-upgrade/runs/<run_id>/request-rollback` marker. Only the current held run dispatches the frozen worker. Under `run.lock`, it persists `rollback_requested` before consuming the marker and uses the existing verified code snapshot rollback. `ReleaseFileManagerRecoveryTest` and existing worker/status tests passed (19 tests, 84 assertions combined). The browser capability remains finite. |
| Copied served-public layout retained obsolete release assets | The external mirror plan included new files but omitted authenticated release-owned deletions; an old served asset could remain after upgrade. | The coordinator passes filtered obsolete public paths into the mirror snapshot, excluding canonical symlinked trees and preserving the scaffold and host files. Focused copied-layout deletion/rollback test passed. |
| New signed first-party module/theme path was silently skipped | The builder can ship future first-party packages while `ReleaseFilesystem::isManaged` permits only the current set; an upgrade could report success without installing them. | The coordinator fails closed immediately after signed staging when a package path is unsupported, before a backup or code swap. Focused signed-artifact test passed. |
| Legacy no-manifest inventory missed retired bundled packages | The first managed upgrade did not include the known first-party module/theme trees in its obsolete-file inventory. | The inventory scans exactly current bundled module/theme roots, excluding Populate and custom packages. Focused legacy-deletion test passed. The three asset/module/legacy tests totaled 15 assertions. |
| Independent restore/schema work could overlap a release | A CLI restore or already-dispatched schema operation could start after the release request's state check. | Restore and independent schema runners now hold the same release OS lock across execution and refuse an active release. Only the frozen migration child, whose parent holds that lock, bypasses reacquisition. Tests exercise refusal without data changes and assert the lock remains held during restore validation. |
| Web OPcache can retain replaced code | CLI cache invalidation does not prove that the web PHP process has dropped old bytecode. Disabling timestamp validation can retain it indefinitely. | Request preflight refuses enabled web OPcache without timestamp validation. Both installation and code recovery retain maintenance through the recorded revalidation interval; tests exercise deferred health/recovery and restart without perpetually resetting the deadline. |
| Normal backup retention could delete a live recovery point | The migration runner takes another backup; a retention count of one can prune the coordinator's earlier archive before a resumed worker validates it. | The coordinator copies the verified data backup into its private run directory before replacement. The regression removes normal retained archives and verifies the private recovery copy is intact. |
| URL streams disabled while cURL remains available | A real PHP 8.3 signed upgrade applied files and migrations but remained held because the frozen health probe used a disabled URL stream. | The standalone worker prefers cURL with verified TLS, no redirects, a total timeout and a 1 MiB response bound; the bounded stream fallback requires URL streams. Preflight refuses hosts with neither transport. Real loopback, canonical packaged upgrade and browser-triggered old-to-new checks passed with URL streams disabled. |

## Refuted candidate and remaining limits

- **Parent killed while a migration child runs:** a Linux PHP `flock`/`proc_open` experiment showed the child retains the locked file description after the parent is killed, so the next cron worker cannot start a concurrent migration. The new activity lease also prevents normal requests during the swap. If an orphan child hangs indefinitely, host process intervention may still be required; the parent's ten-minute timer cannot terminate it after the parent has died.
- **Code rollback is not a database restore.** The frozen worker deliberately restores code and validates the saved snapshot; it does not reverse an already applied migration. An incompatible schema needs the separate data-backup/restore procedure.
- **No production claim:** the real smoke test used an ephemeral Ed25519 key, faked pinned GitHub release endpoints, a signed test ZIP, and a loopback server. It passed canonical, full-public-symlink, generated scaffold, and copied-public layouts, including `/community/health`, migrations, forum/settings data, uploads, custom module, `.env`, served assets, and scaffold-file preservation. Hostinger permissions, cron behavior, and live HTTP remain unverified.

## Release dependency follow-up

The final asset gate detected existing npm advisories in the editor and build tools. Tiptap was updated
within major 3 to 3.30.5 and ProseMirror view within major 1 to 1.42.9. The relevant upstream reports are
[Markdown parsing](https://github.com/advisories/GHSA-j95f-988m-3j2f),
[attribute merging](https://github.com/advisories/GHSA-cp6q-959q-f8rh), and
[clipboard attributes](https://github.com/advisories/GHSA-c8x8-7fp4-3x9w).

Verify-then-refute: root inspected the installed attribute-merging and parsing fixes. A JSON-owned
`__proto__` key no longer changes the merged object's prototype or exposes inherited executable attributes;
the advisory's bounded block/inline parser inputs completed in under 2 ms in the local regression. The
apparent absence of `checkAttrs` in the latest clipboard implementation was refuted by inspection and an
invalid-attribute test: ProseMirror model 1.25.12 validates during node creation, and clipboard code catches
validation failures. This verifies the dependency fix without claiming that every advisory was exploitable
through NovFora's own editor configuration.

Compatible updates to Vite 8.1.6, concurrently 9.2.5 and their dependencies clear the build-tool advisories.
The full npm audit reports zero vulnerabilities. Asset budgets and the PHP 8.3 manifest tests pass; the
finished browser bundle passed all four editor journey tests (13 assertions). No framework major changed.

## Reproducible local smoke

Build a release ZIP, then run `scripts/test-release-upgrade.sh <zip>` with `SMOKE_LAYOUT=canonical`, `option_a`, `option_b`, or `option_c`. The harness creates its app, SQLite database, temporary signing key, and server under `/tmp`; it rejects unsafe ZIP paths/links before extraction, prevents unmatched Laravel HTTP requests, and contacts only loopback during the test. Use a locally built ZIP in an isolated CI job. On failure it prints `SMOKE_WORK` and leaves the sandbox for diagnosis; the ephemeral signing secret is removed.
