<?php

// SPDX-License-Identifier: Apache-2.0
// Test fixture (RH-10): a "new release" migration whose up() fails BEFORE recording anything — the
// single-migration failure case. Used to assert the runner aborts cleanly (no rollback of the previous
// good batch), keeps the site in maintenance, and holds for the operator.

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        throw new RuntimeException('RH-10 fixture: intentional migration failure.');
    }

    public function down(): void
    {
        // nothing to undo — up() never completed
    }
};
