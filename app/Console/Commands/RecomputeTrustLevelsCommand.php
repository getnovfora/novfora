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
    protected $signature = 'novfora:trust:recompute {--user= : Recompute (and diagnose) only this user — id or username}';

    protected $description = 'Recompute trust-level group membership (auto promotion/demotion).';

    public function handle(TrustLevelManager $manager): int
    {
        $ref = $this->option('user');
        if ($ref !== null) {
            return $this->diagnoseAndRecompute($manager, (string) $ref);
        }

        $changed = 0;
        User::query()->each(function (User $user) use ($manager, &$changed) {
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

    /** Diagnose ONE user (id or username): print the computed level + the reason it did/didn't change, then apply. */
    private function diagnoseAndRecompute(TrustLevelManager $manager, string $ref): int
    {
        $user = ctype_digit($ref)
            ? User::find((int) $ref)
            : User::where('username', $ref)->first();

        if (! $user instanceof User) {
            $this->error("No user matching “{$ref}”.");

            return self::FAILURE;
        }

        $diag = $manager->explain($user);
        $this->line("user {$user->getKey()} ({$user->username}): currently TL{$diag['current']}");
        $this->line("  reason: {$diag['reason']}");

        $to = $manager->recompute($user);
        if ($to !== $diag['current']) {
            $this->info("  applied: TL{$diag['current']} → TL{$to}");
        } else {
            $this->line("  no change (TL{$to}).");
        }

        return self::SUCCESS;
    }
}
