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
            'prefix.manage' => ['Manage topic prefixes', 'global', 'Administration', 'Create, edit, reorder and delete topic prefixes.'],
            'permissions.manage' => ['Manage roles & permissions', 'global', 'Administration', 'Edit ACL entries and role presets.'],
            'bans.manage' => ['Issue & lift bans', 'global', 'Moderation', 'Ban or unban users, globally or per scope.'],

            // Reading / posting / moderation (forum scope)
            'forum.view' => ['View forums & topics', 'forum', 'Reading', 'See a forum and read its topics.'],
            'topic.create' => ['Start new topics', 'forum', 'Posting', 'Open a new topic in a forum.'],
            'post.create' => ['Reply to topics', 'forum', 'Posting', 'Post a reply in a topic.'],
            'post.edit.own' => ['Edit own posts', 'forum', 'Posting', 'Edit posts you authored.'],
            'post.delete.own' => ['Delete own posts', 'forum', 'Posting', 'Delete posts you authored.'],
            'attachment.create' => ['Upload attachments', 'forum', 'Posting', 'Attach files to a post.'],
            'react.create' => ['React to posts', 'forum', 'Posting', 'Add a reaction (like / helpful / …) to a post. Rate-limited per trust level; not a trust hard-gate.'],
            'poll.create' => ['Create polls', 'forum', 'Posting', 'Attach a poll to a topic. Trust-gated (soft): TL0 cannot by default; granted from TL1 — an admin may lift it per-forum.'],
            'poll.vote' => ['Vote in polls', 'forum', 'Posting', 'Cast a vote in a topic poll.'],
            // Anti-spam trust gates (ADR-0007 §2.3): TL0 = NEVER (hard), TL1+ = ALLOW. Enforced by
            // link/image suppression in the content write path; the inspector explains a TL0 block.
            'post.links' => ['Post links', 'forum', 'Posting', 'Include hyperlinks in a post (hard-gated for new accounts).'],
            'post.images' => ['Post inline images', 'forum', 'Posting', 'Embed inline images in a post (hard-gated for new accounts).'],
            'pm.send' => ['Send private messages', 'global', 'Messaging', 'Start or reply to PMs. The TL0 gate is seeded now as an anti-spam seam; PM delivery ships in Phase 2.'],
            'post.edit.any' => ['Edit any post', 'forum', 'Moderation', 'Edit posts by any author.'],
            'post.history.view' => ['View post edit history', 'forum', 'Moderation', 'See the edit history & diffs of any post. The author can always view their own.'],
            'post.delete.any' => ['Delete any post', 'forum', 'Moderation', 'Delete posts by any author.'],
            'topic.moderate' => ['Moderate topics', 'forum', 'Moderation', 'Lock, pin, or move topics.'],

            // Tags (P2-M1, ADR-0007 §2.3)
            // tag.apply: ungated participation — a member can attach an existing tag to their topic.
            // tag.create: durable site-wide namespace write — hard-gated at TL0 like links/images.
            'tag.apply' => ['Apply tags to topics', 'forum', 'Posting', 'Attach an existing tag to a topic. Ungated participation; abuse handled by tag.create gating.'],
            'tag.create' => ['Create new tags', 'global', 'Posting', 'Mint a brand-new tag. Hard anti-spam gate: a new tag enters the durable site-wide namespace, so TL0 can never mint tags (NEVER); earned at TL1. Admins can lift tag.apply per-forum but cannot lift this NEVER for TL0.'],

            // Social (P2-M5, ADR-0028)
            // follow.create: soft-gated at TL0 (each follow notifies the followee → mass-follow is a
            // notification-spam vector); granted from TL1; rate-limited per trust level on top.
            // follow.delete: ungated member participation — a user may always undo their own follow,
            // even after a demotion takes follow.create away.
            'follow.create' => ['Follow members', 'global', 'Community', 'Start following another member (they are notified). Trust-gated (soft): TL0 cannot by default; granted from TL1 — an admin may lift it. Rate-limited per trust level.'],
            'follow.delete' => ['Unfollow members', 'global', 'Community', 'Stop following a member you follow. Ungated participation — undoing your own follow is always allowed.'],
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
