<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Deliverability\DeliverabilityManager;
use Illuminate\Console\Command;

/**
 * Spike P2 — `php artisan hearth:deliverability:poll-bounces`. The daemon-free poll path: fetch a bounded
 * batch from the configured bounce mailbox (IMAP, guarded by the extension), parse each message (DSN/ARF,
 * clean-room), and suppress hard bounces / complaints. Degrades to a clean no-op when no mailbox is
 * available (the VERP + manual-ACP floor remains). Never throws. No-op while the pipeline is dormant.
 */
final class PollBouncesCommand extends Command
{
    protected $signature = 'hearth:deliverability:poll-bounces';

    protected $description = 'Poll the bounce mailbox and suppress hard bounces / complaints (no daemon).';

    public function handle(DeliverabilityManager $manager): int
    {
        if (! config('hearth.deliverability.enabled')) {
            $this->info('Deliverability pipeline is dormant (hearth.deliverability disabled).');

            return self::SUCCESS;
        }

        $suppressed = $manager->ingestAvailable();
        $this->info("Suppressed {$suppressed} address(es). Active ingestion path: {$manager->activePath()}.");

        return self::SUCCESS;
    }
}
