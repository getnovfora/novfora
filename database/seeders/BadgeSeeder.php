<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Badge;
use Illuminate\Database\Seeder;

/**
 * The starter badge set (P2-M5) — the proposed defaults from the kickoff, owner-tunable in the ACP
 * (Admin → Content → Badges): names, thresholds, colours and active state are all editable; slugs are
 * the stable identity. Idempotent on slug, and NON-DESTRUCTIVE on re-run: only missing badges are
 * created, so an owner's edits to the seeded set survive a re-seed/upgrade.
 */
class BadgeSeeder extends Seeder
{
    /** @return array<string, array{name:string, description:string, criteria:array<string,int|string>, color_token:?string, icon_token:?string}> */
    public static function defaults(): array
    {
        return [
            'welcome' => [
                'name' => 'Welcome',
                'description' => 'Joined the community.',
                'criteria' => ['type' => 'join'],
                'color_token' => 'teal',
                'icon_token' => 'user',
            ],
            'first-post' => [
                'name' => 'First Post',
                'description' => 'Published a first post.',
                'criteria' => ['type' => 'post_count', 'threshold' => 1],
                'color_token' => 'blue',
                'icon_token' => 'message',
            ],
            'conversationalist' => [
                'name' => 'Conversationalist',
                'description' => 'Published 25 posts.',
                'criteria' => ['type' => 'post_count', 'threshold' => 25],
                'color_token' => 'indigo',
                'icon_token' => 'users',
            ],
            'well-regarded' => [
                'name' => 'Well-Regarded',
                'description' => 'Earned 10 reputation points.',
                'criteria' => ['type' => 'reputation', 'threshold' => 10],
                'color_token' => 'amber',
                'icon_token' => 'check-circle',
            ],
        ];
    }

    public function run(): void
    {
        foreach (self::defaults() as $slug => $attributes) {
            Badge::firstOrCreate(['slug' => $slug], $attributes + ['is_active' => true]);
        }
    }
}
