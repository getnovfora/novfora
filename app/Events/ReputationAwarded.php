<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A user's reputation total rose via a real ledger insert (P2-M5) — the rep-threshold badge trigger.
 * Dispatched by ReputationService::award() ONLY when a new event row landed (a no-op award fires
 * nothing), carrying the recipient. recomputeFor() deliberately does NOT fire this — the badge cron
 * sweep owns catch-up after a heal (badges are permanent, so a downward heal revokes nothing).
 */
final class ReputationAwarded
{
    use Dispatchable;

    public function __construct(public readonly User $recipient) {}
}
