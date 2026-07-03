<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| U4 (NOV-102) — per-user announcement dismissals. One row = "this user has dismissed this announcement";
| the banner query fences on its absence (whereNotExists). unique(user_id, topic_id) makes dismiss idempotent
| and both FKs cascade so the row cannot outlive its user or topic. Reversible.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('announcement_dismissals')) {
            Schema::create('announcement_dismissals', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('topic_id')->constrained()->cascadeOnDelete();
                $table->timestamp('dismissed_at')->useCurrent();

                $table->unique(['user_id', 'topic_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_dismissals');
    }
};
