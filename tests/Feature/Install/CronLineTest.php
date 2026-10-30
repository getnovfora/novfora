<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Install\CronLine;

/*
| NOV-165 — the one cron line the installer prints must be a single absolute command. Hostinger's cron panel
| silently never runs a compound `cd /path && php artisan schedule:run` entry, which left a production queue
| undrained since install. Artisan resolves its own base path, so `<php> <base>/artisan schedule:run` works
| from any working directory.
*/

it('prints a single absolute command — no cd, no && — that runs the scheduler', function () {
    $line = CronLine::line('/usr/bin/php', '/home/u1/novfora');

    expect($line)->toBe('* * * * * /usr/bin/php /home/u1/novfora/artisan schedule:run >> /dev/null 2>&1')
        ->not->toContain('cd ')
        ->not->toContain('&&');
});

it('defaults to this install\'s base path and an absolute PHP CLI binary', function () {
    $line = CronLine::line();

    expect($line)->toContain(' '.base_path('artisan').' schedule:run')
        ->and(CronLine::phpBinary())->toStartWith('/')     // the suite runs under the CLI SAPI → PHP_BINARY
        ->and(is_executable(CronLine::phpBinary()))->toBeTrue();
});

it('quotes a path that contains spaces or shell metacharacters, and only then', function () {
    $line = CronLine::line('/opt/my php/bin/php', '/home/u1/my forum');

    expect($line)->toBe("* * * * * '/opt/my php/bin/php' '/home/u1/my forum/artisan' schedule:run >> /dev/null 2>&1");
});

it('tolerates a trailing slash on the base path', function () {
    expect(CronLine::line('/usr/bin/php', '/srv/novfora/'))->toContain(' /srv/novfora/artisan schedule:run');
});
