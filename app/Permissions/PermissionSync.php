<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Permissions;

use App\Models\AclEntry;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\PermissionCatalogSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Re-provisions the built-in role PRESETS onto existing roles and system groups so that permissions
 * ADDED to a preset in a newer release reach installs that were seeded before that release (Wave 0.1).
 *
 * THE BUG THIS FIXES. On the no-SSH upgrade, migrations run but seeders do NOT. When a release adds a key
 * to a preset (e.g. `badge.manage` on the administrator preset), an already-installed site keeps its OLD
 * role_permissions / acl_entries, so the admin 403s on the new screen. `permissions:sync` closes that gap
 * at upgrade time (and on demand from the CLI).
 *
 * SEMANTICS — ADDITIVE ONLY (deliberate, ADR-0036). We only ever INSERT what is missing:
 *   • catalog: upsert the reference `permissions` table (code-owned docs — refreshing labels is safe);
 *   • role_permissions: add preset keys that are ABSENT from the role — never modify or delete a key;
 *   • acl_entries: write a system group's GLOBAL-scope entry only when it is MISSING — never overwrite a
 *     value. This preserves every admin customisation (a NEVER/NO set on a system group, a per-forum
 *     override, a custom role) and makes a re-run a TRUE no-op (no writes → no AclVersion bump).
 *
 * We deliberately do NOT route through {@see RoleExpander::reexpand()} — its blunt updateOrCreate would
 * overwrite an admin-customised global value (re-granting a deliberately-revoked permission is a security
 * regression). The one consequence is that a baseline entry an admin DELETED is re-provisioned; to deny
 * permanently, set the entry's value to NEVER (which add-only preserves) rather than deleting it.
 *
 * Idempotent and safe to run on every upgrade. NOT final: swappable for a test double where the upgrade
 * runner's best-effort resilience is unit-tested.
 */
class PermissionSync
{
    /** Apply the sync atomically; returns what changed. */
    public function sync(): PermissionSyncReport
    {
        return DB::transaction(fn (): PermissionSyncReport => $this->run(apply: true));
    }

    /** Compute what a sync WOULD change, writing nothing (the --dry-run plan). */
    public function preview(): PermissionSyncReport
    {
        return $this->run(apply: false);
    }

    private function run(bool $apply): PermissionSyncReport
    {
        $report = new PermissionSyncReport;

        $this->syncCatalog($apply, $report);
        $effective = $this->syncPresetPermissions($apply, $report);
        $this->expandOntoGroups($apply, $report, $effective);

        return $report;
    }

    /** (1) Reference catalog — record unknown keys as added; refresh metadata on a real run. */
    private function syncCatalog(bool $apply, PermissionSyncReport $report): void
    {
        $known = Permission::query()->pluck('key')->flip();

        foreach (PermissionCatalogSeeder::catalog() as $key => [$label, $scopeKind, $group, $description]) {
            if (! $known->has($key)) {
                $report->catalogAdded[] = $key;
            }

            if ($apply) {
                Permission::updateOrCreate(
                    ['key' => $key],
                    ['label' => $label, 'scope_kind' => $scopeKind, 'group' => $group, 'description' => $description],
                );
            }
        }
    }

    /**
     * (2) Preset → role_permissions, ADD-ONLY. Returns the effective key=>value map per preset slug
     * (existing rows + the keys just added) so step 3 can expand the full intended set in one pass.
     *
     * @return array<string, array<string,int>>
     */
    private function syncPresetPermissions(bool $apply, PermissionSyncReport $report): array
    {
        $effective = [];

        foreach (RoleSeeder::presets() as $slug => $data) {
            // firstOrCreate (not updateOrCreate) so we never clobber an admin-renamed preset role.
            $role = $apply
                ? Role::firstOrCreate(
                    ['slug' => $slug],
                    ['name' => $data['name'], 'is_preset' => true, 'description' => $data['name'].' preset.'],
                )
                : Role::query()->where('slug', $slug)->first();

            /** @var array<string,int> $current */
            $current = $role ? $role->permissions()->pluck('value', 'permission_key')->all() : [];

            foreach ($data['permissions'] as $key => $value) {
                if (array_key_exists($key, $current)) {
                    continue; // existing key — leave the admin's value untouched
                }

                $report->permissionsAdded[$slug][] = $key;
                $current[$key] = $value;

                if ($apply && $role) {
                    $role->permissions()->create(['permission_key' => $key, 'value' => $value]);
                }
            }

            $effective[$slug] = $current;
        }

        return $effective;
    }

    /**
     * (3) Expand each preset onto its system group at GLOBAL scope, ADD-ONLY — write only the entries that
     * are missing; never overwrite an existing value (so admin customisation survives).
     *
     * @param  array<string, array<string,int>>  $effective
     */
    private function expandOntoGroups(bool $apply, PermissionSyncReport $report, array $effective): void
    {
        foreach (RoleSeeder::groupAssignments() as $groupSlug => $roleSlug) {
            $group = Group::query()->where('slug', $groupSlug)->first();
            if (! $group instanceof Group || ! isset($effective[$roleSlug])) {
                continue;
            }

            if ($apply) {
                // Ensure the role→group assignment row exists (robust if a system group is brand new).
                Role::query()->where('slug', $roleSlug)->first()?->assignments()->firstOrCreate([
                    'holder_type' => 'group',
                    'holder_id' => (int) $group->id,
                    'scope_type' => 'global',
                    'scope_id' => null,
                ]);
            }

            $present = AclEntry::query()
                ->where('holder_type', 'group')
                ->where('holder_id', (int) $group->id)
                ->where('scope_type', 'global')
                ->whereNull('scope_id')
                ->pluck('permission_key')
                ->flip();

            foreach ($effective[$roleSlug] as $key => $value) {
                if ($present->has($key)) {
                    continue;
                }

                $report->entriesWritten[$groupSlug][] = $key;

                if ($apply) {
                    AclEntry::create([
                        'permission_key' => $key,
                        'holder_type' => 'group',
                        'holder_id' => (int) $group->id,
                        'scope_type' => 'global',
                        'scope_id' => null,
                        'value' => $value,
                    ]);
                }
            }
        }
    }
}
