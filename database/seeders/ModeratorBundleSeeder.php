<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use App\Permissions\ForumModeratorProjector;
use App\Permissions\PermissionValue;
use Illuminate\Database\Seeder;

/**
 * ACP v3 · v3-b (per-forum moderators, ADR-0085). Seeds the three forum-moderator preset bundles as
 * `is_preset` roles (read-only in the v3-d builder). UNLIKE {@see RoleSeeder}, these are deliberately NOT
 * expanded onto any group at global scope — ONLY {@see ForumModeratorProjector} expands them,
 * at FORUM scope, when an admin assigns a moderator. Idempotent (updateOrCreate by slug + per-key).
 *
 * `bans.manage` rides in the "full" bundle for completeness, but its catalog scope_kind is GLOBAL — so a
 * forum-scoped expansion of it is INERT at resolution (a per-forum moderator does not gain global ban powers;
 * bans stay a global capability). This is intentional and flagged per the v3-b spec.
 */
class ModeratorBundleSeeder extends Seeder
{
    /** @return array<string, array{name:string, description:string, permissions: list<string>}> */
    public static function bundles(): array
    {
        return [
            'forum-mod-full' => [
                'name' => 'Forum Moderator — Full',
                'description' => 'Full per-forum moderation: lock/pin/move topics, edit & delete any post, view post history. (bans.manage is included for completeness but resolves at global scope, so it is inert per-forum.)',
                'permissions' => ['topic.moderate', 'post.edit.any', 'post.delete.any', 'post.history.view', 'bans.manage'],
            ],
            'forum-mod-content' => [
                'name' => 'Forum Moderator — Content',
                'description' => 'Content moderation: edit & delete any post and view post history. No topic lock/pin/move.',
                'permissions' => ['post.edit.any', 'post.delete.any', 'post.history.view'],
            ],
            'forum-mod-queue' => [
                'name' => 'Forum Moderator — Queue',
                'description' => "Triage moderation: lock/pin/move topics, delete any post, view post history. Cannot edit other authors' posts.",
                'permissions' => ['topic.moderate', 'post.delete.any', 'post.history.view'],
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
