<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CustomField;
use Illuminate\Database\Seeder;

/** A couple of example custom profile fields (data-model §1). Idempotent; admins edit them in the ACP. */
class CustomFieldSeeder extends Seeder
{
    /** @return array<string, array{label:string, type:string, position:int}> */
    public static function fields(): array
    {
        return [
            'location' => ['label' => 'Location', 'type' => 'text', 'position' => 1],
            'website' => ['label' => 'Website', 'type' => 'url', 'position' => 2],
        ];
    }

    public function run(): void
    {
        foreach (self::fields() as $key => $data) {
            CustomField::updateOrCreate(
                ['key' => $key],
                ['label' => $data['label'], 'type' => $data['type'], 'position' => $data['position'], 'is_active' => true],
            );
        }
    }
}
