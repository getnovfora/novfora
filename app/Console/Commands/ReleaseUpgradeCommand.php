<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Upgrade\ReleaseCoordinator;
use App\Upgrade\ReleaseRunState;
use Illuminate\Console\Command;

class ReleaseUpgradeCommand extends Command
{
    protected $signature = 'novfora:release-upgrade {--advance : Prepare the pending owner-requested release}';

    protected $description = 'Show or advance an owner-requested signed stable release upgrade';

    public function handle(ReleaseCoordinator $coordinator, ReleaseRunState $state): int
    {
        $run = $this->option('advance') ? $coordinator->advance() : $state->read();
        $this->line((string) json_encode([
            'phase' => $run['phase'] ?? 'idle',
            'version' => $run['version'] ?? null,
        ], JSON_THROW_ON_ERROR));

        return in_array($run['phase'] ?? '', ['failed', 'held'], true) ? self::FAILURE : self::SUCCESS;
    }
}
