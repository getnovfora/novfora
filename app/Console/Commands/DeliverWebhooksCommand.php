<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Webhooks\WebhookDeliveryRunner;
use Illuminate\Console\Command;

/**
 * Delivers pending outbound webhooks (ADR-0033). Driven by the scheduler every minute, so egress works on the
 * baseline tier with no persistent worker; overlap-guarded so a coarse cron interval never double-sends.
 */
final class DeliverWebhooksCommand extends Command
{
    protected $signature = 'webhooks:deliver {--limit=100 : Maximum deliveries to process this run}';

    protected $description = 'Deliver pending outbound webhooks (cron egress).';

    public function handle(WebhookDeliveryRunner $runner): int
    {
        $count = $runner->runPending((int) $this->option('limit'));
        $this->info("Processed {$count} webhook deliver".($count === 1 ? 'y' : 'ies').'.');

        return self::SUCCESS;
    }
}
