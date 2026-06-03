<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AclEntry;
use App\Models\Group;
use App\Permissions\PermissionValue;
use Illuminate\Database\Seeder;

/**
 * Trust-level anti-spam gating (ADR-0007 / security §2.3) — the crux of M3.
 *
 * Writes the `config('hearth.antispam.trust_gates')` matrix as acl_entries on the seeded TL groups at
 * global scope. Because trust levels ARE ACL groups, new-user gating runs entirely through the existing
 * PermissionResolver — there is NO second permission system. TL0 carries NEVER on true spam vectors
 * (links/images/mass-PM) so even an admin's per-forum ALLOW cannot lift it (NEVER is absolute); higher
 * trust groups grant the progressive capabilities. Idempotent (updateOrCreate keyed by holder+perm+scope).
 */
class TrustGateSeeder extends Seeder
{
    /** Map the config:cache-safe string values to the three-state PermissionValue. */
    private const VALUES = [
        'allow' => PermissionValue::Allow,
        'no' => PermissionValue::No,
        'never' => PermissionValue::Never,
    ];

    public function run(): void
    {
        /** @var array<string, array<string, string>> $gates */
        $gates = config('hearth.antispam.trust_gates', []);

        foreach ($gates as $slug => $entries) {
            $group = Group::where('slug', $slug)->first();
            if (! $group instanceof Group) {
                continue; // a tenant install may not have every level seeded; skip rather than fail
            }

            foreach ($entries as $permissionKey => $value) {
                $resolved = self::VALUES[$value] ?? null;
                if ($resolved === null) {
                    continue; // an unknown gate value is ignored rather than seeding a wrong grant
                }

                AclEntry::updateOrCreate(
                    [
                        'permission_key' => $permissionKey,
                        'holder_type' => 'group',
                        'holder_id' => (int) $group->id,
                        'scope_type' => 'global',
                        'scope_id' => null,
                    ],
                    ['value' => $resolved->value],
                );
            }
        }
    }
}
