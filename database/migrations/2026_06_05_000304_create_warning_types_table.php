<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Pre-defined warning "action bundles" (IPS concept; security §3 / data-model §5). Each type carries a
// default point weight and an optional default consequence (JSON) applied when the warning is issued.
// Seeded with sensible defaults (WarningTypeSeeder); fully editable in the ACP.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('warning_types')) {
            Schema::create('warning_types', function (Blueprint $table) {
                $table->id();
                $table->string('slug')->unique();
                $table->string('label');
                $table->unsignedInteger('default_points')->default(0);
                $table->unsignedInteger('decay_days')->nullable();   // points expire after N days (time-decay); null = never
                $table->json('default_action')->nullable();           // {action: restrict|moderate|temp_ban|ban, days?: int}
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('warning_types');
    }
};
