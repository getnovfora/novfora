<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// M0 operability guards: reversible migrations (no manual DB surgery on upgrade) + the backup skeleton.

it('migrations are fully reversible (migrate then rollback, no errors)', function () {
    $this->artisan('migrate:fresh')->assertSuccessful();
    $this->artisan('migrate:rollback')->assertSuccessful();
});

it('novfora:backup --dry-run reports a plan and succeeds', function () {
    $this->artisan('novfora:backup', ['--dry-run' => true])->assertSuccessful();
});
