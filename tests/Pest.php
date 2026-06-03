<?php

// SPDX-License-Identifier: Apache-2.0

use Tests\DuskTestCase;
use Tests\TestCase;

/*
| Pest bootstrap — bind the Laravel TestCase to Feature tests so they get the application
| container, config(), and helpers like $this->artisan(). Unit tests stay framework-free.
*/

uses(TestCase::class)->in('Feature');

// Dusk browser journeys — the Spike-0 editor battery, run via `php artisan dusk` (Chrome-enabled CI).
// The normal pest run uses phpunit.xml's Unit + Feature suites, so it never loads Browser/Chrome.
uses(DuskTestCase::class)->in('Browser');
