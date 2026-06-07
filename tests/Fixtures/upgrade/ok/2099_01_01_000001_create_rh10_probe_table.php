<?php

// SPDX-License-Identifier: Apache-2.0
// Test fixture (RH-10): a stand-in "new release" migration. Lives outside database/migrations so the
// normal suite never sees it; the auto-upgrade tests add this directory to hearth.upgrade.migration_paths
// so it reads as a pending migration and the runner applies it for real.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rh10_probe', function (Blueprint $table) {
            $table->id();
            $table->string('note')->default('applied');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rh10_probe');
    }
};
