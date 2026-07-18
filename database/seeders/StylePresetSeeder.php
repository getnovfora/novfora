<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Seeders;

use App\Theme\StylePresets;
use Illuminate\Database\Seeder;

/**
 * U10 (NOV-108): install the shipped style presets — the "NovFora" base + the Daylight/Midnight descendants.
 * Idempotent by slug (StylePresets::install never touches an existing row), production-safe, nothing
 * auto-activated. Existing installs get the same presets via the ACP Themes page's "Install presets" action.
 */
class StylePresetSeeder extends Seeder
{
    public function run(): void
    {
        StylePresets::install();
    }
}
