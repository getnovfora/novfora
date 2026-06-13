<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Import\Contracts\SourceDriver;
use App\Import\Drivers\MybbDriver;
use App\Import\Drivers\PhpbbDriver;
use App\Import\Drivers\SmfDriver;
use App\Import\ImportException;
use App\Import\ImportRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Imports a legacy forum (ADR-0034). The operator configures a read-only DB connection to the legacy board
 * (in config/database.php), then runs e.g. `novfora:import phpbb --connection=legacy --prefix=phpbb_`. Idempotent
 * and resumable, so it can be re-run after an interruption or in cron windows. `--preflight` counts + plans
 * without writing.
 */
final class ImportCommand extends Command
{
    protected $signature = 'novfora:import {source : phpbb|mybb|smf}
        {--connection=legacy : a configured DB connection to the legacy board}
        {--prefix= : legacy table prefix (default per source)}
        {--preflight : count + plan only, no writes}
        {--batch=500 : rows per batch}';

    protected $description = 'Import a legacy forum (phpBB/MyBB/SMF) — clean-room, idempotent, resumable.';

    public function handle(ImportRunner $runner): int
    {
        try {
            $driver = $this->driver();

            if ($this->option('preflight')) {
                $plan = $runner->preflight($driver);
                $this->info("Preflight — {$plan['source']}:");
                foreach ($plan['counts'] as $kind => $count) {
                    $this->line(sprintf('  %-7s %d in source · %d already imported', $kind, $count, $plan['already_imported'][$kind] ?? 0));
                }

                return self::SUCCESS;
            }

            $this->info("Importing {$driver->key()} (idempotent + resumable)…");
            $report = $runner->import($driver, (int) $this->option('batch'));
            foreach ($report as $kind => $row) {
                $this->line(sprintf('  %-7s %d / %d  %s', $kind, $row['imported'], $row['source'], $row['complete'] ? 'OK' : 'INCOMPLETE'));
            }

            return self::SUCCESS;
        } catch (ImportException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function driver(): SourceDriver
    {
        $source = (string) $this->argument('source');
        $connection = DB::connection((string) $this->option('connection'));
        $prefix = (string) ($this->option('prefix') ?: '');

        return match ($source) {
            'phpbb' => new PhpbbDriver($connection, $prefix !== '' ? $prefix : 'phpbb_'),
            'mybb' => new MybbDriver($connection, $prefix !== '' ? $prefix : 'mybb_'),
            'smf' => new SmfDriver($connection, $prefix !== '' ? $prefix : 'smf_'),
            default => throw new ImportException("Unknown source '{$source}' — use phpbb, mybb, or smf."),
        };
    }
}
