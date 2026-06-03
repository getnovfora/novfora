<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Per-event × per-channel notification preference (data-model §7); absent row = the default applies. */
class NotificationPreference extends Model
{
    protected $guarded = [];

    protected $casts = [
        'enabled' => 'boolean',
    ];
}
