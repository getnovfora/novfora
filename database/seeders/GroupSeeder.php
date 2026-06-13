<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Group;
use Illuminate\Database\Seeder;

/**
 * System + trust-level groups (ADR-0006 / ADR-0007). Idempotent (keyed by slug).
 *
 * `priority` orders display and tie-breaks promotion — resolution itself is value-based (MAX / NEVER),
 * never priority-based. Trust levels ARE ACL groups: M1 establishes the groups and their
 * auto_promotion config (the gating primitive); promotion automation + anti-spam enforcement are M3.
 */
class GroupSeeder extends Seeder
{
    /** @return array<string, array{name:string, priority:int}> */
    public static function systemGroups(): array
    {
        return [
            'guests' => ['name' => 'Guests', 'priority' => 0],
            'members' => ['name' => 'Members', 'priority' => 10],
            'moderators' => ['name' => 'Moderators', 'priority' => 80],
            'admins' => ['name' => 'Administrators', 'priority' => 100],
        ];
    }

    /** @return array<string, array{name:string, priority:int, auto_promotion:array<string,mixed>}> */
    public static function trustGroups(): array
    {
        return [
            'tl0' => ['name' => 'Trust level 0 — new', 'priority' => 1, 'auto_promotion' => ['min_posts' => 0, 'min_days' => 0, 'min_topics_read' => 0]],
            // TL0→TL1 lifts the link/image NEVER gate, so it requires real engagement (security §2.3,
            // phase-1.5 F-D): posts + tenure + topics read, not a raw self-post count.
            'tl1' => ['name' => 'Trust level 1 — basic', 'priority' => 2, 'auto_promotion' => ['min_posts' => 5, 'min_days' => 1, 'min_topics_read' => 5]],
            // A3: tl2/tl3 also require a reputation floor (community standing), beside posts/tenure/min_trust.
            // tl1 stays reputation-free so a new member can earn TL1 by engagement alone (they have no rep yet).
            'tl2' => ['name' => 'Trust level 2 — member', 'priority' => 3, 'auto_promotion' => ['min_posts' => 50, 'min_days' => 15, 'min_trust_level' => 1, 'min_reputation' => 10]],
            'tl3' => ['name' => 'Trust level 3 — regular', 'priority' => 4, 'auto_promotion' => ['min_posts' => 200, 'min_days' => 50, 'min_trust_level' => 2, 'min_reputation' => 50]],
            'tl4' => ['name' => 'Trust level 4 — leader', 'priority' => 5, 'auto_promotion' => ['manual' => true]],
        ];
    }

    public function run(): void
    {
        foreach (self::systemGroups() as $slug => $data) {
            Group::updateOrCreate(
                ['slug' => $slug],
                ['name' => $data['name'], 'type' => 'system', 'priority' => $data['priority'], 'is_system' => true, 'auto_promotion' => null],
            );
        }

        foreach (self::trustGroups() as $slug => $data) {
            Group::updateOrCreate(
                ['slug' => $slug],
                ['name' => $data['name'], 'type' => 'trust', 'priority' => $data['priority'], 'is_system' => true, 'auto_promotion' => $data['auto_promotion']],
            );
        }
    }
}
