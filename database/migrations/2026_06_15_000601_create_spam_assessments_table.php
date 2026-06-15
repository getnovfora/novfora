<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spam-intelligence assessments (Phase 4 · M6.1). One row per HELD post recording WHY it was held — the
 * SpamScorer's score + per-signal breakdown + the moderation reasons — so the M6.2 review surface can show
 * staff the evidence behind each queue item. Append-only (created_at, no updated_at). post_id is nullable +
 * null-on-delete so a record survives a post being rejected/purged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spam_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('score')->default(0);
            $table->json('signals')->nullable();  // {signal: points}
            $table->json('reasons')->nullable();  // list<string> of moderation reasons
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index('score');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spam_assessments');
    }
};
