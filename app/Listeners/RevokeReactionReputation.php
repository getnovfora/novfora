<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Listeners;

use App\Community\ReputationService;
use App\Events\ReactionRemoved;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Revoke whatever a now-removed reaction had awarded (P2-M5 ⚙ — the unreact half of the reputation
 * wiring). QUEUED off the hot path like the award. The event carries the already-deleted Reaction model:
 * its id keys the ledger's UNIQUE source slot, and revoke() decrements by the STORED points — so the undo
 * is exact even if the config weight changed since the award, and a replay is a no-op (row already gone).
 */
final class RevokeReactionReputation implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $deleteWhenMissingModels = true;

    public function __construct(private readonly ReputationService $reputation) {}

    public function handle(ReactionRemoved $event): void
    {
        $this->reputation->revoke($event->reaction);
    }
}
