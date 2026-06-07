<?php

// SPDX-License-Identifier: Apache-2.0
// Test fixture (RH-10): step two of a two-migration "release" — this one FAILS, after step one was
// recorded. The runner should roll back step one (this run's batch) on the failure.

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        throw new RuntimeException('RH-10 fixture: step two intentional failure.');
    }

    public function down(): void
    {
        // nothing to undo — up() never completed
    }
};
