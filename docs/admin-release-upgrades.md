<!-- SPDX-License-Identifier: Apache-2.0 -->

# Admin release upgrades

This updater installs a published stable release from `getnovfora/novfora` into the existing installation.
The initial installation of the updater and its trusted release key is a separate deployment step. An older
installation cannot acquire these changes through its schema-only **Apply migrations** button.

## Administrator workflow

1. Open **Admin → System → Upgrade** as a co-owner with confirmed two-factor authentication.
2. **Check for updates** shows the latest eligible stable release. **Upgrade** also performs a fresh check.
3. The existing scheduler downloads and authenticates the package, prepares recovery snapshots, replaces
   the managed application files, applies migrations, and verifies the updated site's health.
4. Keep the status page open. It refreshes while the site is in maintenance and returns to the normal panel
   when the upgrade finishes. No Node, Composer, persistent worker, or SSH session is required on the host.

Drafts, prereleases, downgrades, unsigned packages, unknown signing keys, and invalid archives are refused.
The updater preserves `.env`, application storage, uploads, private/custom modules and themes, and the
host's public scaffold and `.htaccess`. It supports a canonical public directory, a symlink, a generated
scaffold with linked assets, and a scaffold with copied assets. The separate marketing site is not part
of the update.

The host needs working minute cron, PHP ZIP/Sodium/cURL support, `proc_open`, writable application files,
and enough free space for the staged release and recovery snapshots. An `APP_VERSION` override that would
hide the selected release must be removed before upgrading. Unsupported new module/theme layouts are
refused before maintenance; the updater's ownership rules must explicitly support them first.
Web OPcache must validate timestamps. The updater allows its revalidation interval to pass before lifting
maintenance, including after a code rollback. Long-running application processes hold shared file locks;
the updater waits for them to exit before taking recovery snapshots or replacing code.

## Recovery

A failed download or preflight leaves the installed code in place. An interrupted file replacement resumes
on the next cron tick. Failures after migrations begin hold the site in maintenance so it cannot silently
serve an incomplete upgrade.

The requesting owner's status-page capability expires after two hours. While valid, **Request code
rollback** queues restoration of this run's code and served assets. Database migrations are retained;
code rollback does not restore the database. A data restore is a separate, deliberate recovery operation.
Backups and private run state remain on the host until an operator removes them.
The run's data-backup copy is kept in its private directory, outside the ordinary backup retention policy.

If the browser capability has expired while the run is held, use the hosting File Manager:

1. Open `storage/framework/release-upgrade/state.json` inside the application directory and read `run_id`.
2. In `storage/framework/release-upgrade/runs/<run_id>/`, create an **empty, ordinary file** named
   `request-rollback`. This requests code rollback for that held run only.
3. Leave the scheduler running. Its next tick records the recovery request and restores verified snapshots.

The marker does not clear maintenance directly. Do not delete the state or lock files to force the site
online: a partial code replacement needs the frozen worker to finish or restore it first.

## Release maintainer setup

Each package contains `release-manifest.json` with the version, supported PHP floor, and SHA-256 digest
of every shipped file. The detached Ed25519 signature authenticates the exact ZIP bytes, including that
manifest. The installer checks all digests before accepting the package.

Provision the core public key in `config/releases.php` through a trusted deployment. The matching secret
key belongs in a private release-signing environment outside Git and outside the hosted application. The
updater does not download trust keys from the same server that supplies releases. The release pins
`core-2026-11`; older installations receive that public key through the trusted initial bootstrap.
A private build with an empty key list refuses automatic installation.

Build and sign with the existing release builder:

```bash
NOVFORA_RELEASE_SIGNING_KEY_FILE=/private/path/core-release.key \
NOVFORA_RELEASE_KEY_ID=core-2026-11 \
  sh scripts/build-release.sh
```

The key file holds a raw 64-byte Ed25519 secret key or its base64 encoding. Both variables must be set
together. The result must be published as assets named **novfora-release.zip** and
**novfora-release.zip.sig** on the same stable GitHub release. The tag and manifest version must match.
Never publish a test fixture key, and never include the private Populate module in an artifact.

Before publishing, run repository gates, `scripts/verify-release.sh`, and the isolated packaged upgrade
test against the locally built, trusted ZIP. Exercise each supported public layout:

```bash
for layout in canonical option_a option_b option_c; do
  SMOKE_LAYOUT="$layout" sh scripts/test-release-upgrade.sh /absolute/path/novfora-release.zip
done
```

The smoke test uses a temporary SQLite installation, ephemeral signing key, synthetic newer release,
loopback HTTP server, and fake release downloads. It does not deploy to Hostinger. Live acceptance still
requires a published signed release and an upgrade initiated from the deployed admin panel.
