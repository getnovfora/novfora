<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Per-user last-read watermark per topic (data-model §9) — sparse (a row only for topics the user has
// opened), so "what's new" / unread scales without a row per (user × topic). A topic is unread when its
// last_posted_at is newer than the user's last_read_at for it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('topic_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('topic_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_read_at')->nullable();

            $table->unique(['user_id', 'topic_id']);
            $table->index(['user_id', 'last_read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topic_reads');
    }
};
