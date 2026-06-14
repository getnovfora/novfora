<!-- SPDX-License-Identifier: Apache-2.0 -->
# `permissions:sync` — re-provisioning role presets on upgrade (Wave 0.1 / ADR-0036)

## The problem it solves

On the **baseline (no-SSH) tier**, an upgrade applies database **migrations** but never re-runs **seeders**
(`UpgradeRunner` is migration-only by design — seeders aren't reversible and mustn't clobber operator data).

That leaves a gap: when a new release **adds a permission key to a built-in preset** — e.g. `badge.manage`
joined the `administrator` preset — an *already-installed* site keeps its pre-release `role_permissions` and
`acl_entries`. The admin role never gains the new key, so the admin gets a **403 on the new screen** (the
Badges ACP checks `badge.manage`). This is the live "Badges 403" class.

## What the command does

```
php artisan novfora:permissions:sync            # apply
php artisan novfora:permissions:sync --dry-run  # preview only, writes nothing
```

It re-derives the built-in presets (from `RoleSeeder::presets()` / `groupAssignments()` and
`PermissionCatalogSeeder::catalog()` — the same definitions the installer uses) onto **existing** roles and
system groups, and writes **only what is missing**:

| Layer | Action | Never |
|---|---|---|
| `permissions` (reference catalog) | upsert known keys (refresh labels) | — |
| `role_permissions` | add a preset key absent from the role | modify / delete an existing key |
| `acl_entries` (system group, global scope) | write a missing entry | overwrite an existing value |

## Additive-only — why (ADR-0036)

It deliberately does **not** use `RoleExpander::reexpand()` (a blunt upsert), because that would overwrite an
admin-customised global value on a system group — **re-granting a permission an admin deliberately revoked is a
security regression.** Additive provisioning instead:

- **fixes the 403** (adds the genuinely-new key),
- **heals partial state** (a `role_permission` present but its expanded `acl_entry` lost),
- is a **true no-op** on a healthy install — no writes, so no `AclVersion` bump and no cache churn,
- **preserves every admin customisation** — a `NEVER`/`NO` set on a system group, per-forum overrides, custom roles.

> **Operator note — permanently denying a baseline permission:** set the entry's value to **NEVER**, do **not**
> delete the entry. `permissions:sync` re-provisions a *missing* baseline entry, but it never overwrites a value
> — so a `NEVER` survives every future sync, while a deleted entry would be re-added.

## How it runs

- **Automatically on every upgrade.** `UpgradeRunner` calls it after a successful `migrate`, under the upgrade
  lock. It is **best-effort**: a sync failure is logged + audited (`upgrade.permissions_sync_failed`) but does
  **not** fail the schema upgrade (the migrations already applied). The success audit records
  `permissions_synced` (count of changes).
- **On demand from the CLI** — the fastest way to clear a 403 caused by a missed sync, with no redeploy.

Idempotent; safe to run any number of times. `--dry-run` and the real run share one code path, so the preview
can never disagree with what an apply would do.
