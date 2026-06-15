<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Admin-defined profile fields (data-model §1). The catalog (`custom_fields`) is defined in the ACP; each
// user's answers live in `custom_field_values`. Labels are translatable later (data-model §10).
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('custom_fields')) {
            Schema::create('custom_fields', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('label');
                $table->string('type', 20)->default('text'); // text | url | textarea
                $table->json('options')->nullable();
                $table->unsignedInteger('position')->default(0);
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('custom_field_values')) {
            Schema::create('custom_field_values', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('custom_field_id')->constrained()->cascadeOnDelete();
                $table->text('value')->nullable();

                $table->unique(['user_id', 'custom_field_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_values');
        Schema::dropIfExists('custom_fields');
    }
};
