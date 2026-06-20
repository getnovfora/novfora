<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Group;
use App\Models\Role;
use App\Permissions\PermissionValue;
use App\Permissions\RoleExpander;
use Illuminate\Database\Seeder;

/**
 * Role presets (ADR-0006). Built compositionally — each role includes the one below it — then
 * EXPANDED onto the matching system group at global scope (security §1.1). Idempotent.
 */
class RoleSeeder extends Seeder
{
    /** @return array<string, array{name:string, permissions: array<string,int>}> */
    public static function presets(): array
    {
        $allow = PermissionValue::Allow->value;

        $guest = ['forum.view' => $allow];

        $member = $guest + [
            'topic.create' => $allow,
            'post.create' => $allow,
            'post.edit.own' => $allow,
            'post.delete.own' => $allow,
            'attachment.create' => $allow,
            'react.create' => $allow,  // reacting is ungated participation; abuse handled by ReactionRateLimiter
            'poll.vote' => $allow,     // voting is ungated participation
            'tag.apply' => $allow,     // applying an EXISTING tag is ungated participation; mint is separately gated
            // follow.delete is ungated (undoing your own follow is always allowed); follow.create is
            // deliberately withheld here — like poll.create it is TL-gated soft (no) at TL0 and granted
            // from TL1 via config/novfora.php trust_gates (a member-preset ALLOW would lift the TL0 NO
            // under the most-permissive group merge, defeating the gate).
            'follow.delete' => $allow,
        ];

        $moderator = $member + [
            'post.edit.any' => $allow,
            'post.delete.any' => $allow,
            'post.history.view' => $allow, // staff see anyone's history; an author sees their own (component short-circuit)
            'topic.moderate' => $allow,
            'bans.manage' => $allow,
            // Staff are never spam-gated by trust level: grant the progressive capabilities outright so a
            // moderator/admin can post links/images regardless of their TL group. (Staff are not TL0 in
            // practice; a NEVER on TL0 would still bite a misassigned TL0 staff account — by design.)
            'post.links' => $allow,
            'post.images' => $allow,
            'pm.send' => $allow,
            // Staff create polls regardless of trust level (poll.create is withheld from the base member
            // preset and granted progressively from TL1 — see config/novfora.php trust_gates).
            'poll.create' => $allow,
            // Staff can mint new tags regardless of trust level (tag.create is withheld from the base member
            // preset and granted progressively from TL1 — see config/novfora.php trust_gates). tag.apply is
            // already inherited from the member preset.
            'tag.create' => $allow,
            // Staff follow regardless of trust level (follow.create is withheld from the base member preset
            // and granted progressively from TL1 — see config/novfora.php trust_gates).
            'follow.create' => $allow,
        ];

        $administrator = $moderator + [
            'admin.access' => $allow,
            'admin.settings' => $allow,
            'users.manage' => $allow,
            'groups.manage' => $allow,
            'prefix.manage' => $allow,
            'badge.manage' => $allow,
            'permissions.manage' => $allow,
            // Clubs (Phase 4 · M1.2): a global admin manages ANY club. Held at global scope so it inherits into
            // every club scope; club OWNERS get it per-club via ClubRoleProjector (scope_type='club').
            'club.manage' => $allow,
            // Per-section ACP access (ACP v3 · v3-a, ADR-0080). The administrator preset grants every rail section
            // EXCEPT Security — additive, so every existing admin keeps the full rail once PermissionSync
            // propagates these on upgrade. admin.security.access is held only by co-owners (AdminCoOwnerService),
            // and a bundle-restricted admin (NOT in the admins group) holds only their bundle's subset as per-user
            // grants. Keep these in sync with the catalog's Administration cluster.
            'admin.forums.access' => $allow,
            'admin.members.access' => $allow,
            'admin.groups.access' => $allow,
            'admin.moderation.access' => $allow,
            'admin.appearance.access' => $allow,
            'admin.plugins.access' => $allow,
            'admin.analytics.access' => $allow,
            'admin.settings.access' => $allow,
            'admin.system.access' => $allow,
        ];

        return [
            'guest' => ['name' => 'Guest (read-only)', 'permissions' => $guest],
            'member' => ['name' => 'Member', 'permissions' => $member],
            'moderator' => ['name' => 'Moderator', 'permissions' => $moderator],
            'administrator' => ['name' => 'Administrator', 'permissions' => $administrator],
        ];
    }

    /** Which preset each system group receives at global scope. @return array<string,string> */
    public static function groupAssignments(): array
    {
        return [
            'guests' => 'guest',
            'members' => 'member',
            'moderators' => 'moderator',
            'admins' => 'administrator',
        ];
    }

    public function run(RoleExpander $expander): void
    {
        $roles = [];

        foreach (self::presets() as $slug => $data) {
            $role = Role::updateOrCreate(
                ['slug' => $slug],
                ['name' => $data['name'], 'is_preset' => true, 'description' => $data['name'].' preset.'],
            );

            foreach ($data['permissions'] as $key => $value) {
                $role->permissions()->updateOrCreate(['permission_key' => $key], ['value' => $value]);
            }

            $roles[$slug] = $role;
        }

        // Expand each preset onto its system group at global scope → acl_entries (the resolver's input).
        foreach (self::groupAssignments() as $groupSlug => $roleSlug) {
            $group = Group::where('slug', $groupSlug)->first();
            if ($group instanceof Group && isset($roles[$roleSlug])) {
                $expander->assignToGroup($roles[$roleSlug], $group);
            }
        }
    }
}
