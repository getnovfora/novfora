<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Laravel notifications (data-model §7). The `data` JSON enables MERGE-AWARE notifications
// ("X and 3 others replied in [thread]") without a row per event. Real-time on the enhanced tier
// (Reverb, Phase 4); polling on baseline.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at']); // unread lookups (data-model §9)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
