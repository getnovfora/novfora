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
        ]);
    }
}
