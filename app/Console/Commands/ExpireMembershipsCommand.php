<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Membership\MembershipService;
use Illuminate\Console\Command;

/**
 * Expire membership subscriptions past their expiry and revoke their perks (Phase 4 · M5.1). Scheduled hourly
 * (`withoutOverlapping`), so it is baseline-safe on a cron-only host — no worker required.
 */
class ExpireMembershipsCommand extends Command
{
    protected $signature = 'novfora:tiers:expire';

    protected $description = 'Expire membership subscriptions past their expiry and revoke their perks.';

    public function handle(MembershipService $service): int
    {
        $count = $service->expireDue();
        $this->info("Expired {$count} membership subscription(s).");

        return self::SUCCESS;
    }
}
