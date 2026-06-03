<?php

uses(
    DuskTestCase::class,
    // Illuminate\Foundation\Testing\DatabaseMigrations::class,
)->in('Browser');

use Tests\DuskTestCase;
use Tests\TestCase;

// SPDX-License-Identifier: Apache-2.0

/*
| Pest bootstrap — bind the Laravel TestCase to Feature tests so they get the application
| container, config(), and helpers like $this->artisan(). Unit tests stay framework-free.
*/

uses(TestCase::class)->in('Feature');
