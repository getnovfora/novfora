<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Tier\ServiceTier;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * `php artisan hearth:tier` — print the active service tier per capability and the reachability of
 * each optional enhanced service. Baseline-friendly (works on a shared host with no extra services).
 */
class TierStatusCommand extends Command
{
    protected $signature = 'hearth:tier';

    protected $description = 'Show the active service tier per capability and optional-service reachability.';

    public function handle(ServiceTier $tier): int
    {
        $snapshot = $tier->snapshot(fresh: true);

        $this->components->info('Overall tier: '.$snapshot->overall->label());

        $this->table(
            ['Capability', 'Driver', 'Tier'],
            collect($snapshot->capabilities)
                ->map(fn ($c) => [$c->capability->label(), $c->driver, $c->tier->label()])
                ->all(),
        );

        $this->newLine();
        $this->components->info('Optional enhanced services');

        $this->table(
            ['Service', 'Configured', 'Reachable', 'Latency', 'Unlocks'],
            collect($snapshot->services)->map(fn ($s) => [
                $s->label,
                $s->configured ? 'yes' : 'no',
                $s->configured ? ($s->reachable ? 'yes' : 'NO') : '—',
                $s->latencyMs !== null ? $s->latencyMs.'ms' : '—',
                Str::limit($s->unlocks, 46),
            ])->all(),
        );

        return self::SUCCESS;
    }
}
