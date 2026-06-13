<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A 301 redirect from a legacy URL path to a new canonical path (ADR-0034) — emitted by the importers so link
 * equity and bookmarks survive a migration. Served by App\Http\Middleware\LegacyRedirects.
 *
 * @property string $from_path
 * @property string $to_path
 * @property int $status
 */
class Redirect extends Model
{
    protected $guarded = [];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['status' => 'integer'];
    }
}
