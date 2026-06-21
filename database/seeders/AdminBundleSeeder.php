<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Seeders;

use App\Admin\AdminBundleService;
use App\Models\Role;
use App\Permissions\PermissionValue;
use Illuminate\Database\Seeder;

/**
 * ACP v3 · v3-a (Admin Manager, ADR-0080). Seeds the admin-section BUNDLES as `is_preset` roles (read-only in
 * the v3-d builder) — the "starting points" a co-owner applies to a restricted admin before per-key toggling.
 * UNLIKE {@see RoleSeeder} they are deliberately NOT expanded onto any group: {@see AdminBundleService}
 * copies a bundle's section keys onto an individual user as PER-USER global grants when a co-owner assigns it.
 *
 * Each bundle holds only `admin.<section>.access` SECTION keys (never the umbrella `admin.access` — the service
 * adds that to every restricted admin, and never `admin.security.access` — that is the co-owner tier). "Custom"
 * is intentionally blank: a panel-access starting point the co-owner fills in per section. Idempotent
 * (updateOrCreate by slug + per-key). Seed-only, mirroring v3-b's ModeratorBundleSeeder; the Admin Manager
 * degrades gracefully (per-key toggles still work) where a bundle preset is absent.
 */
class AdminBundleSeeder extends Seeder
{
    /** @return array<string, array{name:string, description:string, permissions: list<string>}> */
    public static function bundles(): array
    {
        return [
            'admin-bundle-full' => [
                'name' => 'Full (except Security)',
                'description' => 'Every admin section except the owner-only Security section. A near-complete admin who is not a co-owner.',
                'permissions' => [
                    'admin.forums.access', 'admin.members.access', 'admin.groups.access', 'admin.moderation.access',
                    'admin.appearance.access', 'admin.plugins.access', 'admin.analytics.access',
                    'admin.settings.access', 'admin.system.access',
                ],
            ],
            'admin-bundle-community' => [
                'name' => 'Community',
                'description' => 'Day-to-day community management: forums, members, groups, and moderation.',
                'permissions' => ['admin.forums.access', 'admin.members.access', 'admin.groups.access', 'admin.moderation.access'],
            ],
            'admin-bundle-content' => [
                'name' => 'Content',
                'description' => 'Forum structure and moderation — shape the board and keep it clean.',
                'permissions' => ['admin.forums.access', 'admin.moderation.access'],
            ],
            'admin-bundle-style' => [
                'name' => 'Style',
                'description' => 'Appearance only: themes, templates, and layout.',
                'permissions' => ['admin.appearance.access'],
            ],
            'admin-bundle-analytics' => [
                'name' => 'Analytics',
                'description' => 'The Analytics section only — read the board\'s numbers, change nothing else.',
                'permissions' => ['admin.analytics.access'],
            ],
            'admin-bundle-custom' => [
                'name' => 'Custom',
                'description' => 'A blank starting point: panel access with no sections. Toggle the sections you want.',
                'permissions' => [],
            ],
        ];
    }

    public function run(): void
    {
        $allow = PermissionValue::Allow->value;

        foreach (self::bundles() as $slug => $data) {
            $role = Role::updateOrCreate(
                ['slug' => $slug],
                ['name' => $data['name'], 'is_preset' => true, 'description' => $data['description']],
            );

            foreach ($data['permissions'] as $key) {
                $role->permissions()->updateOrCreate(['permission_key' => $key], ['value' => $allow]);
            }
        }
    }
}
