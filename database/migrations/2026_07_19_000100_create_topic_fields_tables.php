<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// Custom TOPIC fields (U19 / NOV-116) — admin-defined, typed, optionally-required extra fields captured when a
// topic is created and shown in the topic header. Parallels the profile `custom_fields` tables but keyed to a
// topic and forum-SCOPED like `prefixes` (nullable forum_id = a global catalog entry, or one forum's). Fully
// reversible: down() drops both tables (and their values cascade).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('topic_fields', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('forum_id')->nullable()->constrained('forums')->nullOnDelete(); // null = global (all forums)
            $table->string('key', 40)->unique();
            $table->string('label', 80);
            $table->string('type', 20)->default('text'); // text | url | textarea | select
            $table->json('options')->nullable();          // the choice list for a `select` field
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('tenant_id')->nullable()->index(); // multi-tenant seam (kept clean, unused today)
            $table->timestamps();
            $table->index(['forum_id', 'position']);
        });

        Schema::create('topic_field_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('topic_id')->constrained('topics')->cascadeOnDelete();
            $table->foreignId('topic_field_id')->constrained('topic_fields')->cascadeOnDelete();
            $table->text('value')->nullable();
            $table->unique(['topic_id', 'topic_field_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topic_field_values');
        Schema::dropIfExists('topic_fields');
    }
};
