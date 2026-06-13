<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An installed module/plugin (ADR-0031). A row exists once a local module is installed; `enabled` controls
 * whether its provider loads and its migrations are applied. All writes go through App\Modules\ModuleManager,
 * which is the single audited lifecycle authority — never mass-assign this from a request.
 *
 * @property string $slug
 * @property string $name
 * @property string $version
 * @property string $api_version
 * @property bool $enabled
 * @property array<int,string>|null $permission_keys
 * @property array<string,mixed>|null $meta
 */
class Module extends Model
{
    protected $guarded = [];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return [
            'enabled' => 'bool',
            'permission_keys' => 'array',
            'meta' => 'array',
            'installed_at' => 'datetime',
        ];
    }
}
