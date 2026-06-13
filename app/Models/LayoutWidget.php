<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A widget placed into a layout region (ADR-0032). Written only through App\Theme\LayoutManager (the audited
 * configurator authority); read by the `<x-region>` outlet.
 *
 * @property string $region
 * @property string $widget_key
 * @property int $position
 * @property array<string,mixed>|null $settings
 * @property bool $is_enabled
 */
class LayoutWidget extends Model
{
    protected $guarded = [];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_enabled' => 'bool',
        ];
    }
}
