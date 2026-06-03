<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

/**
 * The catalog of known permission keys (ADR-0006). Grants live in acl_entries; this table is the
 * human-readable reference list that powers the admin UI and the inspector. Idempotent.
 */
class PermissionCatalogSeeder extends Seeder
{
    /**
     * @return array<string, array{0:string,1:string,2:string,3:string}> key => [label, scope_kind, group, description]
     */
    public static function catalog(): array
    {
        return [
            // Administration (global scope)
            'admin.access' => ['Access the admin control panel', 'global', 'Administration', 'Open the ACP at all.'],
            'admin.settings' => ['Manage system settings', 'global', 'Administration', 'Change board configuration.'],
            'users.manage' => ['Manage user accounts', 'global', 'Administration', 'Edit, suspend, or delete users.'],
            'groups.manage' => ['Manage groups & memberships', 'global', 'Administration', 'Create groups and assign members.'],
            'permissions.manage' => ['Manage roles & permissions', 'global', 'Administration', 'Edit ACL entries and role presets.'],
            'bans.manage' => ['Issue & lift bans', 'global', 'Moderation', 'Ban or unban users, globally or per scope.'],

            // Reading / posting / moderation (forum scope)
            'forum.view' => ['View forums & topics', 'forum', 'Reading', 'See a forum and read its topics.'],
            'topic.create' => ['Start new topics', 'forum', 'Posting', 'Open a new topic in a forum.'],
            'post.create' => ['Reply to topics', 'forum', 'Posting', 'Post a reply in a topic.'],
            'post.edit.own' => ['Edit own posts', 'forum', 'Posting', 'Edit posts you authored.'],
            'post.delete.own' => ['Delete own posts', 'forum', 'Posting', 'Delete posts you authored.'],
            'attachment.create' => ['Upload attachments', 'forum', 'Posting', 'Attach files to a post.'],
            'post.edit.any' => ['Edit any post', 'forum', 'Moderation', 'Edit posts by any author.'],
            'post.delete.any' => ['Delete any post', 'forum', 'Moderation', 'Delete posts by any author.'],
            'topic.moderate' => ['Moderate topics', 'forum', 'Moderation', 'Lock, pin, or move topics.'],
        ];
    }

    public function run(): void
    {
        foreach (self::catalog() as $key => [$label, $scopeKind, $group, $description]) {
            Permission::updateOrCreate(
                ['key' => $key],
                ['label' => $label, 'scope_kind' => $scopeKind, 'group' => $group, 'description' => $description],
            );
        }
    }
}
