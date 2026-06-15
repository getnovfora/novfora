<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Word filters (security §3 / data-model §5). A match can rewrite the text (`replace`), hold the content
// for moderation (`flag`), or reject the submission (`block`). Applied server-side to the text projection
// during the content write path; like all content rules, never trusts client input.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('word_filters')) {
            Schema::create('word_filters', function (Blueprint $table) {
                $table->id();
                $table->string('pattern');
                $table->string('replacement')->nullable();
                $table->string('action', 20)->default('replace'); // replace | flag | block
                $table->boolean('is_regex')->default(false);
                $table->boolean('whole_word')->default(true);
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('word_filters');
    }
};
