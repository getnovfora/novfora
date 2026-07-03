<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Jobs;

use App\Admin\DelegationService;
use App\Admin\GroupManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The BOUNDED + QUEUED delegation fan-out for a GROUP mask reduction (NOV-121, closes the ADR-0087 gap — apex).
 * When a group's standing permissions are edited DOWN (the ⚡group-editor / ⚡group-simple-editor / the
 * category bulk-apply), every MEMBER of that group who has granted live delegations may now exceed their reduced
 * CURRENT mask — the exact invariant "a delegation never outlives its delegator's current mask" that
 * {@see GroupManager::removeMember} already honours on the admins-removal door. This job re-checks
 * each such delegator through {@see DelegationService::cascadeForActor()} (the SAME proven primitive), revoking
 * only the delegations that no longer pass a live canDo() at their scope.
 *
 * Bounded to ACTUAL delegators (a group with none does nothing), chunked, and drained by the cron queue on the
 * Baseline tier (the ADR-0097 discipline) so a large group never blocks the permission-editor request thread.
 */
class CascadeDelegationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @param  list<int>  $groupIds  the groups whose standing mask was just reduced */
    public function __construct(public array $groupIds) {}

    public function handle(DelegationService $delegations): void
    {
        $delegations->cascadeForGroups($this->groupIds);
    }
}
