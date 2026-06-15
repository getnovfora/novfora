<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Granular per-event × per-channel notification preferences (data-model §7). Absence of a row means the
// configured default applies; a row is written only when a user opts a channel off (or back on).
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notification_preferences')) {
            Schema::create('notification_preferences', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('event_type', 40);  // reply | mention | moderation
                $table->string('channel', 20);      // database | mail
                $table->boolean('enabled')->default(true);
                $table->timestamps();

                $table->unique(['user_id', 'event_type', 'channel']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
