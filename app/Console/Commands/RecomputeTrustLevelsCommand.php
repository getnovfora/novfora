<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\AntiSpam\TrustLevelManager;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * `php artisan novfora:trust:recompute` — auto promotion/demotion of trust levels (ADR-0007 §2.3).
 * Idempotent and correct within one (coarse) cron interval (ADR-0011); scheduled hourly in routes/console.php.
 */
class RecomputeTrustLevelsCommand extends Command
{
    protected $signature = 'novfora:trust:recompute {--user= : Recompute only this user id}';

    protected $description = 'Recompute trust-level group membership (auto promotion/demotion).';

    public function handle(TrustLevelManager $manager): int
    {
        $changed = 0;

        User::query()
            ->when($this->option('user'), fn ($q, $id) => $q->whereKey($id))
            ->each(function (User $user) use ($manager, &$changed) {
                $from = (int) $user->trust_level;
                $to = $manager->recompute($user);
                if ($to !== $from) {
                    $changed++;
                    $this->line("user {$user->getKey()}: TL{$from} → TL{$to}");
                }
            });

        $this->info("Trust levels recomputed; {$changed} change(s).");

        return self::SUCCESS;
    }
}
