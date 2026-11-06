<!-- SPDX-License-Identifier: Apache-2.0 -->

# Admin stable-release upgrade — implementation plan

Owner request (2026-11-06): click Upgrade in the admin panel to check and upgrade the existing Hostinger
`/community` installation in place. Owner selected **latest published stable release**. Astra orchestrates;
Sol agents implement focused slices. This resumes E7 / NOV-141; it does not require finishing unrelated E2–E6.

## Deliverable and acceptance evidence

1. ACP System → Upgrade checks GitHub `getnovfora/novfora`, displays installed/latest stable versions, and
   offers Upgrade. Drafts/prereleases, unsigned packages, and downgrades are refused. Preserve manual schema
   migration controls separately. Verify route and Livewire action authorization, including a revoked owner.
2. Release builder emits a deterministic file integrity manifest, and an optional detached Ed25519 signature.
   Only separately provisioned core-release public keys authorize installation. Test forged, absent and wrong
   version signatures, malicious archives, private module exclusion, and exact shipped bytes.
3. A persisted private run records the chosen release and advances via the existing cron. No persistent daemon,
   shell account, Composer or Node is needed by the installed updater. Download/preflight happen before downtime.
4. Capture data and code recovery points before replacement. Preserve `.env`, storage, uploads, custom/private
   modules/themes, host routing, and SQLite data. Refresh the actual served public assets for symlink, scaffold,
   and copy layouts. An interrupted copy must resume or roll back without booting mixed-version Laravel code.
5. Run new-code migrations in a fresh PHP process, clear route/config/view caches, validate the installed file
   manifest and health, then lift maintenance. Failed migrations retain maintenance and both recovery points.
   Provide authenticated status and explicit code rollback; never automatically roll the database backward.
6. Test a real old→new signed package upgrade, injected failure/restart points, preservation of content/settings,
   overlapping invocations, each supported Hostinger layout, and rollback. Run repository gates and adversarial
   review before merge. Production acceptance requires the actual Hostinger layout, deployment of the updater,
   published signed release assets, and a successful admin-initiated upgrade verified on the installed instance.

## Execution architecture

- `App\\Upgrade\\Releases`: cached stable discovery and bounded HTTPS download; detached signature and complete
  manifest verification before safe extraction. GitHub is transport, not the signing trust root.
- Private file-backed run state outside `storage/app`, atomic rename writes and OS file locks. This survives
  cache/database changes. One active run per installation; no time-based lock takeover.
- Keep the destructive copier/recovery runner independent of Composer and freeze its code with the run before
  touching the application. The artisan entrypoint must be able to resume recovery before loading vendor files.
  Public entrypoint maintenance must also work before Composer loads. This addresses process death mid-swap.
- New-code checks/migrations execute in a fresh process after the copy completes. Never invoke already-loaded
  old Laravel classes over a newly replaced vendor tree. Maintenance stays up until success is independently checked.
- The default is operator-triggered stable upgrades; this request does not enable unattended automatic updates.

## External prerequisites to verify

Hostinger access and production public layout; core release key provisioning; signed release publication.
These do not block local implementation/test work. No live content is changed during development.
