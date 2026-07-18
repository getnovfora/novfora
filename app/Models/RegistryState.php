<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Durable registry internal state (NOV-125 / ADR-0113) — notably the monotonic anti-rollback sequence floor,
 * which must survive a cache clear (a flushable floor would let an older signed feed replay). Key/value.
 */
class RegistryState extends Model
{
    protected $table = 'registry_state';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];
}
