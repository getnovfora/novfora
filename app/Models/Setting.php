<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use App\Settings\Settings;
use App\Settings\SettingsRegistry;
use Illuminate\Database\Eloquent\Model;

/**
 * A single overridden site-setting row. Thin by design: all typing, defaulting, precedence, encryption,
 * and audit live in {@see Settings} + {@see SettingsRegistry}. The presence of
 * a row means "this key is overridden in the panel"; its absence means "track env/config" (ADR-0023).
 *
 * @property string $key
 * @property string|null $value
 * @property string $type
 * @property bool $is_encrypted
 */
class Setting extends Model
{
    protected $table = 'settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_encrypted' => 'boolean',
        ];
    }
}
