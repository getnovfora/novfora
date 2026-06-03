<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Anti-spam trust automation (ADR-0007 §2.3): auto promotion/demotion. Cron-driven and idempotent
// (ADR-0011); overlap-guarded so a long run on a large board never doubles up on a coarse interval.
Schedule::command('hearth:trust:recompute')->hourly()->withoutOverlapping();

// Privacy/GDPR retention (ADR-0007 §2.6): purge aged registration checks + expired blocklist cache.
Schedule::command('hearth:antispam:purge')->daily();
