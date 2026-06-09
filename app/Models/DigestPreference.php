<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Spike P2 — a user's chosen digest cadence (data-model: digest_preferences). Absent row = 'immediate'
 * (the existing live behaviour: not in the digest path). 'daily'/'weekly' opt in; 'off' (what 1-click
 * unsubscribe sets) silences digest mail. Read by the send gate at both assembly and send time.
 */
class DigestPreference extends Model
{
    public const OFF = 'off';

    public const IMMEDIATE = 'immediate';

    public const DAILY = 'daily';

    public const WEEKLY = 'weekly';

    /** Cadences that put a user into the cron-batched digest path. */
    public const BATCHED = [self::DAILY, self::WEEKLY];

    public const CADENCES = [self::OFF, self::IMMEDIATE, self::DAILY, self::WEEKLY];

    protected $guarded = [];
}
