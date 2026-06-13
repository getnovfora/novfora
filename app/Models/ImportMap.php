<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One imported legacy entity (ADR-0034): `(source, kind, source_id) → target_id`, UNIQUE so the import is
 * idempotent and resumable.
 *
 * @property string $source
 * @property string $kind
 * @property int $source_id
 * @property int $target_id
 */
class ImportMap extends Model
{
    protected $guarded = [];

    public $timestamps = true;
}
