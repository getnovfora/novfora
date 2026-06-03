<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\WarningType;
use Illuminate\Database\Seeder;

/**
 * Default warning "action bundles" (IPS concept; security §3). Points are time-decaying (decay_days);
 * the live sum drives automated consequences at thresholds (config). Idempotent (keyed by slug); fully
 * editable in the ACP.
 */
class WarningTypeSeeder extends Seeder
{
    /** @return array<string, array{label:string, default_points:int, decay_days:?int, default_action:?array<string,mixed>}> */
    public static function types(): array
    {
        return [
            'minor' => ['label' => 'Minor warning', 'default_points' => 1, 'decay_days' => 30, 'default_action' => null],
            'inappropriate' => ['label' => 'Inappropriate content', 'default_points' => 5, 'decay_days' => 60, 'default_action' => null],
            'spam' => ['label' => 'Spamming', 'default_points' => 10, 'decay_days' => 90, 'default_action' => ['action' => 'moderate']],
            'harassment' => ['label' => 'Harassment', 'default_points' => 15, 'decay_days' => 180, 'default_action' => ['action' => 'restrict']],
        ];
    }

    public function run(): void
    {
        foreach (self::types() as $slug => $data) {
            WarningType::updateOrCreate(
                ['slug' => $slug],
                [
                    'label' => $data['label'],
                    'default_points' => $data['default_points'],
                    'decay_days' => $data['decay_days'],
                    'default_action' => $data['default_action'],
                    'is_active' => true,
                ],
            );
        }
    }
}
