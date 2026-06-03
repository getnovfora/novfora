<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Install\HostDoctor;
use Illuminate\Console\Command;

/**
 * `php artisan hearth:doctor` — a preflight that diagnoses shared-host gotchas before (and after) install:
 * PHP version + extensions, writable paths, disabled functions, open_basedir, the session/cache/queue
 * drivers, outbound mail, whether symlinks work (and the copy fallback), the backup method, and whether the
 * cron line is firing. `fail` items must be fixed; `warn` items are advisories the baseline tolerates.
 */
class DoctorCommand extends Command
{
    protected $signature = 'hearth:doctor';

    protected $description = 'Diagnose host compatibility (extensions, writable paths, disabled functions, symlink, cron, mail).';

    public function handle(HostDoctor $doctor): int
    {
        $result = $doctor->run();

        $this->newLine();
        $this->line('  <options=bold>Hearth host check</>');
        $this->newLine();

        foreach ($result['checks'] as $c) {
            $icon = match ($c['status']) {
                'pass' => '<info>✓</info>',
                'warn' => '<comment>!</comment>',
                default => '<error>✕</error>',
            };
            $this->line("  {$icon} {$c['name']} — {$c['detail']}");
        }

        $this->newLine();

        if ($result['ok']) {
            $this->components->info('No blocking issues. Amber (!) items are advisories — see the notes above.');

            return self::SUCCESS;
        }

        $this->components->error('Some required checks failed (✕). Fix them on your host, then re-run `php artisan hearth:doctor`.');

        return self::FAILURE;
    }
}
