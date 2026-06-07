<?php

// SPDX-License-Identifier: Apache-2.0
// Test fixture (RH-10): step one of a two-migration "release" — this one SUCCEEDS and is recorded, so the
// run leaves a rollback-able batch when step two (next file) then fails. Used to assert the runner rolls
// back exactly this run's batch.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rh10_step_one', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rh10_step_one');
    }
};
