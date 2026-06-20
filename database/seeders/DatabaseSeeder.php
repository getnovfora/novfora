<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the default permission posture (ADR-0006). Idempotent and production-safe: system + trust
     * groups, the permission catalog, and role presets expanded onto the system groups. Users are NOT
     * seeded — they come from registration; the first admin is created by the installer (auth slice).
     */
    public function run(): void
    {
        $this->call([
            GroupSeeder::class,
            PermissionCatalogSeeder::class,
            RoleSeeder::class,
            ModeratorBundleSeeder::class, // v3-b forum-moderator preset bundles (is_preset roles, NOT group-expanded)
            AdminBundleSeeder::class, // v3-a admin-section bundles (is_preset roles; applied per-user by AdminBundleService)
            TrustGateSeeder::class,   // anti-spam trust gates on the TL groups (ADR-0007 §2.3) — needs the groups + catalog
            BadgeSeeder::class,       // starter badge set (P2-M5) — owner-tunable in the ACP, non-destructive re-run
            WarningTypeSeeder::class, // default infraction "action bundles" (security §3)
            BlocklistSeeder::class,   // local disposable-email domain list (ADR-0007 §2.2)
            CustomFieldSeeder::class, // example profile fields (data-model §1)
        ]);
    }
}
